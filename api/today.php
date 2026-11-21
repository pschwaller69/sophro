<?php
declare(strict_types=1);

/* Résumé du contenu du jour, lu par le service worker pour afficher la notification. */

require dirname(__DIR__) . '/lib/bootstrap.php';
require ROOT . '/lib/daily.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
$c = content_for_day(date('Y-m-d'));
$kind = $c ? content_kind($c) : 'texte';
echo json_encode([
    'title' => match ($kind) {
        'audio' => 'Votre instant du jour : une mini-séance',
        'image' => 'Votre instant du jour est arrivé',
        default => 'Votre instant du jour est arrivé',
    },
    'body'  => $c ? (trim((string) $c['title']) !== '' ? $c['title'] : excerpt((string) $c['body'], 120)) : 'Prenez le temps de respirer.',
    'image' => $c && $c['image'] ? media_url($c['image']) : null,
    'url'   => '/?source=notification',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
