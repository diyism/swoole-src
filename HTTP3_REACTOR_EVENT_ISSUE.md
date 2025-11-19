# HTTP/3 Reactor Event Issue - 2025-11-19

## Problem Statement

HTTP/3 QUIC listener is successfully registered to Swoole reactor, but reactor read callback is never triggered when UDP packets arrive on port 9501.

## Evidence

### ✅ Successful Components

1. **QUIC Listener Initialization**
   ```
   [DEBUG] UDP socket bound successfully (fd=13)
   [DEBUG] SSL_new_listener succeeded: 0x7edee4030260
   [DEBUG] SSL_set_fd succeeded
   [DEBUG] SSL_listen succeeded! QUIC listener is ready
   ```

2. **Reactor Registration**
   ```
   [DEBUG] reactor->add() returned: 0  ← Success!
   [DEBUG] QUIC Listener successfully registered to Reactor, fd=13
   ```

3. **Port Binding Verified**
   ```
   php     15117 root   13u  IPv4    738       UDP *:9501
   ```

### ❌ Missing Component

**Reactor Read Callback Never Triggered**
- No `[DEBUG] Reactor read event` messages in logs
- No `accept_connection` calls logged
- No `process_packet` calls logged

### Tests Performed

1. **HTTP/3 curl request**: Connection refused
2. **UDP test packet** (`echo "test" | nc -u localhost 9501`): No reactor event
3. **QUIC handshake from curl**: No reactor event

## Root Cause Analysis

### Hypothesis 1: Reactor/Worker Process Mismatch

**Observation:** All HTTP/3 registration logs show process `#15947.0`

**Possible Issue:**
- HTTP/3 listener registered in master/reactor thread init
- But events may need to be handled in worker processes
- Swoole PROCESS mode: reactor runs in workers, not master

**Evidence:**
```
[2025-11-19 02:43:09 #15947.0] ReactorThread::init(): [DEBUG] Checking HTTP/3 registration: reactor_id=0
[2025-11-19 02:43:09 #15947.0] ReactorThread::init(): [DEBUG] Checking HTTP/3 registration: reactor_id=1
[2025-11-19 02:43:09 #15947.0] ReactorThread::init(): [DEBUG] Registering HTTP/3 to reactor...
```

Two reactor_id values (0 and 1) but only reactor_id=0 registers HTTP/3.

### Hypothesis 2: fd_type Handler Mismatch

**Code Path:**
```cpp
swoole_socket->fd_type = SW_FD_DGRAM_SERVER;  // Set in register_to_reactor()
_reactor->set_handler(SW_FD_DGRAM_SERVER, SW_EVENT_READ, on_reactor_read);  // Set handler
_reactor->add(swoole_socket, SW_EVENT_READ);  // Returns 0 (success)
```

**Possible Issue:**
- Handler set globally for SW_FD_DGRAM_SERVER type
- But reactor->add() may not associate the socket with the handler correctly
- Or epoll may not be monitoring fd=13 for EPOLLIN events

### Hypothesis 3: Socket Ownership in Multi-Process

**Observation:** UDP socket created in one process, reactor in another

**Possible Issue:**
- UDP socket fd=13 created during bind() in process A
- Reactor registered in process B
- After fork(), file descriptor may not be shared correctly
- Or epoll instance doesn't inherit socket monitoring across fork()

## Next Steps to Investigate

### 1. Verify Reactor Process
```bash
# Add debug log in on_reactor_read to print PID
swoole_warning("[DEBUG] on_reactor_read called in PID=%d", getpid());
```

### 2. Check epoll Monitoring
```bash
# In register_to_reactor after reactor->add():
char epoll_path[64];
snprintf(epoll_path, sizeof(epoll_path), "/proc/self/fdinfo/%d", reactor->epfd);
# Read and log epoll file descriptor info
```

### 3. Test Direct epoll
Create minimal test: bind UDP socket, add to epoll, send packet, verify epoll_wait triggers.

### 4. Check Swoole Process Mode
```php
// In http3_server.php, verify mode
echo "Server mode: " . $server->mode . "\n";  // Should be SWOOLE_PROCESS
```

### 5. Alternative: Register in Worker Start

Instead of registering in ReactorThread::init(), try registering in worker start callback:
```cpp
// In Server::onWorkerStart callback
if (worker_id == 0) {
    h3_server->quic_server->register_to_reactor(reactor);
}
```

## Code Locations

### Registration Call
- **File:** `src/server/reactor_thread.cc`
- **Function:** `ReactorThread::init()`
- **Line:** ~834-842
- **Condition:** `reactor_id == 0`

### Handler Set
- **File:** `src/protocol/quic_openssl.cc`
- **Function:** `Listener::register_to_reactor()`
- **Line:** ~620
- **Type:** `SW_FD_DGRAM_SERVER`

### Callback Definition
- **File:** `src/protocol/quic_openssl.cc`
- **Function:** `Listener::on_reactor_read()`
- **Line:** ~864-883
- **Static:** Yes (registered as function pointer)

## Workarounds to Try

1. **Move registration to onWorkerStart**
2. **Use different fd_type** (e.g., SW_FD_UDP instead of SW_FD_DGRAM_SERVER)
3. **Register handler after reactor->add()** instead of before
4. **Create socket in worker process** instead of master/reactor thread init

## System Information

```
Swoole version: 6.1.2
PHP version: 8.3.14
OpenSSL: 3.5.0 (QUIC enabled)
Platform: Linux 4.4.0
Worker processes: 2
Reactor threads: Unknown (need to check)
```

## Conclusion

The HTTP/3 QUIC listener is correctly initialized and registered to the reactor with all success indicators, but the reactor read callback is never invoked when UDP packets arrive. This suggests a process/thread boundary issue or epoll monitoring problem rather than a code logic error.

**Most Likely Cause:** Reactor registration happening in wrong process or timing in Swoole's multi-process architecture.

**Recommended Fix:** Register HTTP/3 listener in worker process start callback instead of reactor thread init.
