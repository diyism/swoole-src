# HTTP/3 Request Processing Integration - Phase 6 设计文档

## 📋 概述

**当前状态**: Phase 5完成 - Virtual FD系统实现完成
**目标**: 实现HTTP/3请求到Worker进程的完整处理流程
**范围**: HTTP/3请求接收、转换、分发到Worker、响应回写

---

## 🎯 Phase 6 目标

### 主要目标
1. HTTP/3 Stream请求数据到Swoole可处理格式的转换
2. 请求分发到Worker进程（通过Swoole的消息队列）
3. Worker进程的onRequest回调触发
4. Response收集和写回到HTTP/3 Stream

### 非目标（推迟到Phase 7）
- 性能优化（zero-copy, 批量处理）
- 高级流控策略
- 内存池优化

---

## 🏗️ 当前架构分析

### Phase 5已完成
```
QUIC Connection Accepted
    ↓
create_swoole_connection()
    ├─ socketpair创建virtual fd
    ├─ 创建Swoole Connection
    └─ 绑定QUIC ↔ Swoole Connection
    ↓
notify_connect()
    ├─ 触发SW_SERVER_EVENT_CONNECT
    └─ PHP onConnect回调（如果设置）
```

### HTTP/3当前流程
```
HTTP/3 HEADERS frame接收
    ↓
nghttp3回调: recv_header()
    ↓
Stream::recv_headers()
    ├─ 解析pseudo-headers (:method, :path, etc.)
    ├─ 存储headers到Stream::headers
    └─ 设置headers_complete = true
    ↓
Connection::on_recv_header回调
    ↓
Server::on_request回调（应用层设置）
    └─ 当前为nullptr，需要集成
```

### Swoole Worker模式
```
Reactor线程（接收请求）
    ↓
factory->dispatch(RecvData)
    ↓
消息队列 (IPC)
    ↓
Worker进程
    ↓
onReceive/onRequest回调
    ↓
用户PHP代码
    ↓
send() Response
    ↓
消息队列返回
    ↓
Reactor线程写回客户端
```

---

## 💡 设计方案

### 方案选择

**方案A: 完整Worker集成** (推荐)
- 将HTTP/3请求转换为RecvData格式
- 通过factory->dispatch()发送到Worker
- Worker触发onRequest回调
- 完整利用Swoole的Worker机制

**优点**:
- 完全兼容Swoole Server模式
- 支持多Worker负载均衡
- 支持Task Worker
- 与HTTP/1.1, HTTP/2一致的架构

**缺点**:
- 需要理解RecvData格式
- 需要实现Response回写机制

**方案B: 直接回调** (简化方案)
- 在Reactor线程直接调用PHP回调
- 不经过Worker进程

**优点**:
- 实现简单
- 延迟低

**缺点**:
- 不支持Worker模式
- 不支持多进程
- 与Swoole架构不一致

**结论**: 采用方案A - 完整Worker集成

---

## 🔧 实施计划

### Step 1: 数据结构设计

#### 1.1 HTTP/3请求上下文
```cpp
// 在http3::Stream中已有的字段（无需修改）:
struct Stream {
    std::vector<HeaderField> headers;  // HTTP headers
    String *body;                       // Request body
    std::string method;                 // :method
    std::string path;                   // :path
    std::string scheme;                 // :scheme
    std::string authority;              // :authority
    uchar headers_complete : 1;
    uchar body_complete : 1;
};
```

#### 1.2 请求数据包装
需要将HTTP/3 Stream数据包装为Swoole可识别的格式：

**选项1**: 使用Protocol::RecvData
```cpp
struct RecvData {
    SessionId session_id;
    swoole::DataHead info;
    const char *data;
};
```

**选项2**: 使用自定义事件类型
```cpp
// SW_SERVER_EVENT_HTTP3_REQUEST (新增事件类型)
// 数据包含: session_id + 序列化的HTTP/3请求
```

### Step 2: 请求分发流程

#### 2.1 在Connection::on_recv_header中集成
```cpp
conn->on_recv_header = [](Connection *c, Stream *s) {
    Server *server = (Server *) c->user_data;

    // Phase 6: 分发请求到Worker
    if (c->quic_conn && c->quic_conn->swoole_conn) {
        swoole::Connection *swoole_conn = c->quic_conn->swoole_conn;
        swoole::Server *swoole_server = server->swoole_server;

        if (swoole_server) {
            // 创建请求数据包
            RecvData rdata = create_request_data(s, swoole_conn->session_id);

            // 分发到Worker
            swoole_server->factory->dispatch(&rdata);
        }
    }

    // 保留原有的on_request回调（用于非Swoole集成模式）
    if (server && server->on_request) {
        server->on_request(c, s);
    }
};
```

