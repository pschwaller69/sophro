<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require ROOT . '/lib/auth.php';
require ROOT . '/lib/layout.php';

require_admin();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (cfg('admin_password_hash')) {
        $error = 'Le mot de passe est fixé dans config.local.php sur le serveur et ne peut pas être changé ici.';
    } elseif (!password_verify((string) ($_POST['current'] ?? ''), admin_hash())) {
        usleep(800000);
        $error = 'Le mot de passe actuel est incorrect.';
    } else {
        $error = set_password((string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''));
        if (!$error) {
            header('Location: /admin/?pw=1');
            exit;
        }
    }
}

page_head(['title' => 'Mot de passe', 'noindex' => true], 'admin');
?>
<header class="site-header">
  <a href="/admin/" class="brand"><img src="/assets/icon.svg" alt="" width="36" height="36"><span>Administration</span></a>
  <nav><a href="/admin/">← Retour</a></nav>
</header>
<main class="wrap narrow">
  <h1 class="admin-title">Changer le mot de passe</h1>
  <form method="post" class="form card"><div class="card-body">
    <?= csrf_field() ?>
    <?php if ($error): ?><p class="alert error"><?= h($error) ?></p><?php endif ?>
    <label>Mot de passe actuel
      <input type="password" name="current" autocomplete="current-password" required>
    </label>
    <label>Nouveau mot de passe <span class="muted">(8 caractères minimum)</span>
      <input type="password" name="password" autocomplete="new-password" minlength="8" required>
    </label>
    <label>Confirmez le nouveau mot de passe
      <input type="password" name="confirm" autocomplete="new-password" minlength="8" required>
    </label>
    <button class="btn">Enregistrer</button>
  </div></form>
</main>
<?php page_foot();
