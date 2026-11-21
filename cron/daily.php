<?php
declare(strict_types=1);

/*
 * Tâche quotidienne (cron o2switch, chaque matin à 7 h 30) :
 *   30 7 * * * php ~/sophro.schwaller.fr/cron/daily.php
 * 1. fixe le contenu du jour ;
 * 2. envoie la notification (et la pastille sur l'icône) à tous les abonnés ;
 * 3. publie le contenu sur Facebook / Instagram si c'est activé dans l'admin.
 * Chaque étape n'est faite qu'une fois par jour, même si la tâche est relancée.
 * Options : --force (refaire même si déjà fait aujourd'hui), --no-social, --no-push
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require ROOT . '/lib/daily.php';
require ROOT . '/lib/push.php';
require ROOT . '/lib/social.php';

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$today = date('Y-m-d');

$c = content_for_day($today);
if (!$c) {
    echo "$today : aucun instant, rien à faire.\n";
    exit(0);
}
echo "$today : « " . content_label($c) . " »\n";

if (!in_array('--no-push', $args, true) && ($force || setting('push_day') !== $today)) {
    set_setting('push_day', $today);
    [$ok, $fail, $removed] = push_all();
    echo "Notifications : $ok envoyée(s), $fail échec(s), $removed abonnement(s) expiré(s) supprimé(s).\n";
}

if (!in_array('--no-social', $args, true) && ($force || setting('social_day') !== $today)) {
    set_setting('social_day', $today);
    foreach (post_to_socials($c) as $line) {
        echo $line . "\n";
    }
}
