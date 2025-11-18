# Phase 6 Implementation Roadmap - 简化实施路径

## 📋 背景

Phase 6是HTTP/3项目中最复杂的阶段，涉及请求处理、Worker集成和PHP扩展层。

**原计划**: 7-12天的完整实施
**简化方案**: 分解为4个可独立测试的子阶段

---

## 🎯 分阶段实施策略

### Phase 6.1: 最小化请求标记 (1-2天)

**目标**: 让HTTP/3请求能够"触达"Worker，即使只是简单的通知

**实施内容**:
1. 在`http3::Connection::on_recv_header`中添加日志
2. 通过Swoole Connection的session_id识别HTTP/3连接
3. 使用`Server::notify()`发送简单事件
4. 验证Worker能接收到事件

**代码示例**:
```cpp
conn->on_recv_header = [](Connection *c, Stream *s) {
    if (c->quic_conn && c->quic_conn->swoole_conn) {
        swoole::Connection *swoole_conn = c->quic_conn->swoole_conn;
        Server *swoole_server = ...; // 获取Server实例

        // 最简单的通知：触发一个自定义事件
        swoole_trace_log(SW_TRACE_HTTP3,
            "HTTP/3 request received: method=%s, path=%s, session_id=%ld",
            s->method.c_str(), s->path.c_str(), swoole_conn->session_id);

        // TODO Phase 6.2: 实际的请求分发
    }
};
```

**验证**:
- ✅ 日志能正确输出HTTP/3请求信息
- ✅ session_id与虚拟fd匹配

---

### Phase 6.2: 请求数据传递 (2-3天)

**目标**: 将HTTP/3请求数据传递到Worker（不涉及PHP）

**实施内容**:
1. 实现HTTP/3请求序列化（JSON格式）
2. 使用`factory->dispatch()`发送数据
3. Worker端添加HTTP/3数据识别逻辑
4. 打印日志验证数据完整性

**请求序列化**:
```cpp
std::string serialize_http3_request(http3::Stream *s) {
    json req = {
        {"method", s->method},
        {"path", s->path},
        {"scheme", s->scheme},
        {"authority", s->authority},
        {"headers", json::object()},
        {"body", s->body ? s->body->to_std_string() : ""}
    };

    for (auto &h : s->headers) {
        req["headers"][h.name] = h.value;
    }

    return req.dump();
}
```

**数据分发**:
```cpp
// 创建RecvData
RecvData rdata = {};
rdata.session_id = swoole_conn->session_id;
rdata.info.type = SW_SERVER_EVENT_RECV_DATA;
rdata.info.fd = swoole_conn->fd;
rdata.info.len = request_json.length();

String *buffer = new String(request_json.c_str(), request_json.length());
rdata.data = buffer->str;

// 分发到Worker
swoole_server->factory->dispatch(&rdata);
```

**Worker端处理**:
```cpp
// src/server/worker.cc 或相关文件
// 在onReceive中添加
if (rdata->info.type == SW_SERVER_EVENT_RECV_DATA) {
    // 尝试解析为JSON
    std::string data_str(rdata->data, rdata->info.len);
    if (data_str[0] == '{') {  // 简单判断是否为JSON
        swoole_trace_log(SW_TRACE_HTTP3,
            "Worker received HTTP/3 request: %s", data_str.c_str());
        // TODO Phase 6.3: 创建PHP对象
    }
}
```

**验证**:
- ✅ Worker日志显示完整的HTTP/3请求数据
- ✅ 多个请求能正确分发
- ✅ headers和body数据完整

---

### Phase 6.3: PHP扩展集成 (2-3天)

**目标**: 创建PHP Request/Response对象，触发onRequest回调

**实施内容**:
1. 扩展`swoole_http_server.cc`支持HTTP/3
2. 创建Request对象并填充数据
3. 创建Response对象
4. 调用用户的onRequest回调
5. 暂时返回固定响应（不写回客户端）

**PHP对象创建**:
```cpp
// 在Worker进程
static void create_http3_request_object(json &req_data, zval *zrequest) {
    // 创建Request对象
    object_init_ex(zrequest, swoole_http_request_ce);

    // 设置server信息
    zval *zserver = sw_zend_read_property(Z_OBJCE_P(zrequest), zrequest, ZEND_STRL("server"), 0);
    add_assoc_string(zserver, "request_method", req_data["method"].get<std::string>().c_str());
    add_assoc_string(zserver, "request_uri", req_data["path"].get<std::string>().c_str());
    add_assoc_string(zserver, "server_protocol", "HTTP/3");

    // 设置header
    zval *zheader = sw_zend_read_property(Z_OBJCE_P(zrequest), zrequest, ZEND_STRL("header"), 0);
    for (auto &[key, val] : req_data["headers"].items()) {
        add_assoc_string(zheader, key.c_str(), val.get<std::string>().c_str());
    }

    // 设置body（如果有）
    if (!req_data["body"].empty()) {
        sw_zend_update_property_stringl(Z_OBJCE_P(zrequest), zrequest,
            ZEND_STRL("rawContent"), req_data["body"].get<std::string>().c_str(),
            req_data["body"].get<std::string>().length());
    }
}
```

**回调触发**:
```cpp
// 调用用户的onRequest
zval args[2];
create_http3_request_object(req_data, &args[0]);
create_http3_response_object(session_id, stream_id, &args[1]);

zend::function::call(on_request_callback, 2, args, nullptr, serv->is_enable_coroutine());
```

**验证**:
- ✅ PHP onRequest回调能触发
- ✅ Request对象包含正确的数据
- ✅ 用户可以读取headers, method, path
- ✅ 可以读取body数据

---

