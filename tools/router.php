<?php
// Serveur de test local : php -S localhost:8000 tools/router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/p/(\d+)/?$#', $path, $m)) { $_GET['p'] = $m[1]; require __DIR__ . '/../index.php'; return true; }
if (preg_match('#^/(lib|data|tools)/#', $path) || str_ends_with($path, '.sqlite')) { http_response_code(403); return true; }
if ($path === '/manifest.webmanifest') { header('Content-Type: application/manifest+json'); readfile(__DIR__ . '/../manifest.webmanifest'); return true; }
return false;
