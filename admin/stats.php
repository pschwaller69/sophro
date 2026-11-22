<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require ROOT . '/lib/auth.php';
require ROOT . '/lib/layout.php';
require ROOT . '/lib/push.php';
require ROOT . '/lib/stats.php';

require_admin();

$today = date('Y-m-d');
$days = daily_stats(30);
$max = max(1, ...array_map(fn($d) => (int) $d['people'], $days));
$tiles = [
    ['Aujourd’hui', visitors_between($today, $today)],
    ['7 derniers jours', visitors_between(date('Y-m-d', strtotime('-6 days')), $today)],
    ['30 derniers jours', visitors_between(date('Y-m-d', strtotime('-29 days')), $today)],
    ['Avec l’appli installée', visitors_between(date('Y-m-d', strtotime('-29 days')), $today, 'app')],
    ['Abonnés aux notifications', subscription_count()],
];
$shared = top_shared(30);
$since = db()->query('SELECT MIN(day) FROM views')->fetchColumn();

page_head(['title' => 'Statistiques', 'noindex' => true], 'admin');
?>
<header class="site-header">
  <a href="/admin/" class="brand"><img src="/assets/icon.svg" alt="" width="36" height="36"><span>Administration</span></a>
  <nav><a href="/admin/">← Retour</a></nav>
</header>

<main class="wrap">
  <h1 class="admin-title">Statistiques</h1>
  <p class="muted small">Nombre de personnes (téléphones ou ordinateurs) différentes qui ont ouvert l’application.
    Vos propres visites quand vous êtes connectée à l’administration ne sont pas comptées.
    <?= $since ? 'Comptage depuis le ' . h(date_fr((string) $since, false, true)) . '.' : 'Le comptage commence maintenant.' ?></p>

  <div class="stat-tiles">
    <?php foreach ($tiles as [$label, $n]): ?>
      <div class="stat-tile"><strong><?= (int) $n ?></strong><span><?= h($label) ?></span></div>
    <?php endforeach ?>
  </div>

  <h2>Jour par jour</h2>
  <ul class="stat-days">
    <?php foreach ($days as $d): ?>
      <li>
        <div class="sd-head">
          <span class="sd-day"><?= $d['day'] === $today ? 'Aujourd’hui' : h(date_fr($d['day'])) ?></span>
          <strong class="sd-n"><?= (int) $d['people'] ?> <?= (int) $d['people'] > 1 ? 'personnes' : 'personne' ?></strong>
        </div>
        <div class="sd-bar"><span style="width:<?= round(100 * (int) $d['people'] / $max) ?>%"></span></div>
        <div class="sd-detail small muted">
          <?php if ($d['content']): ?>
            <a href="/admin/edit.php?id=<?= (int) $d['content']['id'] ?>"><?= h(content_label($d['content'])) ?></a> :
            vu par <?= (int) $d['today_people'] ?>
            <?php $more = array_filter([
                $d['app_people'] ? $d['app_people'] . ' depuis l’appli' : '',
                $d['notif_people'] ? $d['notif_people'] . ' via la notification' : '',
            ]); ?>
            <?= $more ? '(dont ' . h(implode(', ', $more)) . ')' : '' ?>
          <?php else: ?>Aucun instant ce jour-là<?php endif ?>
          <?php if ($d['link_people']): ?> · <?= (int) $d['link_people'] ?> par un lien partagé<?php endif ?>
        </div>
      </li>
    <?php endforeach ?>
  </ul>

  <?php if ($shared): ?>
    <h2>Les instants les plus partagés <span class="muted small">(liens ouverts, 30 derniers jours)</span></h2>
    <ul class="stat-days">
      <?php foreach ($shared as $s): ?>
        <li class="sd-head"><a href="/admin/edit.php?id=<?= (int) $s['id'] ?>"><?= h(content_label($s)) ?></a><strong class="sd-n"><?= (int) $s['opens'] ?></strong></li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>

  <p class="muted small">Aucun traceur publicitaire ni service extérieur : chaque appareil reçoit un numéro au hasard, gardé sur le téléphone, et les données sont effacées au bout de 13 mois.
    Les vues sur Facebook et Instagram se consultent dans les statistiques de la Page (Meta Business Suite).</p>
</main>
<?php page_foot();
