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

$today = date('Y-m-d');
$todayContent = content_for_day($today);

// Les 14 prochains jours
$upcoming = [];
for ($i = 1; $i <= 14; $i++) {
    $d = date('Y-m-d', strtotime("$today +$i day"));
    [$kind, $rows] = forced_for_day($d);
    $upcoming[] = [$d, $kind, $rows];
}

$filter = $_GET['f'] ?? 'all';
$where = match ($filter) {
    'auto'   => "WHERE mode = 'auto'",
    'date'   => "WHERE mode = 'date'",
    'yearly' => "WHERE mode = 'yearly'",
    'draft'  => 'WHERE active = 0',
    default  => '',
};
$contents = db()->query("SELECT c.*, (SELECT COUNT(*) FROM daily WHERE content_id = c.id) AS shown,
                                (SELECT MAX(day) FROM daily WHERE content_id = c.id) AS last_shown
                         FROM contents c $where ORDER BY c.id DESC")->fetchAll();
$counts = db()->query("SELECT COUNT(*) total, SUM(mode='auto') auto, SUM(mode='date') date, SUM(mode='yearly') yearly, SUM(active=0) draft FROM contents")->fetch();

function mode_label(array $c): string
{
    return match ($c['mode']) {
        'date'   => 'Le ' . date_fr((string) $c['publish_date'], false, true),
        'yearly' => 'Chaque année du ' . md_fr((string) $c['yearly_start']) . ' au ' . md_fr((string) $c['yearly_end']),
        default  => 'Au hasard',
    };
}

page_head(['title' => 'Administration', 'noindex' => true], 'admin');
?>
<header class="site-header">
  <a href="/admin/" class="brand"><img src="/assets/icon.svg" alt="" width="36" height="36"><span>Administration</span></a>
  <nav><a href="/" target="_blank">Voir le site</a> · <a href="/admin/password.php">Mot de passe</a> · <a href="/admin/logout.php">Déconnexion</a></nav>
</header>

<main class="wrap wide">
  <?php if (isset($_GET['welcome'])): ?><p class="alert ok">Mot de passe créé ✔ Vous pouvez maintenant ajouter vos contenus.</p><?php endif ?>
  <?php if (isset($_GET['pw'])): ?><p class="alert ok">Mot de passe modifié ✔</p><?php endif ?>
  <?php if (isset($_GET['ok'])): ?><p class="alert ok">Enregistré ✔</p><?php endif ?>
  <?php if (isset($_GET['deleted'])): ?><p class="alert ok">Contenu supprimé.</p><?php endif ?>

  <div class="admin-grid">
    <section>
      <h2>Aujourd'hui, <?= h(date_fr($today)) ?></h2>
      <?php if ($todayContent): ?>
        <?= render_content($todayContent) ?>
        <div class="actions">
          <?= share_button($todayContent) ?>
          <a class="btn btn-ghost" href="/admin/edit.php?id=<?= (int) $todayContent['id'] ?>">Modifier</a>
        </div>
      <?php else: ?>
        <p class="muted">Aucun contenu pour l'instant. Créez le premier !</p>
      <?php endif ?>

      <h2>Les 14 prochains jours</h2>
      <ul class="upcoming">
        <?php foreach ($upcoming as [$d, $kind, $rows]): ?>
          <li class="up-<?= $kind ?>">
            <span class="up-day"><?= h(date_fr($d)) ?></span>
            <?php if ($kind === 'random'): ?>
              <span class="muted">Tirage au hasard</span>
            <?php else: ?>
              <?= $kind === 'yearly' ? '<span class="muted">Saison :</span> ' : '' ?><a href="/admin/edit.php?id=<?= (int) $rows[0]['id'] ?>"><?= h(content_label($rows[0])) ?></a>
              <?php if (count($rows) > 1): ?><span class="muted"><?= $kind === 'yearly' ? '(+' . (count($rows) - 1) . ' au choix)' : '(+' . (count($rows) - 1) . ' autre(s) le même jour)' ?></span><?php endif ?>
            <?php endif ?>
          </li>
        <?php endforeach ?>
      </ul>
    </section>

    <section>
      <div class="list-head">
        <h2>Contenus (<?= (int) $counts['total'] ?>)</h2>
        <a class="btn" href="/admin/edit.php">+ Nouveau contenu</a>
      </div>
      <nav class="filters">
        <?php foreach (['all' => 'Tous', 'auto' => 'Au hasard', 'date' => 'Date précise', 'yearly' => 'Chaque année', 'draft' => 'Brouillons'] as $k => $lbl): ?>
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
