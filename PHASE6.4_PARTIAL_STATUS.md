# Phase 6.4 Partial Implementation Status

## 📋 Current Status

**Phase**: 6.4 - HTTP/3 Response Write-back
**Status**: 🚧 PARTIAL IMPLEMENTATION (60% Complete)
**Date**: 2025-11-18
**Branch**: `claude/sync-http3-server-01Y6UXTJM4b5RzBewB1QFPh2`

---

## ✅ Completed Work

### 1. Response Serialization (Worker Side)

**Location**: `ext-src/swoole_http_server.cc:184-293`

Implemented `swoole_http3_server_end()` function that:
- ✅ Extracts stream_id from Response object
- ✅ Serializes response data to JSON format
- ✅ Includes: session_id, stream_id, status_code, headers, body
- ✅ Performs JSON escaping for all string values
- ✅ Logs response sending
- ✅ Calls Server::send() to transmit data

**JSON Format**:
```json
{
  "session_id": 1234,
  "stream_id": 0,
  "status_code": 200,
  "headers": {"content-type": "text/html"},
  "body": "Hello HTTP/3!"
}
```

### 2. HttpContext::end() Integration

**Location**: `ext-src/swoole_http_response.cc:781-788`

Modified `HttpContext::end()` to:
- ✅ Detect HTTP/3 responses by checking for `streamId` property
- ✅ Call `swoole_http3_server_end()` for HTTP/3 requests
- ✅ Fallback to HTTP/2 or HTTP/1.1 handling otherwise

**Detection Logic**:
```cpp
// Check for HTTP/3 by looking for streamId property
if (!http2) {
    zval *zstream_id = sw_zend_read_property(swoole_http_response_ce,
        response.zobject, ZEND_STRL("streamId"), 1);
    if (zstream_id && Z_TYPE_P(zstream_id) == IS_LONG) {
        // This is an HTTP/3 response
        RETURN_BOOL(swoole_http3_server_end(this, zdata));
    }
}
```

### 3. Header Files Updated

**Location**: `ext-src/php_swoole_http_server.h:45-46`

Added function declaration:
```cpp
// Phase 6.4: HTTP/3 response handling
bool swoole_http3_server_end(swoole::http::Context *ctx, zval *zdata);
```

### 4. Compilation Status

- ✅ All code compiles successfully
- ✅ No warnings or errors
- ✅ Library size: ~46MB

---

## ⏳ Remaining Work

### 1. Reactor-Side Response Reception (TODO)

**Goal**: Receive JSON response data in Reactor thread

**Approach Options**:

#### Option A: Custom Event Type (Recommended)
Create a new event type `SW_SERVER_EVENT_HTTP3_RESPONSE` and use SendData:

```cpp
// In swoole_http3_server_end():
SendData send_data = {};
send_data.info.type = SW_SERVER_EVENT_HTTP3_RESPONSE;
send_data.info.fd = ctx->fd;
send_data.info.len = json.length();
send_data.data = json.c_str();

// Send to Reactor thread
serv->send_to_reactor_thread(&send_data);
```

#### Option B: Pipe Communication
Use Worker→Reactor pipe with custom protocol marker.

#### Option C: Callback-based (HTTP/2 style)
Store responses in a global map and use callbacks, but this requires Stream objects accessible from Worker (complex).

**Recommendation**: Use Option A with a new event type.

### 2. JSON Response Parsing (TODO)

**Location**: To be added in Reactor thread handling code

**Pseudocode**:
```cpp
// In Reactor thread event handler
if (event_data.info.type == SW_SERVER_EVENT_HTTP3_RESPONSE) {
    std::string json_str(event_data.data, event_data.info.len);

    // Parse JSON (can use same approach as Phase 6.3)
    zval args[2];
    ZVAL_STRING(&args[0], json_str.c_str());
    ZVAL_BOOL(&args[1], true);

    zend::Variable result = zend::function::call("json_decode", 2, args);

    // Extract fields
    zval *result_ptr = result.ptr();
    HashTable *ht = Z_ARRVAL_P(result_ptr);

    int64_t stream_id = /* extract from ht */;
    int status_code = /* extract from ht */;
    // ... extract headers and body
}
```

### 3. HTTP/3 Stream Lookup (TODO)

**Challenge**: Need to maintain a mapping from (session_id, stream_id) to HTTP/3 Stream objects.

**Proposed Solution**:
```cpp
// Global map (or in HTTP/3 Server object)
std::unordered_map<std::string, http3::Stream*> active_http3_streams;

// Key format: "session_id:stream_id"
std::string make_stream_key(SessionId sid, int64_t stream_id) {
    return std::to_string(sid) + ":" + std::to_string(stream_id);
}

// During request handling (Phase 6.2):
std::string key = make_stream_key(session_id, stream_id);
active_http3_streams[key] = stream;

// During response handling:
std::string key = make_stream_key(session_id, stream_id);
auto it = active_http3_streams.find(key);
if (it != active_http3_streams.end()) {
    http3::Stream *stream = it->second;
    // Write response to stream
}
```

### 4. HTTP/3 Response Writing (TODO)

**Location**: To be added in Reactor thread