#### 2.2 实现create_request_data()
```cpp
swoole::RecvData http3::create_request_data(Stream *s, swoole::SessionId sid) {
    RecvData rdata = {};
    rdata.session_id = sid;
    rdata.info.type = SW_SERVER_EVENT_RECV_DATA;  // 或自定义事件类型
    rdata.info.fd = s->conn->quic_conn->get_virtual_fd();
    rdata.info.reactor_id = ...; // 从reactor获取

    // 序列化HTTP/3请求数据
    // 方案: 使用JSON或自定义二进制格式
    String *request_buf = serialize_http3_request(s);
    rdata.data = request_buf->str;
    rdata.info.len = request_buf->length;

    return rdata;
}
```

### Step 3: Worker端处理

#### 3.1 扩展Server::onReceive处理
在Worker进程接收到数据后：

```cpp
// src/server/worker.cc 或相关文件
int Server::onReceive(Server *serv, RecvData *rdata) {
    Connection *conn = serv->get_connection(rdata->info.fd);

    // 检查是否为HTTP/3连接
    if (conn && is_http3_connection(conn)) {
        // 解析HTTP/3请求数据
        http3_request *req = parse_http3_request(rdata->data, rdata->info.len);

        // 触发onRequest回调
        call_php_on_request(conn, req);

        return SW_OK;
    }

    // 原有处理逻辑...
}
```

#### 3.2 PHP扩展层集成
在`ext-src/swoole_http_server.cc`中：

```cpp
// 创建Request和Response对象
zval *zrequest, *zresponse;
create_http3_request_object(http3_stream, zrequest);
create_http3_response_object(http3_stream, zresponse);

// 调用用户的onRequest回调
zval args[2] = {*zrequest, *zresponse};
zend::function::call(on_request_callback, 2, args, nullptr, true);
```

### Step 4: Response处理

#### 4.1 Response收集
当用户调用`$response->end($data)`时：

```cpp
// PHP扩展层
PHP_METHOD(swoole_http_response, end) {
    // ...
    if (is_http3_response(response)) {
        // 将Response数据发送回Reactor线程
        send_http3_response_to_reactor(response);
    }
}
```

#### 4.2 Response写回
在Reactor线程接收到Worker的Response后：

```cpp
int Server::onPipeReceive(Reactor *reactor, Event *event) {
    // 读取Worker发送的数据
    ResponseData *resp = read_worker_response(event);

    if (resp->type == SW_SERVER_EVENT_HTTP3_RESPONSE) {
        // 获取对应的HTTP/3 Stream
        http3::Stream *stream = get_http3_stream(resp->session_id, resp->stream_id);

        // 写回HTTP/3响应
        stream->send_response(resp->status_code, resp->headers, resp->body, resp->body_len);
    }
}
```

---

## 📊 数据流图

### 完整请求响应流程
```
1. 客户端发送HTTP/3请求
   ↓
2. QUIC层接收HEADERS frame
   ↓
3. nghttp3解析，调用recv_header回调
   ↓
4. http3::Stream::recv_headers()
   ├─ 解析pseudo-headers
   ├─ 存储headers
   └─ 设置headers_complete = true
   ↓
5. http3::Connection::on_recv_header
   ├─ 序列化HTTP/3请求
   ├─ 创建RecvData
   └─ factory->dispatch(RecvData)
   ↓
6. 消息队列 → Worker进程
   ↓
7. Worker::onReceive()
   ├─ 解析HTTP/3请求数据
   ├─ 创建PHP Request/Response对象
   └─ 调用onRequest PHP回调
   ↓
8. 用户PHP代码
   ↓
9. $response->end($data)
   ├─ 序列化Response
   └─ 发送到Reactor线程
   ↓
10. Reactor::onPipeReceive()
    ├─ 读取Response数据
    ├─ 查找对应的HTTP/3 Stream
    └─ stream->send_response()
    ↓
11. nghttp3编码HEADERS + DATA frames
    ↓
12. QUIC层发送数据包
    ↓
13. 客户端接收响应
```

---

## 🔍 关键技术问题

