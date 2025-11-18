# HTTP/3 Virtual FD System - 技术设计文档

## 📋 概述

本文档描述HTTP/3/QUIC连接与Swoole Server完整集成所需的Virtual FD系统设计方案。

**当前状态**: Phase 3完成，基础设施就绪
**目标**: 实现QUIC连接的完整Swoole Connection集成
**挑战**: QUIC连接复用单个UDP socket，与Swoole的一fd一连接模型不兼容

---

## 🎯 核心挑战

### Challenge 1: FD复用问题

**Swoole的连接模型**:
```cpp
Connection *Server::add_connection(const ListenPort *ls, Socket *_socket, int server_fd) {
    int fd = _socket->fd;  // 使用socket的实际fd
    Connection *connection = &(connection_list[fd]);  // 直接索引
    // ...
}
```

**QUIC的连接模型**:
```
UDP Socket (fd=10)
  ├─ QUIC Connection 1 (SSL* object)
  ├─ QUIC Connection 2 (SSL* object)
  ├─ QUIC Connection 3 (SSL* object)
  └─ QUIC Connection N (SSL* object)
```

所有QUIC连接共享同一个UDP socket的fd，无法直接映射到`connection_list[fd]`。

### Challenge 2: connection_list数组限制

```cpp
Connection *connection_list;  // 大小为max_connection的数组
Connection *get_connection(const int fd) const {
    if (static_cast<uint32_t>(fd) > max_connection) {
        return nullptr;
    }
    return &connection_list[fd];
}
```

- connection_list是一个连续数组，基于fd直接索引
- 如果fd大于max_connection，返回nullptr
- 无法为QUIC连接分配任意高位的虚拟fd

### Challenge 3: Socket对象需求

```cpp
struct Connection {
    network::Socket *socket;  // 期望一个真实的Socket对象
    // ...
};
```

Swoole的Connection对象需要一个Socket*，而Socket需要一个真实的文件描述符进行I/O操作。

---

## 💡 设计方案

### 方案1: Socketpair Virtual FD (推荐)

为每个QUIC连接创建一个socketpair，使用其中一个fd作为虚拟fd。

**优点**:
- 提供真实的fd，可以注册到reactor
- 可以进行select/epoll监听
- 与Swoole的Connection模型完全兼容

**缺点**:
- 消耗系统fd资源（每个QUIC连接2个fd）
- 需要处理socketpair的数据转发

**实现示例**:
```cpp
Connection* Listener::create_swoole_connection(quic::Connection *qc) {
    if (!swoole_server) return nullptr;

    // Create socketpair for virtual fd
    int fds[2];
    if (socketpair(AF_UNIX, SOCK_STREAM, 0, fds) < 0) {
        swoole_sys_warning("socketpair() failed");
        return nullptr;
    }

    // Use fds[0] as the virtual fd for this QUIC connection
    int virtual_fd = fds[0];
    swoole_set_nonblock(virtual_fd);
    swoole_set_nonblock(fds[1]);

    // Create Socket object
    Socket *sock = new Socket(virtual_fd, SW_FD_SESSION, SW_SOCK_STREAM);
    sock->socket_type = SW_SOCK_UDP;  // Mark as UDP-based
    sock->object = nullptr;  // Will be set by add_connection

    // Store the pair fd for cleanup
    qc->virtual_fd_pair[0] = fds[0];
    qc->virtual_fd_pair[1] = fds[1];

    // Add connection to Swoole Server
    Connection *conn = swoole_server->add_connection(port, sock, udp_fd);
    if (conn) {
        // Bind QUIC connection to Swoole connection
        qc->bind_swoole_connection(conn, conn->session_id, udp_fd, reactor);

        // Store virtual fd for reverse lookup
        virtual_fd_map[virtual_fd] = qc;
    }

    return conn;
}
```

### 方案2: 共享Connection + Object Mapping

所有QUIC连接共享UDP socket的Connection，使用object指针区分。

