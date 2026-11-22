<?php
declare(strict_types=1);

/*
 * Choix du contenu du jour, par ordre de priorité :
 *  1. un contenu programmé à cette date précise ;
 *  2. un contenu « chaque année » dont la période couvre ce jour (Noël, printemps…) ;
 *  3. un contenu réservé à ce jour de la semaine (instants du lundi, du vendredi…) ;
 *  4. sinon, un tirage au hasard parmi les contenus libres et les anciens contenus datés,
 *     en privilégiant ceux qui n'ont pas été montrés depuis longtemps.
 * Le choix est mémorisé (table daily) pour que tout le monde voie le même contenu toute la journée.
 */

function content_for_day(string $day, bool $persist = true): ?array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT c.* FROM daily d JOIN contents c ON c.id = d.content_id WHERE d.day = ? AND c.active = 1');
    $st->execute([$day]);
    if ($c = $st->fetch()) {
        return $c;
    }
    $c = pick_for_day($day);
    if ($c && $persist) {
        $pdo->prepare('INSERT OR REPLACE INTO daily(day, content_id) VALUES(?, ?)')->execute([$day, $c['id']]);
    }
    return $c;
}

/** Contenus imposés pour ce jour (date précise puis période annuelle). Sans tirage au hasard. */
function forced_for_day(string $day): array
{
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM contents WHERE active = 1 AND mode = 'date' AND publish_date = ? ORDER BY id DESC");
    $st->execute([$day]);
    $rows = $st->fetchAll();
    if ($rows) {
        return ['date', $rows];
    }
    $md = substr($day, 5);
    $st = $pdo->prepare("
        SELECT c.*, (SELECT MAX(day) FROM daily WHERE content_id = c.id AND day < :day) AS last_shown
        FROM contents c
        WHERE c.active = 1 AND c.mode = 'yearly' AND (
            (c.yearly_start <= c.yearly_end AND :md BETWEEN c.yearly_start AND c.yearly_end)
         OR (c.yearly_start >  c.yearly_end AND (:md >= c.yearly_start OR :md <= c.yearly_end))
        )");
    $st->execute([':day' => $day, ':md' => $md]);
    $rows = $st->fetchAll();
    return $rows ? ['yearly', $rows] : ['random', []];
}

function pick_for_day(string $day): ?array
{
    [$kind, $rows] = forced_for_day($day);
    if ($kind === 'date') {
        return $rows[0];
    }
    if ($kind === 'yearly') {
        // Contenus de saison pas encore montrés depuis le début de la période en cours : prioritaires.
        // Les périodes les plus courtes passent en premier : un contenu « 25 décembre » l'emporte le jour J.
        $fresh = array_values(array_filter($rows, fn($r) => (string) $r['last_shown'] < period_start($r, $day)));
        if ($fresh) {
            $min = min(array_map('period_length', $fresh));
            return pick_fair(array_values(array_filter($fresh, fn($r) => period_length($r) === $min)));
        }
    }
    // Instants réservés à ce jour de la semaine (ex. « chaque lundi »)
    if ($week = weekday_for_day($day)) {
        return pick_fair($week);
    }
    if ($kind === 'yearly') {
        // Tous déjà vus cette saison : on les mélange avec les autres contenus.
        return pick_fair(array_merge($rows, pool_for_day($day)));
    }
    return pick_fair(pool_for_day($day));
}

/** Instants qui doivent passer ce jour-là (avant tout tirage au hasard parmi les autres). */
function imposed_for_day(string $day): array
{
    [$kind, $rows] = forced_for_day($day);
    if ($kind === 'date') {
        return $rows;
    }
    if ($kind === 'yearly' && ($fresh = array_filter($rows, fn($r) => (string) $r['last_shown'] < period_start($r, $day)))) {
        return array_values($fresh);
    }
    return weekday_for_day($day);
}

/** Instants « certains jours de la semaine » qui tombent ce jour-là. */
function weekday_for_day(string $day): array
{
    $t = strtotime($day . ' 12:00:00');
    $n = (int) date('N', $t);
    // « Dernière fois » comptée sur ce même jour de la semaine : un instant « lundi et vendredi »
    // passe aussi son tour le lundi, même s'il vient d'être montré le vendredi.
    $st = db()->prepare("
        SELECT c.*, (SELECT MAX(day) FROM daily WHERE content_id = c.id AND day < :day AND strftime('%w', day) = :w) AS last_shown
        FROM contents c
        WHERE c.active = 1 AND c.mode = 'weekday' AND c.weekdays LIKE :n");
    $st->execute([':day' => $day, ':w' => date('w', $t), ':n' => '%,' . $n . ',%']);
    return $st->fetchAll();
}

/** Durée de la période annuelle, en jours. */
function period_length(array $c): int
{
    $a = (int) date('z', strtotime('2023-' . $c['yearly_start']));
    $b = (int) date('z', strtotime('2023-' . $c['yearly_end']));
    return ($b - $a + 365) % 365 + 1;
}

/** Date (AAAA-MM-JJ) du début de la période annuelle qui contient $day. */
function period_start(array $c, string $day): string
{
    $y = (int) substr($day, 0, 4);
    $md = substr($day, 5);
    if ($c['yearly_start'] > $c['yearly_end'] && $md <= $c['yearly_end']) {
        $y--; // période à cheval sur deux années (ex. 21 déc → 10 janv), on est en janvier
    }
    return sprintf('%04d-%s', $y, $c['yearly_start']);
}

/** Contenus libres et anciens contenus datés (jamais les contenus de saison). */
function pool_for_day(string $day): array
{
    $st = db()->prepare("
        SELECT c.*, (SELECT MAX(day) FROM daily WHERE content_id = c.id AND day < :day) AS last_shown
        FROM contents c
        WHERE c.active = 1 AND (c.mode = 'auto' OR (c.mode = 'date' AND c.publish_date < :day))");
    $st->execute([':day' => $day]);
    return $st->fetchAll();
}

/** Hasard parmi le tiers des contenus montrés il y a le plus longtemps (jamais montrés en premier). */
function pick_fair(array $rows): ?array
{
    if (!$rows) {
        return null;
    }
    shuffle($rows);
    usort($rows, fn($a, $b) => strcmp((string) $a['last_shown'], (string) $b['last_shown']));
    $n = max(1, (int) ceil(count($rows) / 3));
    return $rows[random_int(0, $n - 1)];
}

/** Si un contenu modifié concerne aujourd'hui, on oublie le choix déjà fait pour qu'il soit recalculé. */
function reset_today_if_needed(?array $c): void
{
    $today = date('Y-m-d');
    $st = db()->prepare('SELECT content_id FROM daily WHERE day = ?');
    $st->execute([$today]);
    $current = $st->fetchColumn();
    $forcedIds = array_map(fn($r) => (int) $r['id'], imposed_for_day($today));
    $isCurrent = $c && $current !== false && (int) $current === (int) $c['id'];
    $stale = ($isCurrent && !is_public($c))
        || ($isCurrent && $c['mode'] === 'weekday' && !in_array((int) date('N'), weekdays_of($c), true))
        || ($forcedIds && $current !== false && !in_array((int) $current, $forcedIds, true));
    if ($stale) {
        db()->prepare('DELETE FROM daily WHERE day = ?')->execute([$today]);
    }
}

/** Oublie les tirages déjà faits pour les jours à venir (après un ajout, une modification ou un import). */
function clear_future_days(): void
{
    db()->prepare('DELETE FROM daily WHERE day > ?')->execute([date('Y-m-d')]);
}

function recent_days(string $today, int $limit = 7): array
{
    $st = db()->prepare('SELECT d.day, c.* FROM daily d JOIN contents c ON c.id = d.content_id
                         WHERE d.day < ? AND c.active = 1 ORDER BY d.day DESC LIMIT ' . (int) $limit);
    $st->execute([$today]);
    return $st->fetchAll();
}