### Q1: 如何序列化HTTP/3请求？

**选项A: JSON格式**
```json
{
  "method": "GET",
  "path": "/api/users",
  "headers": {"user-agent": "curl", ...},
  "body": "..."
}
```
优点: 易于调试
缺点: 性能开销，序列化/反序列化

**选项B: 二进制格式**
```
[magic: 4bytes][version: 2bytes][method_len: 2bytes][method][path_len: 2bytes][path]
[header_count: 2bytes]
[header1_name_len: 2bytes][header1_name][header1_value_len: 2bytes][header1_value]
...
[body_len: 4bytes][body]
```
优点: 高性能
缺点: 实现复杂

**推荐**: 先使用JSON（Phase 6），优化时改为二进制（Phase 7）

### Q2: Stream ID如何映射？

HTTP/3 Stream ID需要在Worker进程和Reactor线程之间传递：

```cpp
struct StreamContext {
    swoole::SessionId session_id;  // Swoole Connection ID
    int64_t stream_id;              // HTTP/3 Stream ID
    http3::Stream *stream_ptr;      // 仅在Reactor线程有效
};

// 全局映射表（Reactor线程）
std::unordered_map<std::string, StreamContext> stream_contexts;
// key = "{session_id}:{stream_id}"
```

### Q3: 如何处理Body数据？

HTTP/3 Body可能分多个DATA frames接收：

```cpp
conn->on_recv_data = [](Connection *c, Stream *s, const uint8_t *data, size_t len) {
    // 追加到Stream::body
    if (!s->body) {
        s->body = new String(len);
    }
    s->body->append((const char*)data, len);

    // 如果body_complete，立即分发请求
    if (s->body_complete) {
        dispatch_http3_request(s);
    }
};
```

---

## 📝 实施检查清单

### Phase 6.1: 基础集成
- [ ] 在http3::Connection::on_recv_header中添加Worker分发逻辑
- [ ] 实现HTTP/3请求序列化（JSON格式）
- [ ] 创建RecvData并调用factory->dispatch()
- [ ] 在Worker端添加HTTP/3请求识别逻辑
- [ ] 测试请求能否到达Worker

### Phase 6.2: PHP集成
- [ ] 扩展swoole_http_server.cc支持HTTP/3
- [ ] 创建Request对象（填充headers, body, method, path）
- [ ] 创建Response对象
- [ ] 调用用户的onRequest回调
- [ ] 测试PHP回调能否触发

### Phase 6.3: Response回写
- [ ] 实现$response->end()的HTTP/3支持
- [ ] 序列化Response数据
- [ ] 通过Pipe发送回Reactor
- [ ] Reactor端接收并写回Stream
- [ ] 测试完整请求响应流程

### Phase 6.4: Body处理
- [ ] 实现on_recv_data回调集成
- [ ] 处理分片Body数据
- [ ] 支持大Body传输
- [ ] 测试POST请求

---

## 🎯 成功标准

Phase 6完成标准：
- ✅ HTTP/3 GET请求能到达Worker进程
- ✅ onRequest回调正确触发
- ✅ Request对象包含正确的headers和path
- ✅ Response能正确回写到客户端
- ✅ 支持POST请求和body数据
- ✅ 支持多个并发请求
- ✅ 无内存泄漏

---

## ⏱️ 时间估计

| 任务 | 估计时间 |
|------|---------|
| 请求序列化和分发 | 1-2天 |
| Worker端集成 | 2-3天 |
| PHP扩展集成 | 2-3天 |
| Response处理 | 1-2天 |
| 测试和调试 | 1-2天 |
| **总计** | **7-12天** |

---

## 🚨 风险和挑战

### 技术风险
1. **RecvData格式理解** - 需要深入理解Swoole的IPC机制
2. **Stream生命周期** - Stream可能在Worker处理时已关闭
3. **内存管理** - 请求数据的序列化和反序列化需要正确管理内存

### 缓解措施
1. 参考HTTP/2的实现
2. 实现Stream引用计数
3. 使用String对象和智能指针

---

## 📚 参考资料

- Swoole Server Worker模式文档
- HTTP/2 Server实现 (src/protocol/http2.cc)
- RecvData定义 (include/swoole_server.h)
- factory->dispatch实现 (src/server/*.cc)

---

**文档版本**: 1.0
**作者**: Claude (Anthropic)
**日期**: 2025-11-18
**状态**: Phase 6 - 设计阶段