**优点**:
- 不需要额外的fd
- 实现简单

**缺点**:
- connection_list[udp_fd]只能存储一个Connection
- 无法为每个QUIC连接分配独立的SessionId
- 事件通知难以区分不同的QUIC连接

**不推荐使用**。

### 方案3: SessionId-Only Approach (当前阶段)

不创建完整的Swoole Connection，只分配SessionId并手动触发事件。

**优点**:
- 实现简单，不修改Swoole核心
- 可以实现基本的事件通知

**缺点**:
- 无法使用Swoole的Connection管理功能
- 无法利用Swoole的连接状态追踪
- 无法使用Worker进程的正常请求处理流程

**当前阶段采用此方案作为过渡**。

---

## 🏗️ 推荐实施路径

### Phase 4: 架构设计与文档 (当前)

✅ **目标**: 完成技术设计，识别关键挑战
✅ **产出**: 技术设计文档（本文档）
✅ **状态**: 完成

### Phase 5: Socketpair Virtual FD实现

🚧 **目标**: 实现基于socketpair的Virtual FD系统

**关键任务**:
1. 在QUIC Connection添加virtual_fd_pair字段
2. 实现create_swoole_connection()方法
3. 实现虚拟fd到QUIC连接的映射表
4. 更新process_packet()调用create_swoole_connection()
5. 实现onConnect/onClose事件通知
6. 处理socketpair的清理逻辑

**预计代码量**: ~200-300行

### Phase 6: 请求处理集成

⏳ **目标**: 实现HTTP/3请求到Worker的完整流程

**关键任务**:
1. 实现HTTP/3 Request对象到Swoole Request的转换
2. 使用factory->dispatch()分发请求到Worker
3. 实现Response回写到QUIC连接
4. 处理流控和背压

**预计代码量**: ~400-500行

### Phase 7: 性能优化

⏳ **目标**: 优化Virtual FD系统性能

**关键任务**:
1. 减少socketpair的开销（zero-copy技术）
2. 优化虚拟fd映射查找
3. 批量处理QUIC连接事件
4. 内存池优化

---

## 📊 技术指标

### Virtual FD资源消耗

| 指标 | Socketpair方案 | 共享Connection方案 |
|------|---------------|-------------------|
| 额外FD | 2 per connection | 0 |
| 内存开销 | ~4KB per connection | ~100 bytes per connection |
| CPU开销 | 低（只在连接建立/关闭） | 极低 |
| 可扩展性 | 好（受系统fd限制） | 优秀 |
| Swoole兼容性 | 完美 | 有限 |

### 性能影响评估

**Socketpair方案性能影响**:
- 连接建立: +0.1ms（socketpair开销）
- 数据传输: 0ms（不通过socketpair传输数据）
- 连接关闭: +0.05ms（close()开销）
- 内存: +4KB per connection

**结论**: 对于HTTP/3的高性能场景（1000+ connections），socketpair的开销可以接受。

---

## 🔧 关键代码接口

### 新增到quic::Listener

```cpp
class Listener {
private:
    // Virtual FD management
    std::unordered_map<int, quic::Connection*> virtual_fd_map;
    std::mutex virtual_fd_mutex;

    int create_virtual_fd_pair(int fds[2]);
    void cleanup_virtual_fd(int virtual_fd);

public:
    // Create Swoole Connection for QUIC connection
    swoole::Connection* create_swoole_connection(quic::Connection *qc);

    // Notify Swoole of connection events
    bool notify_connect(quic::Connection *qc);
    bool notify_close(quic::Connection *qc, uint64_t error_code);
};
```

### 新增到quic::Connection

```cpp
struct Connection {
    // Virtual FD pair (for Swoole integration)
    int virtual_fd_pair[2];  // [0] = virtual fd, [1] = internal fd
    bool has_virtual_fd;

    // Helper methods
    int get_virtual_fd() const { return has_virtual_fd ? virtual_fd_pair[0] : -1; }
    void cleanup_virtual_fd();
};
```

