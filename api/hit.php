<?php
declare(strict_types=1);

// Compte une lecture (appelé par assets/app.js à l'ouverture d'un instant). Aucune donnée personnelle.
require dirname(__DIR__) . '/lib/bootstrap.php';
require ROOT . '/lib/stats.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('{"error":"POST attendu"}');
}
// Les visites de la sophrologue elle-même (connectée à l'admin) ne sont pas comptées.
if (!empty($_COOKIE['sophro_admin'])) {
    require ROOT . '/lib/auth.php';
    if (is_admin()) {
        exit('{"ok":true,"admin":true}');
    }
}
$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua === '' || preg_match('/bot|crawl|spider|preview|facebookexternalhit|headless/i', $ua)) {
    exit('{"ok":true}');
}
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$id = (int) ($in['p'] ?? 0);
$visitor = (string) ($in['v'] ?? '');
if ($id <= 0 || strlen($visitor) < 8 || strlen($visitor) > 64 || !($c = get_content($id)) || !is_public($c)) {
    http_response_code(400);
    exit('{"error":"requête invalide"}');
}
record_view($id, $visitor, (string) ($in['kind'] ?? ''), (string) ($in['source'] ?? ''));
echo '{"ok":true}';