### Phase 6.4: Response回写 (1-2天)

**目标**: 将Worker的Response写回到客户端

**实施内容**:
1. 实现`$response->end()`的HTTP/3支持
2. 序列化Response数据
3. 通过Pipe发送回Reactor线程
4. Reactor接收并写入HTTP/3 Stream

**Response对象**:
```cpp
// PHP扩展层
PHP_METHOD(swoole_http_response, end) {
    // ... 参数解析

    if (is_http3_response(response)) {
        // 序列化Response
        json resp = {
            {"session_id", response->session_id},
            {"stream_id", response->stream_id},
            {"status_code", response->status_code},
            {"headers", response->headers},  // map<string, string>
            {"body", response->body}
        };

        // 发送到Reactor
        send_to_reactor_thread(worker_id, resp.dump());
    }
}
```

**Reactor端处理**:
```cpp
// src/server/reactor_thread.cc
int ReactorThread::onPipeReceive(Reactor *reactor, Event *event) {
    // 读取数据
    EventData event_data;
    read(event->fd, &event_data, sizeof(event_data));

    if (event_data.info.type == SW_SERVER_EVENT_HTTP3_RESPONSE) {
        // 解析Response JSON
        json resp = json::parse(event_data.data);

        // 查找对应的HTTP/3 Stream
        SessionId sid = resp["session_id"];
        int64_t stream_id = resp["stream_id"];

        http3::Stream *stream = find_http3_stream(sid, stream_id);
        if (stream) {
            // 构建headers
            std::vector<http3::HeaderField> headers;
            for (auto &[k, v] : resp["headers"].items()) {
                headers.push_back({k, v});
            }

            // 发送响应
            stream->send_response(
                resp["status_code"],
                headers,
                (const uint8_t*)resp["body"].get<std::string>().c_str(),
                resp["body"].get<std::string>().length()
            );
        }
    }
}
```

**验证**:
- ✅ 客户端能收到响应
- ✅ 状态码正确
- ✅ Headers正确
- ✅ Body数据完整
- ✅ 多个请求/响应正常工作

---

## 📊 总体时间线

| 子阶段 | 时间 | 累计 | 可测试性 |
|--------|------|------|----------|
| 6.1 请求标记 | 1-2天 | 1-2天 | ✅ 日志验证 |
| 6.2 数据传递 | 2-3天 | 3-5天 | ✅ Worker日志 |
| 6.3 PHP集成 | 2-3天 | 5-8天 | ✅ PHP回调 |
| 6.4 Response | 1-2天 | 6-10天 | ✅ 端到端 |

**优点**: 每个子阶段都可以独立测试和提交

---

## 🎯 关键决策点

### 决策1: 序列化格式

**临时方案**: JSON
- 易于调试
- 快速实现
- 性能可接受（Phase 6重点是功能）

**未来优化**: 二进制格式（Phase 7）

### 决策2: Stream查找机制

需要在Reactor线程维护Stream映射：

```cpp
// 全局或Listener级别
std::unordered_map<std::string, http3::Stream*> active_streams;

// key = "session_id:stream_id"
std::string make_stream_key(SessionId sid, int64_t stream_id) {
    return std::to_string(sid) + ":" + std::to_string(stream_id);
}
```

### 决策3: 内存管理

**Request数据**: 使用String对象，Worker处理后释放
**Response数据**: 使用临时buffer，写入Stream后释放

---

## ✅ 每个子阶段的提交策略

### Phase 6.1 提交
```
feat(http3): Add request event marking (Phase 6.1)

- Add logging in on_recv_header callback
- Track HTTP/3 requests by session_id
- Verify connection mapping works

Progress: Phase 6.1/4 complete
```

### Phase 6.2 提交
```
feat(http3): Implement request dispatch to Worker (Phase 6.2)

- Serialize HTTP/3 requests to JSON
- Use factory->dispatch() to send to Worker
- Add Worker-side request recognition
- Verify data integrity

Progress: Phase 6.2/4 complete
```

### Phase 6.3 提交
```
feat(http3): Add PHP extension integration (Phase 6.3)

- Create Request/Response PHP objects
- Populate Request with HTTP/3 data
- Trigger onRequest callback
- Verify PHP user code can access request data

Progress: Phase 6.3/4 complete
```

### Phase 6.4 提交
```
feat(http3): Implement response write-back (Phase 6.4)

- Support $response->end() for HTTP/3
- Send response data to Reactor thread
- Write response to HTTP/3 Stream
- Complete end-to-end request/response flow

Progress: Phase 6 完成 - 请求处理集成完整实现
```

---

## 🚀 下一步行动

1. **立即开始**: Phase 6.1 - 请求标记
   - 修改`src/protocol/http3.cc`
   - 添加日志跟踪
   - 验证连接映射

2. **预计2周内完成**: 整个Phase 6
   - 每个子阶段独立测试
   - 每个子阶段独立提交
   - 渐进式集成，风险可控

3. **Phase 7准备**: 性能优化
   - 二进制序列化
   - Zero-copy优化
   - 批量处理

---

## 📚 参考实现

建议参考的文件：
- `src/server/worker.cc` - Worker处理逻辑
- `src/server/reactor_thread.cc` - Reactor线程和Pipe通信
- `ext-src/swoole_http_server.cc` - PHP扩展HTTP Server
- `ext-src/swoole_http_request.cc` - Request对象创建
- `ext-src/swoole_http_response.cc` - Response对象和end()方法

---

**文档版本**: 1.0
**日期**: 2025-11-18
**状态**: Phase 6 - 实施路线图
**建议**: 分4个子阶段渐进实施，每个子阶段可独立测试和提交
