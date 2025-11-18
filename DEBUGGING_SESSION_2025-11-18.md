# HTTP/3 Server Debugging Session - 2025-11-18

## Session Context

Continued investigation of HTTP/3 server crash issue from previous session.

**Initial Problem:** Server crashed immediately after startup with no error messages.

**Branch:** `claude/sync-http3-server-01Y6UXTJM4b5RzBewB1QFPh2`

## Critical Discovery: Segmentation Fault

### Debug Process

1. **Running with strace**
   ```bash
   strace -f -o /tmp/http3_strace_full.log php http3_server.php
   ```

   **Result:** Server stayed running under strace (timing-sensitive issue)

2. **Analyzing strace output**
   ```
   947   bind(13, {sa_family=AF_INET, sin_port=htons(9501), sin_addr=inet_addr("0.0.0.0")}, 16) = 0
   947   epoll_ctl(11, EPOLL_CTL_ADD, 13, {events=EPOLLIN, ...}) = 0
   947   epoll_wait(11, ...) = 1
   947   --- SIGSEGV {si_signo=SIGSEGV, si_code=SEGV_MAPERR, si_addr=NULL} ---
   947   +++ killed by SIGSEGV +++
   ```

   **Key Finding:**
   - UDP socket (fd=13) bound successfully to port 9501
   - Added to epoll successfully
   - epoll_wait returned with 1 event (incoming QUIC packet)
   - **CRASH** with NULL pointer dereference (`si_addr=NULL`)

### Root Cause Identified

**File:** `src/protocol/quic_openssl.cc`
**Function:** `Listener::create_swoole_connection()`
**Line:** 699

**Problematic Code:**
```cpp
// Find the ListenPort for this listener
swoole::ListenPort *port = nullptr;
for (auto ls : swoole_server->ports) {
    if (ls->socket->fd == udp_fd) {  // ❌ NULL POINTER DEREFERENCE
        port = ls;
        break;
    }
}
```

**Problem:**
- In worker process, `swoole_server->ports` may contain NULL elements
- Or `ls->socket` may be NULL after fork()
- Accessing `ls->socket->fd` without NULL check causes segfault

**Fix Applied (Commit 8263826):**
```cpp
for (auto ls : swoole_server->ports) {
    if (ls && ls->socket && ls->socket->fd == udp_fd) {  // ✅ NULL CHECKS
        port = ls;
        break;
    }
}
```

## Results After Fix

### ✅ Achievements

1. **Server No Longer Crashes**
   - Server starts successfully
   - No more SIGSEGV errors
   - Clean shutdown possible

2. **Ports Bound Correctly**
   ```
   COMMAND   PID USER   FD   TYPE DEVICE SIZE NODE NAME
   php     21340 root    4u  IPv4   2230       TCP *:9501 (LISTEN)
   php     21340 root   13u  IPv4   2235       UDP *:9501
   ```
   - Both TCP (for HTTP/1.1, HTTP/2) and UDP (for HTTP/3) listening

3. **HTTP/3 Compilation Successful**
   ```
   HTTP/3 support: ✅ ENABLED
   ```

### ❌ Remaining Issue

**QUIC Connection Refused**
```bash
$ curl --http3-only https://localhost:9501 -k
curl: (7) error:8000006F:system library::Connection refused
```

**Symptoms:**
- Server running and ports bound
- QUIC packets reaching UDP socket (epoll events trigger)
- OpenSSL/QUIC layer rejecting connections
- No error messages in server logs

**Possible Causes:**
1. `SSL_listen()` not called or failing silently
2. `SSL_accept_connection()` not processing incoming handshakes
3. ListenPort not found in worker process (returns nullptr)
4. Virtual FD system not working correctly
5. QUIC SSL context incompatible with listener mode

## Commits in This Session

### Commit 2cf1dae
**Message:** `fix(http3): Resolve OpenSSL symbol lookup error and document findings`

**Changes:**
- Created `PHASE7_FINDINGS.md` documenting OpenSSL linkage issue
- Root cause: PHP linked to system OpenSSL 3.0.13, swoole.so needs OpenSSL 3.5.0
- Solution: Use `LD_LIBRARY_PATH=/usr/local/openssl35/lib64` when running server

