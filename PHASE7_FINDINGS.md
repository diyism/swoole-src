# Phase 7 HTTP/3 Server - Critical Findings

Date: 2025-11-18
Session: claude/sync-http3-server-01Y6UXTJM4b5RzBewB1QFPh2

## Root Cause Analysis

### Issue 1: OSSL_QUIC_server_method Undefined Symbol

**Symptom:**
```
php: symbol lookup error: /usr/lib/php/20240924/swoole.so: undefined symbol: OSSL_QUIC_server_method
```

**Root Cause:**
- PHP binary is linked against system OpenSSL 3.0.13 (`/lib/x86_64-linux-gnu/libssl.so.3`)
- System OpenSSL 3.0.13 does NOT have QUIC support (`OSSL_QUIC_server_method` not available)
- Swoole extension was compiled against OpenSSL 3.5.0 with QUIC support (`/usr/local/openssl35/lib64/libssl.so.3`)
- When PHP loads, it loads system OpenSSL first
- When swoole.so tries to call `OSSL_QUIC_server_method`, the symbol cannot be resolved from the already-loaded system OpenSSL

**Verification:**
```bash
# System OpenSSL - NO QUIC
$ openssl version
OpenSSL 3.0.13 30 Jan 2024

$ nm -D /lib/x86_64-linux-gnu/libssl.so.3 | grep OSSL_QUIC_server_method
# (no output - symbol not found)

# OpenSSL 3.5 - HAS QUIC
$ nm -D /usr/local/openssl35/lib64/libssl.so.3 | grep OSSL_QUIC_server_method
000000000007ca10 T OSSL_QUIC_server_method@@OPENSSL_3.5.0

# PHP is linked to system OpenSSL
$ ldd /usr/bin/php | grep ssl
libssl.so.3 => /lib/x86_64-linux-gnu/libssl.so.3 (0x00007ecaf4207000)
libcrypto.so.3 => /lib/x86_64-linux-gnu/libcrypto.so.3 (0x00007ecaf3400000)
```

### Solution: LD_LIBRARY_PATH

**Why RUNPATH Doesn't Work:**
- RUNPATH is embedded in swoole.so: `/usr/local/openssl35/lib64:/usr/local/openssl35/lib`
- But RUNPATH only affects direct library loading
- Since PHP already loaded system OpenSSL, symbol resolution uses the already-loaded library

**Working Solution:**
```bash
LD_LIBRARY_PATH=/usr/local/openssl35/lib64:/usr/local/openssl35/lib php http3_server.php
```

This forces the dynamic linker to load OpenSSL 3.5 libraries BEFORE PHP starts, so they take precedence.

## Server Status

### ✅ Achievements

1. **Symbol Resolution Fixed**
   - Server starts successfully with LD_LIBRARY_PATH
   - No more "undefined symbol" errors

2. **Server Initialization**
   - Master process spawns correctly (PID: 39030)
   - Worker processes created (4 workers)
   - TCP port 9501 bound successfully (for HTTP/1.1 and HTTP/2)
   - UDP port 9501 bound successfully (for HTTP/3 QUIC)

3. **Port Verification**
   ```
   COMMAND   PID USER   FD   TYPE DEVICE SIZE NODE NAME
   php     39031 root    4u  IPv4  24225       TCP *:9501 (LISTEN)
   php     39031 root   13u  IPv4  24230       UDP *:9501
   ```

### ❌ Remaining Issue

**Server Crash**
- Server starts successfully
- Binds to ports
- Exits silently after ~1-2 seconds
- No error messages in logs
- Both HTTP/1.1 and HTTP/3 connections refused

**Possible Causes:**
1. Segmentation fault during QUIC listener initialization
2. SSL context issue after worker processes spawn
3. QUIC server event loop crash
4. Memory corruption in HTTP/3 initialization

## Next Steps

1. **Capture Core Dump**
   ```bash
   ulimit -c unlimited
   LD_LIBRARY_PATH=/usr/local/openssl35/lib64 php http3_server.php
   ```

2. **Run with GDB**
   ```bash
   LD_LIBRARY_PATH=/usr/local/openssl35/lib64 gdb --args php http3_server.php
   ```

3. **Check System Logs**
   ```bash
   dmesg | tail -20  # Check for segfault messages
   ```

4. **Add Debug Logging**
   - Add swoole_trace_log calls in HTTP/3 bind and event loop code
   - Log when QUIC listener is created
   - Log when SSL context is initialized

## Code Changes Summary (Commit bbb1e1a)

### Files Modified

1. **include/swoole_http3.h**
   - Changed `bind()` signature to accept cert/key paths instead of SSL_CTX

2. **src/protocol/http3.cc**
   - Implemented QUIC-specific SSL context creation using `OSSL_QUIC_server_method()`
   - Load certificates from file paths

3. **src/server/reactor_thread.cc**
   - Extract SSL cert/key file paths from ListenPort
   - Pass paths to HTTP/3 bind() instead of SSL_CTX

4. **ext-src/swoole_http3_server.cc**
   - Simplified to delegate SSL context creation to C++ layer

### Why This Architecture?

**Problem:** QUIC requires `SSL_CTX` created with `OSSL_QUIC_server_method()`, but Swoole's ListenPort uses `TLS_server_method()`

**Solution:** Create separate QUIC-specific SSL context inside HTTP/3 layer

**Key Insight:** Cannot reuse TLS SSL_CTX for QUIC - they are incompatible at the SSL_METHOD level

## Environment Details

```
Swoole version: 6.1.2
PHP version: 8.3.14
System OpenSSL: 3.0.13 (no QUIC)
OpenSSL 3.5: 3.5.0 (with QUIC support)
nghttp3 version: 1.12.0
Platform: Linux 4.4.0
```

## Lessons Learned

1. **QUIC SSL Context Must Use OSSL_QUIC_server_method()**
   - Cannot use TLS_server_method() or TLS_client_method()
   - This is a hard requirement in OpenSSL 3.5 QUIC implementation

2. **Symbol Resolution in PHP Extensions is Complex**
   - RUNPATH doesn't help when parent process already loaded a conflicting library
   - LD_LIBRARY_PATH is the most reliable solution
   - Consider distributing a wrapper script that sets LD_LIBRARY_PATH

3. **OpenSSL Version Matters**
   - QUIC support only in OpenSSL 3.5+
   - System OpenSSL on most Linux distros is still 3.0.x
   - Cannot mix OpenSSL versions in same process

4. **UDP Socket Binding is Different from SSL Listener**
   - UDP socket can bind successfully
   - But QUIC SSL listener may still fail during initialization
   - Need to check SSL_new_listener() separately from socket bind()
