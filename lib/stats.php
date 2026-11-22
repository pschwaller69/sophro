<?php
declare(strict_types=1);

/*
 * Mesure d'audience sans traceur externe : chaque appareil a un numéro au hasard (gardé sur le téléphone),
 * stocké ici haché avec un sel du serveur. Une seule ligne par appareil, par jour et par instant.
 * Les données de plus de 13 mois sont effacées (recommandation CNIL pour la mesure d'audience).
 */

function record_view(int $contentId, string $visitor, string $kind, string $source): void
{
    $salt = setting('stats_salt');
    if (!$salt) {
        $salt = bin2hex(random_bytes(16));
        set_setting('stats_salt', $salt);
    }
    $kind = $kind === 'link' ? 'link' : 'today';
    $source = in_array($source, ['app', 'notification'], true) ? $source : 'web';
    db()->prepare('INSERT OR IGNORE INTO views(day, content_id, visitor, kind, source) VALUES(?, ?, ?, ?, ?)')
        ->execute([date('Y-m-d'), $contentId, substr(hash('sha256', $salt . $visitor), 0, 24), $kind, $source]);

    if (random_int(1, 200) === 1) {
        db()->prepare('DELETE FROM views WHERE day < ?')->execute([date('Y-m-d', strtotime('-13 months'))]);
    }
}

/** Nombre de personnes différentes entre deux dates (incluses). */
function visitors_between(string $from, string $to, ?string $source = null): int
{
    $sql = 'SELECT COUNT(DISTINCT visitor) FROM views WHERE day BETWEEN ? AND ?' . ($source ? ' AND source = ?' : '');
    $st = db()->prepare($sql);
    $st->execute($source ? [$from, $to, $source] : [$from, $to]);
    return (int) $st->fetchColumn();
}

/** Par jour : l'instant du jour, combien de personnes l'ont vu, dont via l'appli / la notification, et les liens partagés ouverts. */
function daily_stats(int $days = 30): array
{
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $st = db()->prepare("
        SELECT v.day,
               COUNT(DISTINCT v.visitor) AS people,
               COUNT(DISTINCT CASE WHEN v.kind = 'today' THEN v.visitor END) AS today_people,
               COUNT(DISTINCT CASE WHEN v.kind = 'today' AND v.source = 'app' THEN v.visitor END) AS app_people,
               COUNT(DISTINCT CASE WHEN v.kind = 'today' AND v.source = 'notification' THEN v.visitor END) AS notif_people,
               COUNT(DISTINCT CASE WHEN v.kind = 'link' THEN v.visitor END) AS link_people
        FROM views v WHERE v.day >= ? GROUP BY v.day");
    $st->execute([$from]);
    $byDay = [];
    foreach ($st->fetchAll() as $r) {
        $byDay[$r['day']] = $r;
    }
    $out = [];
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $st = db()->prepare('SELECT c.* FROM daily d JOIN contents c ON c.id = d.content_id WHERE d.day = ?');
        $st->execute([$d]);
        $out[] = ['day' => $d, 'content' => $st->fetch() ?: null] + ($byDay[$d] ?? ['people' => 0, 'today_people' => 0, 'app_people' => 0, 'notif_people' => 0, 'link_people' => 0]);
    }
    return $out;
}

/** Instants dont le lien partagé a été le plus ouvert. */
function top_shared(int $days = 30, int $limit = 10): array
{
    $st = db()->prepare("
        SELECT c.*, COUNT(DISTINCT v.visitor || v.day) AS opens
        FROM views v JOIN contents c ON c.id = v.content_id
        WHERE v.kind = 'link' AND v.day >= ?
        GROUP BY c.id ORDER BY opens DESC LIMIT " . (int) $limit);
    $st->execute([date('Y-m-d', strtotime('-' . ($days - 1) . ' days'))]);
    return $st->fetchAll();
}
