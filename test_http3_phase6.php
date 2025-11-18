<?php
/**
 * HTTP/3 Server Test for Phase 6 Implementation
 *
 * This tests the HTTP/3 integration implemented in Phase 6.
 * Uses standard Swoole\HTTP\Server with HTTP/3 enabled.
 */

echo "==============================================\n";
echo "Swoole HTTP/3 Server - Phase 6 Test\n";
echo "==============================================\n\n";

// Check if Swoole is loaded
if (!extension_loaded('swoole')) {
    die("Error: Swoole extension not loaded\n");
}

echo "Swoole version: " . swoole_version() . "\n";

// Check if HTTP/3 support is compiled
if (!defined('SWOOLE_USE_HTTP3') || !SWOOLE_USE_HTTP3) {
    die("Error: Swoole was not compiled with HTTP/3 support\n");
}

echo "HTTP/3 support: ✅ ENABLED\n\n";

// Create standard HTTP Server (NOT Http3\Server)
$server = new Swoole\HTTP\Server("0.0.0.0", 9501, SWOOLE_PROCESS);

// Configure server with HTTP/3 enabled
$server->set([
    // Enable HTTP/3
    'enable_http3' => true,

    // SSL certificate (required for HTTP/3)
    'ssl_cert_file' => __DIR__ . '/examples/ssl/ssl.crt',
    'ssl_key_file' => __DIR__ . '/examples/ssl/ssl.key',

    // Worker settings
    'worker_num' => 2,

    // Logging
    'log_level' => SWOOLE_LOG_TRACE,
    // 'trace_flags' => SWOOLE_TRACE_HTTP3,  // Commented: constant not yet defined

    // HTTP/3 specific settings (optional)
    'http3_max_field_section_size' => 65536,
    'http3_qpack_max_table_capacity' => 4096,
    'http3_qpack_blocked_streams' => 100,
]);

// Handle all HTTP requests (HTTP/1.1, HTTP/2, and HTTP/3)
$server->on('Request', function (Swoole\Http\Request $request, Swoole\Http\Response $response) {
    $protocol = $request->server['server_protocol'] ?? 'UNKNOWN';
    $method = $request->server['request_method'] ?? 'UNKNOWN';
    $uri = $request->server['request_uri'] ?? '/';

    echo "\n=== Received Request ===\n";
    echo "Protocol: {$protocol}\n";
    echo "Method: {$method}\n";
    echo "URI: {$uri}\n";

    if ($protocol === 'HTTP/3') {
        echo "✅ HTTP/3 REQUEST DETECTED!\n";

        // Check if streamId is set (Phase 6.3 feature)
        if (isset($request->streamId)) {
            echo "Stream ID: {$request->streamId}\n";
        }
    }

    // Show some headers
    if (!empty($request->header)) {
        echo "Headers:\n";
        foreach ($request->header as $key => $val) {
            echo "  {$key}: {$val}\n";
        }
    }

    echo "========================\n";

    // Set response headers
    $response->header('Content-Type', 'text/plain; charset=utf-8');
    $response->header('X-Server', 'Swoole-HTTP3-Phase6');
    $response->header('X-Protocol', $protocol);

    // Set status
    $response->status(200);

    // Create response body
    $body = "Hello from Swoole HTTP/3 (Phase 6)!\n\n";
    $body .= "Protocol: {$protocol}\n";
    $body .= "Method: {$method}\n";
    $body .= "URI: {$uri}\n";
    $body .= "\nPhase 6.1-6.4: Complete request/response cycle ✅\n";

    // Send response (Phase 6.4 implementation)
    $response->end($body);

    echo "Response sent successfully!\n\n";
});

echo "Starting HTTP/3 Server...\n";
echo "Listening on: https://0.0.0.0:9501\n";
echo "Protocols: HTTP/1.1, HTTP/2, HTTP/3\n";
echo "\nTest with:\n";
echo "  HTTP/3:  curl --http3-only https://localhost:9501 -k\n";
echo "  HTTP/2:  curl --http2 https://localhost:9501 -k\n";
echo "  HTTP/1:  curl https://localhost:9501 -k\n";
echo "\nPress Ctrl+C to stop\n";
echo "==============================================\n\n";

// Start the server
$server->start();
