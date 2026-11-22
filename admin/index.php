<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require ROOT . '/lib/auth.php';
require ROOT . '/lib/daily.php';
require ROOT . '/lib/layout.php';

$error = null;
$setup = !password_is_set();
if ($setup && ($_POST['action'] ?? '') === 'setup') {
    $error = set_password((string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''));
    if (!$error) {
        login_session();
        header('Location: /admin/?welcome=1');
        exit;
    }
}
if (!$setup && ($_POST['action'] ?? '') === 'login') {
    if (try_login((string) ($_POST['password'] ?? ''))) {
        header('Location: /admin/');
        exit;
    }
    $error = 'Mot de passe incorrect.';
}

if (!is_admin()) {
    page_head(['title' => 'Administration', 'noindex' => true], 'admin');
    ?>
    <main class="wrap narrow">
      <h1 class="admin-title">Espace sophrologue</h1>
      <form method="post" class="form card"><div class="card-body">
        <?php if ($error): ?><p class="alert error"><?= h($error) ?></p><?php endif ?>
        <?php if ($setup): ?>
          <input type="hidden" name="action" value="setup">
          <p>Bienvenue ! Choisissez le mot de passe de l'administration (8 caractères minimum).</p>
          <label>Nouveau mot de passe
            <input type="password" name="password" autocomplete="new-password" minlength="8" required autofocus>
          </label>
          <label>Confirmez le mot de passe
            <input type="password" name="confirm" autocomplete="new-password" minlength="8" required>
          </label>
          <button class="btn">Créer le mot de passe</button>
        <?php else: ?>
          <input type="hidden" name="action" value="login">
          <label>Mot de passe
            <input type="password" name="password" autocomplete="current-password" required autofocus>
          </label>
          <button class="btn">Se connecter</button>
        <?php endif ?>
      </div></form>
      <p class="center"><a href="/">← Retour au site</a></p>
    </main>
    <?php
    page_foot();
    exit;
}

if (setting('site_url') !== base_url()) {
    set_setting('site_url', base_url()); // utilisé par la tâche cron pour construire les liens
}

$today = date('Y-m-d');
$todayContent = content_for_day($today);

// Les 14 prochains jours
$upcoming = [];
for ($i = 1; $i <= 14; $i++) {
    $d = date('Y-m-d', strtotime("$today +$i day"));
    [$kind] = forced_for_day($d);
    // Le tirage est fait à l'avance et mémorisé : ce qui est affiché ici sera bien publié ce jour-là
    // (il est refait si un contenu est ajouté, modifié ou supprimé entre-temps).
    $upcoming[] = [$d, $kind, content_for_day($d)];
}

