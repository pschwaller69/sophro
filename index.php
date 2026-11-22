<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/daily.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/push.php';

$today = date('Y-m-d');
$c = null;
$isPermalink = false;

if (isset($_GET['p'])) {
    $c = get_content((int) $_GET['p']);
    if ($c && is_public($c, $today)) {
        $isPermalink = true;
    } else {
        http_response_code(404);
        $c = null;
    }
}
if (!$isPermalink) {
    $c = content_for_day($today);
}
$recent = recent_days($today, 7);

$meta = [];
if ($c) {
    $meta = [
        'title'       => content_label($c),
        'description' => excerpt((string) $c['body'], 200) ?: (string) cfg('site_tagline'),
        'url'         => content_url($c),
        'image'       => og_image_url($c),
        'audio'       => $c['audio'] ? base_url() . media_url($c['audio']) : null,
    ];
    if (!$isPermalink) {
        $meta['title'] = (string) cfg('site_name');
        $meta['url'] = base_url() . '/';
    }
}

try {
    $meta['vapid'] = vapid_public_key();
} catch (Throwable $e) {
    // pas de notifications si OpenSSL ne le permet pas
}
page_head($meta, 'public');
?>
<header class="site-header">
  <a href="/" class="brand"><img src="/assets/icon.svg" alt="" width="36" height="36"><span><?= h(cfg('site_name')) ?></span></a>
  <button type="button" class="btn btn-ghost" id="install-btn" hidden>Installer l'app</button>
</header>

<main class="wrap"<?php if ($c): ?> data-view="<?= (int) $c['id'] ?>" data-view-kind="<?= $isPermalink ? 'link' : 'today' ?>"<?php endif ?>>
  <p class="today-label">
    <?= $isPermalink ? 'Un instant partagé avec vous' : 'L’instant du jour · ' . h(date_fr($today)) ?>
  </p>

  <?php if ($c): ?>
    <?= render_content($c) ?>
    <div class="actions">
      <?= share_button($c) ?>
      <?php if ($isPermalink): ?><a class="btn btn-ghost" href="/">Voir l’instant du jour</a><?php endif ?>
    </div>
  <?php else: ?>
    <article class="card text-only"><div class="card-body">
      <div class="card-text">Prenez le temps de respirer profondément… Le premier instant arrive très bientôt.</div>
    </div></article>
  <?php endif ?>

  <div class="notify" id="notify" hidden>
    <button type="button" class="btn btn-ghost" id="notify-on">🔔 Me prévenir chaque jour à <?= h(cfg('notify_label')) ?></button>
    <p class="muted small" id="notify-state" hidden>🔔 Vous recevez la notification chaque jour à <?= h(cfg('notify_label')) ?>. <button type="button" class="linkish" id="notify-off">Ne plus recevoir</button></p>
    <p class="muted small" id="notify-ios" hidden>Pour recevoir une notification chaque matin sur iPhone, ajoutez d'abord l'application à l'écran d'accueil, puis ouvrez-la depuis son icône.</p>
    <p class="muted small" id="notify-denied" hidden>Les notifications sont bloquées pour ce site dans les réglages du téléphone.</p>
  </div>

  <?php if ($recent): ?>
    <section class="recent">
      <h2>Les jours précédents</h2>
      <ul>
        <?php foreach ($recent as $r): ?>
          <li><a href="/p/<?= (int) $r['id'] ?>">
            <span class="recent-day"><?= h(date_fr($r['day'])) ?></span>
            <span class="recent-title"><?= h(content_label($r)) ?></span>
            <span class="recent-kind kind-<?= content_kind($r) ?>"><?= content_kind($r) ?></span>
          </a></li>
        <?php endforeach ?>
      </ul>
    </section>
  <?php endif ?>


  <p class="center share-app-link"><button type="button" class="linkish" id="share-app">Partager l'application avec un proche</button></p>

  <dialog id="qr-dialog" class="share-dialog qr-dialog">
    <form method="dialog"><button class="close" aria-label="Fermer">×</button></form>
    <h2>Partager l'application</h2>
    <p class="muted small">Faites scanner ce code avec l'appareil photo d'un téléphone :</p>
    <img src="/assets/qr-sophro.svg" alt="QR code vers <?= h(parse_url(base_url(), PHP_URL_HOST)) ?>" width="240" height="240">
    <p class="qr-url"><?= h(parse_url(base_url(), PHP_URL_HOST)) ?></p>
    <div class="share-links">
      <button type="button" data-share-app data-url="<?= h(base_url()) ?>/" data-title="<?= h(cfg('site_name')) ?>" data-text="<?= h(cfg('site_tagline')) ?> : une phrase, une image ou une mini-séance de sophrologie chaque jour.">Envoyer le lien</button>
      <a href="/assets/qr-sophro.png" download="qr-sophro-du-jour.png" class="qr-download">Télécharger le QR code</a>
    </div>
  </dialog>

  <p class="ios-hint" id="ios-hint" hidden>
    Pour installer l'application sur iPhone : touchez <strong>Partager</strong> puis <strong>Sur l'écran d'accueil</strong>.
  </p>
</main>
<?php page_foot();