---

## 📝 实现检查清单

### Phase 5: Virtual FD Implementation

- [ ] Add virtual_fd_pair to quic::Connection
- [ ] Implement create_virtual_fd_pair()
- [ ] Implement create_swoole_connection()
- [ ] Add virtual_fd_map to Listener
- [ ] Update process_packet() to create Swoole Connection
- [ ] Implement notify_connect()
- [ ] Implement notify_close()
- [ ] Handle virtual fd cleanup in ~Connection()
- [ ] Add error handling for fd exhaustion
- [ ] Write unit tests

### Phase 6: Request Processing

- [ ] Convert HTTP/3 Stream headers to Swoole Request
- [ ] Implement factory->dispatch() call
- [ ] Handle Worker process request
- [ ] Implement Response write back
- [ ] Handle flow control
- [ ] Implement error handling
- [ ] Write integration tests

---

## 🎓 技术参考

### DTLS Implementation (类似场景)

Swoole的DTLS实现面临类似的UDP复用问题，采用的方案：

```cpp
// src/server/master.cc
dtls::Session *Server::accept_dtls_connection(const ListenPort *port, const Address *sa) {
    // 为每个DTLS session创建一个新的UDP socket
    Socket *sock = make_socket(port->type, SW_FD_SESSION, SW_SOCK_CLOEXEC | SW_SOCK_NONBLOCK);

    // 绑定并连接到对端地址
    sock->bind(port->host, port->port);
    sock->connect(sa);

    // 添加到connection_list
    conn = add_connection(port, sock, port->socket->fd);
}
```

**关键差异**: DTLS为每个连接创建新的UDP socket，而QUIC必须复用同一个UDP socket。

### HTTP/2 Multiplexing (借鉴经验)

HTTP/2也支持多路复用，但它在TCP层面：
- 一个TCP连接 = 一个fd = 一个Swoole Connection
- 多个HTTP/2 Stream在应用层复用
- 不存在fd映射问题

QUIC的挑战更大，因为复用发生在传输层（UDP）。

---

## 🚨 风险与限制

### 系统FD限制

**问题**: 每个QUIC连接消耗2个fd（socketpair）

**解决方案**:
1. 提高系统fd限制：`ulimit -n 100000`
2. 监控fd使用情况
3. 实现连接限制和排队机制

### 内存消耗

**问题**: 额外的Socket对象和mapping table

**解决方案**:
1. 使用内存池
2. 及时清理closed connections
3. 设置合理的max_connections限制

### 性能影响

**问题**: socketpair创建/销毁开销

**解决方案**:
1. 使用fd池预创建socketpair
2. 延迟销毁，复用fd
3. 批量处理连接事件

---

## 📈 成功标准

Phase 5 (Virtual FD)完成标准：
- ✅ 每个QUIC连接有独立的Swoole Connection
- ✅ SessionId正确分配
- ✅ onConnect事件正确触发
- ✅ onClose事件正确触发
- ✅ 支持至少1000并发连接
- ✅ 无fd泄漏
- ✅ 无内存泄漏

Phase 6 (Request Processing)完成标准：
- ✅ HTTP/3请求到达Worker进程
- ✅ onRequest回调正确触发
- ✅ Response正确回写到客户端
- ✅ 支持大文件传输
- ✅ 流控工作正常

---

## 🎯 结论

**推荐路径**: Socketpair Virtual FD方案

**理由**:
1. 与Swoole架构完美兼容
2. 实现复杂度可控
3. 性能影响可接受
4. 功能完整性最好

**时间估计**:
- Phase 5 (Virtual FD): 3-5天
- Phase 6 (Request Processing): 5-7天
- Phase 7 (Optimization): 3-5天

**总计**: 11-17天完成完整集成

---

**文档版本**: 1.0
**作者**: Claude (Anthropic)
**日期**: 2025-11-18
**状态**: Phase 4 - 架构设计完成