$filter = $_GET['f'] ?? 'all';
$where = match ($filter) {
    'auto'   => "WHERE mode = 'auto'",
    'date'   => "WHERE mode = 'date'",
    'yearly' => "WHERE mode = 'yearly'",
    'weekday' => "WHERE mode = 'weekday'",
    'draft'  => 'WHERE active = 0',
    default  => '',
};
$order = match ($filter) {
    'date'   => 'c.publish_date ASC, c.id',
    'yearly' => 'c.yearly_start ASC, c.yearly_end ASC, c.id',
    'weekday' => 'c.weekdays ASC, c.id',
    default  => 'c.id DESC',
};
$contents = db()->query("SELECT c.*, (SELECT COUNT(*) FROM daily WHERE content_id = c.id AND day <= '$today') AS shown,
                                (SELECT MAX(day) FROM daily WHERE content_id = c.id AND day <= '$today') AS last_shown
                         FROM contents c $where ORDER BY $order")->fetchAll();
$counts = db()->query("SELECT COUNT(*) total, SUM(mode='auto') auto, SUM(mode='date') date, SUM(mode='yearly') yearly, SUM(mode='weekday') weekday, SUM(active=0) draft FROM contents")->fetch();

function mode_label(array $c): string
{
    return match ($c['mode']) {
        'date'   => 'Le ' . date_fr((string) $c['publish_date'], false, true),
        'yearly' => $c['yearly_start'] === $c['yearly_end']
            ? 'Chaque année le ' . md_fr((string) $c['yearly_start'])
            : 'Chaque année du ' . md_fr((string) $c['yearly_start']) . ' au ' . md_fr((string) $c['yearly_end']),
        'weekday' => 'Chaque ' . weekdays_fr(weekdays_of($c)),
        default  => 'Au hasard',
    };
}

page_head(['title' => 'Administration', 'noindex' => true], 'admin');
?>
<header class="site-header">
  <a href="/admin/" class="brand"><img src="/assets/icon.svg" alt="" width="36" height="36"><span>Administration</span></a>
  <nav><a href="/" target="_blank">Voir le site</a> · <a href="/admin/social.php">Réseaux &amp; notifications</a> · <a href="/admin/password.php">Mot de passe</a> · <a href="/admin/logout.php">Déconnexion</a></nav>
</header>

<main class="wrap wide">
  <?php if (isset($_GET['welcome'])): ?><p class="alert ok">Mot de passe créé ✔ Vous pouvez maintenant ajouter vos instants.</p><?php endif ?>
  <?php if (isset($_GET['pw'])): ?><p class="alert ok">Mot de passe modifié ✔</p><?php endif ?>
  <?php if (isset($_GET['ok'])): ?><p class="alert ok">Enregistré ✔</p><?php endif ?>
  <?php if (isset($_GET['deleted'])): ?><p class="alert ok">Instant supprimé.</p><?php endif ?>

  <div class="admin-grid">
    <section>
      <h2>L’instant d’aujourd’hui, <?= h(date_fr($today)) ?></h2>
      <?php if ($todayContent): ?>
        <?= render_content($todayContent) ?>
        <div class="actions">
          <?= share_button($todayContent) ?>
          <a class="btn btn-ghost" href="/admin/edit.php?id=<?= (int) $todayContent['id'] ?>">Modifier</a>
        </div>
      <?php else: ?>
        <p class="muted">Aucun instant pour le moment. Créez le premier !</p>
      <?php endif ?>

      <h2>Les 14 prochains jours</h2>
      <ul class="upcoming">
        <?php foreach ($upcoming as [$d, $kind, $uc]): ?>
          <li class="up-<?= $uc ? h($uc['mode']) : 'auto' ?>">
            <span class="up-day"><?= h(date_fr($d)) ?></span>
            <?php if (!$uc): ?>
              <span class="muted">Aucun instant disponible</span>
            <?php else: ?>
              <div class="up-content">
                <a href="/admin/edit.php?id=<?= (int) $uc['id'] ?>" class="up-title"><?= h(content_label($uc)) ?></a>
                <?php if ($uc['mode'] !== 'auto'): ?><span class="badge mode-<?= h($uc['mode']) ?>"><?= h(match ($uc['mode']) { 'date' => 'Date précise', 'weekday' => 'Chaque ' . weekdays_fr(weekdays_of($uc)), default => 'Chaque année' }) ?></span><?php endif ?>
                <?php if ($uc['image']): ?><span class="badge">image</span><?php endif ?>
                <?php if ($uc['audio']): ?><span class="badge">audio</span><?php endif ?>
                <?php if (trim((string) $uc['body']) !== '' && trim((string) $uc['title']) !== ''): ?>
                  <span class="up-text"><?= h(excerpt((string) $uc['body'], 160)) ?></span>
                <?php endif ?>
              </div>
            <?php endif ?>
          </li>
        <?php endforeach ?>
      </ul>
    </section>

    <section>
      <div class="list-head">
        <h2>Mes instants (<?= (int) $counts['total'] ?>)</h2>
        <span><a class="btn btn-ghost" href="/admin/import.php">Importer</a> <a class="btn" href="/admin/edit.php">+ Nouvel instant</a></span>
      </div>
      <nav class="filters">
        <?php foreach (['all' => 'Tous', 'auto' => 'Au hasard', 'date' => 'Date précise', 'yearly' => 'Chaque année', 'weekday' => 'Jour de la semaine', 'draft' => 'Brouillons'] as $k => $lbl): ?>
          <a href="?f=<?= $k ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= $lbl ?><?php if ($k !== 'all'): ?> <small><?= (int) $counts[$k] ?></small><?php endif ?></a>
        <?php endforeach ?>
      </nav>
      <ul class="content-list">
        <?php foreach ($contents as $c): ?>
          <li class="<?= $c['active'] ? '' : 'draft' ?>">
            <a class="thumb kind-<?= content_kind($c) ?>" href="/admin/edit.php?id=<?= (int) $c['id'] ?>">
              <?php if ($c['image']): ?><img src="<?= h(media_url($c['image'])) ?>" alt="" loading="lazy">
              <?php else: ?><span><?= content_kind($c) === 'audio' ? '♪' : '“' ?></span><?php endif ?>
            </a>
            <div class="info">
              <a href="/admin/edit.php?id=<?= (int) $c['id'] ?>" class="info-title"><?= h(content_label($c)) ?></a>
              <span class="badge mode-<?= h($c['mode']) ?>"><?= h(mode_label($c)) ?></span>
              <?php if (!$c['active']): ?><span class="badge draft">Brouillon</span><?php endif ?>
              <span class="muted small"><?= $c['shown'] ? 'Montré ' . (int) $c['shown'] . ' fois, dernière fois le ' . h(date_fr((string) $c['last_shown'], false, true)) : 'Jamais montré' ?></span>
            </div>
            <div class="row-actions">
              <?php if (is_public($c)): ?><?= share_button($c, '', 'icon-btn') ?><?php endif ?>
            </div>
          </li>
        <?php endforeach ?>
        <?php if (!$contents): ?><li class="muted">Rien ici pour le moment.</li><?php endif ?>
      </ul>
    </section>
  </div>
</main>
<?php page_foot();