**Pseudocode**:
```cpp
// Build HTTP/3 headers
std::vector<http3::HeaderField> headers;
headers.push_back({":status", std::to_string(status_code)});

// Add custom headers from JSON
for (auto &[key, val] : json_headers) {
    headers.push_back({key, val});
}

// Send response via HTTP/3 Stream
stream->send_response(
    status_code,
    headers,
    (const uint8_t*)body_data,
    body_length
);

// Clean up stream mapping
active_http3_streams.erase(key);
```

---

## 🔧 Implementation Plan for Completion

### Step 1: Add Event Type (15 min)
- Define `SW_SERVER_EVENT_HTTP3_RESPONSE` in `include/swoole_server.h`
- Modify `swoole_http3_server_end()` to use this event type

### Step 2: Modify Reactor Event Handler (30 min)
- Find where Reactor handles Worker events
- Add case for `SW_SERVER_EVENT_HTTP3_RESPONSE`
- Parse JSON response
- Extract session_id, stream_id, status, headers, body

### Step 3: Implement Stream Mapping (20 min)
- Add `active_http3_streams` map to HTTP/3 Server or global scope
- Register streams during request handling (modify Phase 6.2 code)
- Lookup streams during response handling
- Remove streams after response sent

### Step 4: Write to HTTP/3 Stream (20 min)
- Call `stream->send_response()` with parsed data
- Handle errors (stream not found, send failed)
- Clean up resources

### Step 5: Testing (30 min)
- Test end-to-end flow
- Verify response reaches client
- Check memory leaks
- Test multiple concurrent requests

**Total Estimated Time**: 2 hours

---

## 🎯 Success Criteria

When Phase 6.4 is complete, the following should work:

```php
$server->on('Request', function ($request, $response) {
    var_dump($request->server['server_protocol']); // "HTTP/3" ✅

    $response->status(200);
    $response->header('Content-Type', 'text/plain');
    $response->end("Hello from HTTP/3!"); // ⏳ Should send to client
});
```

**Expected**:
- ✅ Client receives HTTP/3 response
- ✅ Status code is correct
- ✅ Headers are correct
- ✅ Body content is correct
- ✅ No memory leaks
- ✅ Multiple requests work correctly

---

## 📊 Progress Breakdown

| Task | Status | Lines | Complexity |
|------|--------|-------|------------|
| Response serialization | ✅ Done | ~110 | Medium |
| HttpContext::end() integration | ✅ Done | ~8 | Easy |
| Header declarations | ✅ Done | ~2 | Easy |
| Event type definition | ⏳ TODO | ~2 | Easy |
| Reactor event handling | ⏳ TODO | ~50 | Medium |
| Stream mapping system | ⏳ TODO | ~30 | Medium |
| HTTP/3 response writing | ⏳ TODO | ~40 | Medium |
| Testing & debugging | ⏳ TODO | - | Hard |

**Completed**: 120 lines (~60%)
**Remaining**: ~122 lines (~40%)

---

## 🚧 Known Issues

### 1. Server::send() May Not Work Correctly
**Issue**: Using `Server::send()` might try to write directly to socket, which won't work for HTTP/3.

**Solution**: Use proper event-based IPC mechanism with `SW_SERVER_EVENT_HTTP3_RESPONSE`.

### 2. Stream Lifetime Management
**Issue**: Streams may be closed/destroyed before response is sent.

**Solution**: Implement reference counting or keep-alive mechanism for active streams.

### 3. Concurrent Response Handling
**Issue**: Multiple responses for same session need proper synchronization.

**Solution**: Use mutex or ensure Reactor thread handles responses sequentially.

---

## 📚 Reference Code Locations

### For Implementation:
1. **HTTP/2 Response Handling**: `ext-src/swoole_http2_server.cc:654-663`
2. **Reactor Event Loop**: `src/server/reactor_thread.cc`
3. **Worker→Reactor IPC**: `src/server/worker.cc`
4. **HTTP/3 Stream API**: `src/protocol/http3.cc`

### For Testing:
1. **HTTP/3 Server Test**: `examples/http3/` (to be created)
2. **PHP Test Script**: Need to create test with h3 client

---

## 💡 Design Notes

### Why JSON for Responses?
- **Pros**: Easy to debug, symmetric with request handling
- **Cons**: Slight performance overhead
- **Future**: Can optimize to binary format in Phase 7

### Why Not Direct Stream Access?
- HTTP/3 Streams live in Reactor thread
- Worker processes can't directly access Reactor memory
- Need IPC mechanism (similar to how requests are dispatched)

### Architecture Consistency
- Request Flow: Reactor → Worker (via dispatch)
- Response Flow: Worker → Reactor (via send_to_reactor)
- Symmetric design, easier to maintain

---

## 🔜 Next Steps

1. **Immediate**: Commit current partial implementation
2. **Next Session**: Implement remaining Reactor-side handling
3. **Then**: Test end-to-end flow
4. **Finally**: Complete Phase 6 documentation

---

**Status**: Partial Implementation - Ready for Next Phase
**Commit Ready**: Yes
**Estimated Completion Time**: 2 hours of focused work
