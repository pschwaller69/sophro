<?php
declare(strict_types=1);

/** En-tête HTML commun. $meta : title, description, url, image, audio, noindex */
function page_head(array $meta = [], string $bodyClass = ''): void
{
    $site  = (string) cfg('site_name');
    $title = $meta['title'] ?? $site;
    $desc  = $meta['description'] ?? (string) cfg('site_tagline');
    $url   = $meta['url'] ?? base_url() . '/';
    $image = $meta['image'] ?? base_url() . '/assets/og-default.png';
    $v = asset_version();
    ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($title === $site ? $site : "$title · $site") ?></title>
<meta name="description" content="<?= h($desc) ?>">
<?php if (!empty($meta['noindex'])): ?><meta name="robots" content="noindex"><?php endif ?>
<link rel="canonical" href="<?= h($url) ?>">
<meta property="og:type" content="article">
<meta property="og:site_name" content="<?= h($site) ?>">
<meta property="og:locale" content="fr_FR">
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h($desc) ?>">
<meta property="og:url" content="<?= h($url) ?>">
<meta property="og:image" content="<?= h($image) ?>">
<?php if (!empty($meta['audio'])): ?><meta property="og:audio" content="<?= h($meta['audio']) ?>"><?php endif ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#5f7f6e">
<link rel="manifest" href="/manifest.webmanifest">
<?php if (!empty($meta['vapid'])): ?><meta name="vapid-key" content="<?= h($meta['vapid']) ?>"><?php endif ?>
<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/icon-180.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h($site) ?>">
<link rel="stylesheet" href="/assets/style.css?v=<?= $v ?>">
<script src="/assets/app.js?v=<?= $v ?>" defer></script>
</head>
<body class="<?= h($bodyClass) ?> season-<?= h(current_season()) ?>">
<?php if (str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/admin')): ?><div class="app-version"><?= h(app_version()) ?></div><?php endif ?>
<?php
}

/** Saison affichée ; ?saison=hiver (etc.) permet de prévisualiser les autres fonds. */
function current_season(): string
{
    $preview = $_GET['saison'] ?? '';
    return in_array($preview, ['printemps', 'ete', 'automne', 'hiver'], true) ? $preview : season();
}

/** Version affichée en bas de l'admin : fichier VERSION + commit Git récupéré par o2switch. */
function app_version(): string
{
    $v = trim((string) @file_get_contents(ROOT . '/VERSION')) ?: '?';
    $head = trim((string) @file_get_contents(ROOT . '/.git/HEAD'));
    $hash = '';
    if (str_starts_with($head, 'ref: ')) {
        $ref = substr($head, 5);
        $hash = trim((string) @file_get_contents(ROOT . '/.git/' . $ref));
        if ($hash === '' && is_file(ROOT . '/.git/packed-refs')) {
            foreach (file(ROOT . '/.git/packed-refs') as $l) {
                if (str_ends_with(trim($l), ' ' . $ref)) {
                    $hash = strtok($l, ' ');
                }
            }
        }
    } elseif (preg_match('/^[0-9a-f]{40}$/', $head)) {
        $hash = $head;
    }
    return 'Version ' . $v . ($hash ? ' · ' . substr($hash, 0, 7) : '');
}

function page_foot(): void
{
    ?>
<dialog id="share-dialog" class="share-dialog">
  <form method="dialog"><button class="close" aria-label="Fermer">×</button></form>
  <h2>Partager</h2>
  <div class="share-links">
    <a data-net="whatsapp" target="_blank" rel="noopener">WhatsApp</a>
    <a data-net="facebook" target="_blank" rel="noopener">Facebook</a>
    <a data-net="messenger" target="_blank" rel="noopener">Messenger</a>
    <a data-net="x" target="_blank" rel="noopener">X (Twitter)</a>
    <a data-net="linkedin" target="_blank" rel="noopener">LinkedIn</a>
    <a data-net="email">E-mail</a>
    <button type="button" data-net="copy">Copier le lien</button>
  </div>
</dialog>
</body>
</html>
<?php
}

function asset_version(): string
{
    static $v = null;
    return $v ??= substr(md5((string) @filemtime(ROOT . '/assets/style.css') . (string) @filemtime(ROOT . '/assets/app.js')), 0, 8);
}

/** Bouton de partage : Web Share API sur mobile, sinon menu de réseaux sociaux. */
function share_button(array $c, string $label = 'Partager', string $class = 'btn btn-share'): string
{
    $text = trim((string) $c['title'] !== '' ? $c['title'] : excerpt((string) $c['body'], 200));
    return sprintf(
        '<button type="button" class="%s" data-share data-url="%s" data-title="%s" data-text="%s"%s>%s %s</button>',
        h($class),
        h(content_url($c)),
        h(content_label($c)),
        h($text),
        $c['image'] ? ' data-image="' . h(media_url($c['image'])) . '"' : '',
        '<svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M18 16a3 3 0 0 0-2.4 1.2l-6.7-3.4a3 3 0 0 0 0-1.6l6.7-3.4A3 3 0 1 0 15 7l-6.7 3.4a3 3 0 1 0 0 3.2L15 17a3 3 0 1 0 3-1Z"/></svg>',
        h($label)
    );
}

/** Affichage d'un contenu (texte, image, audio, ou combinaison). */
function render_content(array $c): string
{
    ob_start();
    $hasMedia = $c['image'] || $c['audio'];
    ?>
<article class="card kind-<?= content_kind($c) ?><?= $hasMedia ? '' : ' text-only' ?>">
  <?php if ($c['image']): ?>
    <img class="card-image" src="<?= h(media_url($c['image'])) ?>" alt="<?= h(content_label($c)) ?>">
  <?php endif ?>
  <div class="card-body">
    <?php if (trim((string) $c['title']) !== ''): ?><h2 class="card-title"><?= h($c['title']) ?></h2><?php endif ?>
    <?php if ($c['audio']): ?>
      <audio class="card-audio" controls preload="metadata" src="<?= h(media_url($c['audio'])) ?>"></audio>
    <?php endif ?>
    <?php if (trim((string) $c['body']) !== ''): ?>
      <div class="card-text"><?= nl2br(h(trim((string) $c['body']))) ?></div>
    <?php endif ?>
  </div>
</article>
<?php
    return (string) ob_get_clean();
}

/** Image Open Graph : l'image du contenu, ou une carte générée avec le texte. */
function og_image_url(array $c): string
{
    if ($c['image']) {
        return base_url() . media_url($c['image']);
    }
    return base_url() . '/og.php?p=' . (int) $c['id'] . '&v=' . substr(md5($c['updated_at']), 0, 6);
}