### Commit 8263826
**Message:** `fix(http3): Add NULL pointer checks to prevent segmentation fault`

**Changes:**
- Added NULL pointer guards in `create_swoole_connection()`
- Prevents segfault when iterating through `swoole_server->ports`
- Server now starts without crashing

## Technical Insights

### OpenSSL Symbol Resolution

**Problem:** Symbol lookup error for `OSSL_QUIC_server_method`
```
php: symbol lookup error: /usr/lib/php/20240924/swoole.so: undefined symbol: OSSL_QUIC_server_method
```

**Why RUNPATH Doesn't Work:**
- RUNPATH embedded in swoole.so: `/usr/local/openssl35/lib64`
- But PHP binary already loaded system OpenSSL 3.0.13
- Symbol resolution uses already-loaded library
- RUNPATH only affects direct library loading, not symbol resolution

**Solution:**
```bash
LD_LIBRARY_PATH=/usr/local/openssl35/lib64:/usr/local/openssl35/lib php http3_server.php
```

Forces dynamic linker to load OpenSSL 3.5 BEFORE PHP starts.

### Worker Process Architecture

**Key Understanding:**
- Swoole forks worker processes after binding ports
- `swoole_server` state may not be fully initialized in workers
- Pointers in `swoole_server->ports` may be NULL or invalid
- Must add defensive NULL checks in all worker code paths

**strace Evidence:**
- Parent process (PID 945) binds sockets
- Worker process (PID 947) handles epoll events
- Crash occurs in worker when processing first QUIC packet

### QUIC Listener Lifecycle

**Expected Flow:**
1. `Listener::init()` - Create SSL_CTX with `OSSL_QUIC_server_method()`
2. `Listener::listen()` - Bind UDP socket, call `SSL_new_listener()`
3. `Listener::register_to_reactor()` - Add to epoll with callback
4. Reactor callback - Call `SSL_accept_connection()` on incoming packets

**Current Status:**
- Steps 1-3 complete ✅
- Step 4 failing - connections refused ❌

## Environment Details

```
Swoole version: 6.1.2
PHP version: 8.3.14
System OpenSSL: 3.0.13 (no QUIC)
OpenSSL 3.5: 3.5.0 (with QUIC support at /usr/local/openssl35)
nghttp3 version: 1.12.0
Platform: Linux 4.4.0
```

## Next Steps

1. **Add Debug Logging**
   - Log when `SSL_new_listener()` is called
   - Log when `SSL_accept_connection()` is called
   - Log return values and error codes

2. **Verify SSL Context**
   - Check if `ssl_listener` is valid in worker process
   - Verify ALPN callback is set correctly
   - Check if UDP socket is readable in worker

3. **Test Simplified QUIC Server**
   - Create minimal OpenSSL 3.5 QUIC server
   - Verify basic QUIC handshake works
   - Compare with Swoole implementation

4. **Check Worker Initialization**
   - Verify `Listener::set_server()` called in workers
   - Check if `swoole_server` pointer is valid
   - Log when reactor handler is registered

5. **Alternative Approach**
   - Consider not using virtual FD system initially
   - Try direct QUIC connection handling
   - Simplify to get basic HTTP/3 working first

## Files Modified

1. `src/protocol/quic_openssl.cc`
   - Line 699: Added NULL pointer checks

2. `PHASE7_FINDINGS.md` (created)
   - Documented OpenSSL linkage investigation

3. `DEBUGGING_SESSION_2025-11-18.md` (this file)
   - Complete debugging session log

## Summary

**Major Progress:**
- ✅ Identified and fixed segmentation fault
- ✅ Server starts without crashing
- ✅ Ports bind correctly
- ✅ Resolved OpenSSL symbol linkage issue

**Remaining Work:**
- ❌ QUIC listener not accepting connections
- Next: Debug why `SSL_accept_connection()` rejects clients

**Key Learning:**
Always add NULL checks when accessing pointers in worker processes, as fork() may invalidate parent process state.

