<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require ROOT . '/lib/auth.php';
require ROOT . '/lib/daily.php';
require ROOT . '/lib/push.php';
require ROOT . '/lib/social.php';
require ROOT . '/lib/layout.php';

require_admin();

$msg = null;
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['action'] ?? '') === 'save') {
        foreach (['fb_page_id', 'ig_user_id'] as $k) {
            set_setting($k, preg_replace('/\D/', '', (string) ($_POST[$k] ?? '')));
        }
        $token = trim((string) ($_POST['fb_token'] ?? ''));
        if ($token !== '') {
            set_setting('fb_token', $token);
        }
        if (isset($_POST['forget_token'])) {
            set_setting('fb_token', '');
        }
        set_setting('fb_enabled', isset($_POST['fb_enabled']) ? '1' : '0');
        set_setting('ig_enabled', isset($_POST['ig_enabled']) ? '1' : '0');
        $msg = 'Réglages enregistrés ✔';
    } elseif (($_POST['action'] ?? '') === 'post_now') {
        $c = content_for_day(date('Y-m-d'));
        $result = $c ? post_to_socials($c) : ['Aucun contenu aujourd\'hui.'];
    }
}

$hasToken = (string) setting('fb_token') !== '';
$log = array_reverse(array_filter(explode("\n", (string) setting('social_log'))));

page_head(['title' => 'Réseaux sociaux', 'noindex' => true], 'admin');
?>
<header class="site-header">
  <a href="/admin/" class="brand"><img src="/assets/icon.svg" alt="" width="36" height="36"><span>Administration</span></a>
  <nav><a href="/admin/">← Retour</a></nav>
</header>
<main class="wrap">
  <h1 class="admin-title">Notifications et réseaux sociaux</h1>
  <?php if ($msg): ?><p class="alert ok"><?= h($msg) ?></p><?php endif ?>
  <?php if ($result): ?><p class="alert <?= str_contains(implode(' ', $result), 'ERREUR') ? 'error' : 'ok' ?>"><?= h(implode(' · ', $result)) ?></p><?php endif ?>

  <section class="card"><div class="card-body">
    <h2>Notifications</h2>
    <p><strong><?= subscription_count() ?></strong> appareil(s) reçoivent la notification de <?= h(cfg('notify_label')) ?>.</p>
    <p class="muted small">Dernier envoi : <?= h(setting('push_day') ? date_fr((string) setting('push_day'), true, true) : 'jamais') ?>.
      Pour la recevoir vous-même, ouvrez l'application sur votre téléphone et touchez « Me prévenir chaque jour ».</p>
  </div></section>

  <form method="post" class="form card" style="margin-top:20px"><div class="card-body">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <h2>Publication automatique</h2>
    <p class="muted small">Chaque matin, le contenu du jour est publié sur les réseaux cochés ci-dessous (texte + image ; pour un texte seul, une image est créée automatiquement).</p>

    <fieldset>
      <legend>Page Facebook</legend>
      <label class="inline"><input type="checkbox" name="fb_enabled" <?= setting('fb_enabled') === '1' ? 'checked' : '' ?>> Publier chaque jour sur la page</label>
      <label>Identifiant de la page
        <input type="text" name="fb_page_id" inputmode="numeric" value="<?= h(setting('fb_page_id')) ?>" placeholder="ex. 123456789012345">
      </label>
    </fieldset>

    <fieldset>
      <legend>Instagram (compte professionnel ou créateur relié à la page)</legend>
      <label class="inline"><input type="checkbox" name="ig_enabled" <?= setting('ig_enabled') === '1' ? 'checked' : '' ?>> Publier chaque jour sur Instagram</label>
      <label>Identifiant du compte Instagram professionnel
        <input type="text" name="ig_user_id" inputmode="numeric" value="<?= h(setting('ig_user_id')) ?>" placeholder="ex. 17841400000000000">
      </label>
    </fieldset>

    <fieldset>
      <legend>Jeton d'accès de la page (Meta)</legend>
      <p class="muted small"><?= $hasToken ? 'Un jeton est enregistré ✔ (laissez vide pour le garder).' : 'Aucun jeton enregistré.' ?></p>
      <input type="password" name="fb_token" autocomplete="off" placeholder="Coller ici le jeton de la page">
      <?php if ($hasToken): ?><label class="inline"><input type="checkbox" name="forget_token"> Supprimer le jeton enregistré</label><?php endif ?>
    </fieldset>

    <div class="actions"><button class="btn">Enregistrer</button></div>
  </div></form>

  <form method="post" class="center" onsubmit="return confirm('Publier maintenant le contenu du jour sur les réseaux activés ?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="post_now">
    <button class="btn btn-ghost">Publier le contenu du jour maintenant</button>
  </form>

  <?php if ($log): ?>
    <h2>Historique des publications</h2>
    <ul class="upcoming">
      <?php foreach ($log as $l): ?><li class="small"><?= h($l) ?></li><?php endforeach ?>
    </ul>
  <?php endif ?>
</main>
<?php page_foot();
