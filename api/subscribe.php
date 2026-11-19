<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require ROOT . '/lib/push.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('{"error":"POST attendu"}');
}
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
try {
    $endpoint = (string) ($in['endpoint'] ?? '');
    if (($in['action'] ?? 'subscribe') === 'unsubscribe') {
        delete_subscription($endpoint);
    } else {
        save_subscription($endpoint);
    }
    echo '{"ok":true}';
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
