<?php
// Server tiruan Brevo + Meta untuk tes lokal: php -S 127.0.0.1:8091 tests/mock-outbox.php
// Setiap request dicatat (tanpa header rahasia) ke storage/test/outbox-requests.jsonl.
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
$dir = __DIR__ . '/../storage/test';
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}
file_put_contents($dir . '/outbox-requests.jsonl', json_encode([
    'path' => $path,
    'has_api_key' => ($_SERVER['HTTP_API_KEY'] ?? '') !== '',
    'has_token' => isset($_GET['access_token']) && $_GET['access_token'] !== '',
    'body' => json_decode($body, true),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

header('Content-Type: application/json');
if ($path === '/brevo') {
    if (($_SERVER['HTTP_API_KEY'] ?? '') === 'kunci-salah') {
        http_response_code(401);
        echo json_encode(['code' => 'unauthorized', 'message' => 'Key not found']);
        return;
    }
    http_response_code(201);
    echo json_encode(['messageId' => '<' . bin2hex(random_bytes(8)) . '@smtp-relay.mock>']);
    return;
}
if (preg_match('#^/meta/\d+/events$#', $path)) {
    $n = count(json_decode($body, true)['data'] ?? []);
    echo json_encode(['events_received' => $n, 'fbtrace_id' => 'mock']);
    return;
}
http_response_code(404);
echo json_encode(['error' => 'not_found']);
