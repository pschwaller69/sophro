<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

define('ROOT', dirname(__DIR__));

$__cfgFile = ROOT . '/config.local.php';
$GLOBALS['CONFIG'] = array_merge([
    'site_name'           => 'Sophro du jour',
    'site_tagline'        => 'Une pause sophrologie, chaque jour',
    'site_url'            => '',      // ex. https://sophro.schwaller.fr (détecté automatiquement si vide)
    'notify_label'        => '7 h 30',   // heure affichée sur le bouton de notification (l'heure réelle est celle de la tâche cron)
    'admin_password_hash' => '',      // facultatif : sinon le mot de passe est créé depuis /admin/ et stocké en base
], is_file($__cfgFile) ? (array) require $__cfgFile : []);

function cfg(string $key)
{
    return $GLOBALS['CONFIG'][$key] ?? null;
}

function base_url(): string
{
    $u = (string) cfg('site_url');
    if ($u !== '') {
        return rtrim($u, '/');
    }
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        // tâche cron : on reprend l'adresse vue lors de la dernière visite de l'admin
        return rtrim(setting('site_url') ?: 'https://sophro.schwaller.fr', '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    ensure_dirs();
    $pdo = new PDO('sqlite:' . ROOT . '/data/sophro.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    migrate($pdo);
    return $pdo;
}

/** Crée data/ et uploads/ avec leur protection si besoin (ils ne sont pas envoyés par le déploiement). */
function ensure_dirs(): void
{
    $rules = [
        'data'    => "Require all denied\n",
        'uploads' => "Options -Indexes\n<FilesMatch \"\\.(php\\d?|phtml|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n",
    ];
    foreach ($rules as $dir => $htaccess) {
        $path = ROOT . '/' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
        if (!is_file("$path/.htaccess")) {
            file_put_contents("$path/.htaccess", $htaccess);
        }
    }
}

function migrate(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS contents (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            title         TEXT NOT NULL DEFAULT '',
            body          TEXT NOT NULL DEFAULT '',
            image         TEXT,
            audio         TEXT,
            mode          TEXT NOT NULL DEFAULT 'auto',  -- auto | date | yearly
            publish_date  TEXT,                          -- AAAA-MM-JJ (mode date)
            yearly_start  TEXT,                          -- MM-JJ (mode yearly)
            yearly_end    TEXT,                          -- MM-JJ (mode yearly)
            active        INTEGER NOT NULL DEFAULT 1,
            created_at    TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS daily (
            day        TEXT PRIMARY KEY,                 -- AAAA-MM-JJ
            content_id INTEGER NOT NULL REFERENCES contents(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS daily_content ON daily(content_id);
        CREATE TABLE IF NOT EXISTS push_subscriptions (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            endpoint   TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            last_ok    TEXT
        );
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
    ");
}

function setting(string $key): ?string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT OR REPLACE INTO settings(key, value) VALUES(?, ?)')->execute([$key, $value]);
}

function get_content(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM contents WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Un contenu peut-il être vu publiquement (lien partagé) ? */
function is_public(array $c, ?string $today = null): bool
{
    $today = $today ?? date('Y-m-d');
    if (!(int) $c['active']) {
        return false;
    }
    return $c['mode'] !== 'date' || (string) $c['publish_date'] <= $today;
}

function media_url(?string $file): ?string
{
    return $file ? '/uploads/' . rawurlencode($file) : null;
}

function content_url(array $c): string
{
    return base_url() . '/p/' . (int) $c['id'];
}

function excerpt(string $text, int $len = 180): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if (mb_strlen($text) <= $len) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $len - 1)) . '…';
}

function content_label(array $c): string
{
    if (trim((string) $c['title']) !== '') {
        return (string) $c['title'];
    }
    if (trim((string) $c['body']) !== '') {
        return excerpt((string) $c['body'], 60);
    }
    return $c['audio'] ? 'Séance audio' : 'Image';
}

function content_kind(array $c): string
{
    if ($c['audio']) {
        return 'audio';
    }
    if ($c['image']) {
        return 'image';
    }
    return 'texte';
}

/** Saison du jour (pour le fond d'écran) : printemps, ete, automne ou hiver. */
function season(?string $ymd = null): string
{
    $md = substr($ymd ?? date('Y-m-d'), 5);
    return match (true) {
        $md >= '03-20' && $md < '06-21' => 'printemps',
        $md >= '06-21' && $md < '09-22' => 'ete',
        $md >= '09-22' && $md < '12-21' => 'automne',
        default                         => 'hiver',
    };
}

const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
const MOIS  = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

function date_fr(string $ymd, bool $withDay = true, bool $withYear = false): string
{
    $t = strtotime($ymd . ' 12:00:00');
    $s = (int) date('j', $t) . ($withDay && date('j', $t) === '1' ? 'er' : '') . ' ' . MOIS[(int) date('n', $t)];
    if ($withYear) {
        $s .= ' ' . date('Y', $t);
    }
    return $withDay ? JOURS[(int) date('w', $t)] . ' ' . $s : $s;
}

function md_fr(string $md): string
{
    [$m, $d] = array_map('intval', explode('-', $md));
    return $d . ($d === 1 ? 'er' : '') . ' ' . MOIS[$m];
}
