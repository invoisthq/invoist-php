<?php

declare(strict_types=1);

// A router for PHP's built-in server, used by CurlTransportTest: it answers
// with what it received, or with a canned response, by path.

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(\is_string($uri) ? $uri : '/', PHP_URL_PATH);

if ($path === '/slow') {
    usleep(1_500_000);
}
if ($path === '/file') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="bill.pdf"');
    echo "%PDF-1.4\x00\x01\xff";

    return;
}
if ($path === '/error') {
    http_response_code(429);
    header('Retry-After: 0');
    header('X-Request-Id: req_local');
    header('Content-Type: application/json');
    echo '{"error":{"code":"rate_limited","message":"too many requests"}}';

    return;
}
if ($path === '/empty') {
    http_response_code(204);

    return;
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
}
$headers['content-type'] = $_SERVER['CONTENT_TYPE'] ?? null;
// The server runs with enable_post_data_reading=0, so multipart bodies arrive raw too.
header('Content-Type: application/json');
header('X-Request-Id: req_echo');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'body' => base64_encode((string) file_get_contents('php://input')),
], JSON_THROW_ON_ERROR);
