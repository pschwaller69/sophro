<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require ROOT . '/lib/auth.php';
require ROOT . '/lib/daily.php';
require ROOT . '/lib/upload.php';
require ROOT . '/lib/layout.php';

require_admin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$c = $id ? get_content($id) : null;
if ($id && !$c) {
    http_response_code(404);
    exit('Contenu introuvable.');
}
$c = $c ?? [
    'id' => 0, 'title' => '', 'body' => '', 'image' => null, 'audio' => null,
    'mode' => 'auto', 'publish_date' => null, 'yearly_start' => '12-20', 'yearly_end' => '12-31', 'active' => 1,
];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if (($_POST['action'] ?? '') === 'delete' && $c['id']) {
        delete_media($c['image']);
        delete_media($c['audio']);
        db()->prepare('DELETE FROM contents WHERE id = ?')->execute([$c['id']]);
        header('Location: /admin/?deleted=1');
        exit;
    }

    $new = [
        'title'  => trim((string) ($_POST['title'] ?? '')),
        'body'   => trim((string) ($_POST['body'] ?? '')),
        'mode'   => in_array($_POST['mode'] ?? '', ['auto', 'date', 'yearly'], true) ? $_POST['mode'] : 'auto',
        'active' => isset($_POST['active']) ? 1 : 0,
        'publish_date' => null, 'yearly_start' => null, 'yearly_end' => null,
        'image' => $c['image'], 'audio' => $c['audio'],
    ];
    $md = fn(string $p) => sprintf('%02d-%02d', (int) ($_POST[$p . '_m'] ?? 1), (int) ($_POST[$p . '_d'] ?? 1));

    try {
        if ($new['mode'] === 'date') {
            $d = (string) ($_POST['publish_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                throw new RuntimeException('Choisissez la date de publication.');
            }
            if (isset($_POST['repeat'])) {
                // « Répéter chaque année » : devient un contenu annuel sur ce seul jour
                $new['mode'] = 'yearly';
                $new['yearly_start'] = $new['yearly_end'] = substr($d, 5);
            } else {
                $new['publish_date'] = $d;
            }
        } elseif ($new['mode'] === 'yearly') {
            $new['yearly_start'] = $md('ys');
            $new['yearly_end'] = $md('ye');
        }

        $uploadedImage = handle_upload('image', 'image');
        $uploadedAudio = handle_upload('audio', 'audio');
        if ($uploadedImage || isset($_POST['remove_image'])) {
            delete_media($c['image']);
            $new['image'] = $uploadedImage;
        }
        if ($uploadedAudio || isset($_POST['remove_audio'])) {
            delete_media($c['audio']);
            $new['audio'] = $uploadedAudio;
        }
        if ($new['title'] === '' && $new['body'] === '' && !$new['image'] && !$new['audio']) {
            throw new RuntimeException('Le contenu est vide : ajoutez au moins un texte, une image ou un audio.');
        }

        $cols = ['title', 'body', 'image', 'audio', 'mode', 'publish_date', 'yearly_start', 'yearly_end', 'active'];
        $vals = array_map(fn($k) => $new[$k], $cols);
        if ($c['id']) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", $cols));
            db()->prepare("UPDATE contents SET $set, updated_at = datetime('now') WHERE id = ?")->execute([...$vals, $c['id']]);
            $savedId = (int) $c['id'];
        } else {
            $ph = implode(', ', array_fill(0, count($cols), '?'));
            db()->prepare('INSERT INTO contents (' . implode(', ', $cols) . ") VALUES ($ph)")->execute($vals);
            $savedId = (int) db()->lastInsertId();
        }
        reset_today_if_needed(get_content($savedId));
        header('Location: /admin/?ok=1');
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        $c = array_merge($c, $new, ['publish_date' => $_POST['publish_date'] ?? null]);
        if ($new['mode'] === 'yearly') {
            $c['yearly_start'] = $md('ys');
            $c['yearly_end'] = $md('ye');
        }
    }
}

function md_select(string $prefix, ?string $md): string
{
    [$m, $d] = array_map('intval', explode('-', $md ?: '01-01'));
    $out = '<select name="' . $prefix . '_d" aria-label="Jour">';
    for ($i = 1; $i <= 31; $i++) {
        $out .= '<option value="' . $i . '"' . ($i === $d ? ' selected' : '') . '>' . $i . '</option>';
    }
    $out .= '</select><select name="' . $prefix . '_m" aria-label="Mois">';
    for ($i = 1; $i <= 12; $i++) {
        $out .= '<option value="' . $i . '"' . ($i === $m ? ' selected' : '') . '>' . MOIS[$i] . '</option>';
    }
    return $out . '</select>';
}

page_head(['title' => $c['id'] ? 'Modifier' : 'Nouveau contenu', 'noindex' => true], 'admin');
?>
<header class="site-header">
  <a href="/admin/" class="brand"><img src="/assets/icon.svg" alt="" width="36" height="36"><span>Administration</span></a>
  <nav><a href="/admin/">← Retour</a></nav>
</header>

<main class="wrap">
  <h1 class="admin-title"><?= $c['id'] ? 'Modifier le contenu' : 'Nouveau contenu' ?></h1>
  <?php if ($error): ?><p class="alert error"><?= h($error) ?></p><?php endif ?>

  <form method="post" enctype="multipart/form-data" class="form card" id="edit-form"><div class="card-body">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">

    <label>Titre <span class="muted">(facultatif)</span>
      <input type="text" name="title" value="<?= h($c['title']) ?>" maxlength="150" placeholder="Ex. : Respiration du matin">
    </label>

    <label>Texte <span class="muted">(une phrase, une consigne, une pensée…)</span>
      <textarea name="body" rows="6" placeholder="Inspirez profondément…"><?= h($c['body']) ?></textarea>
    </label>

    <fieldset>
      <legend>Image <span class="muted">(facultatif)</span></legend>
      <?php if ($c['image']): ?>
        <img src="<?= h(media_url($c['image'])) ?>" alt="" class="preview">
        <label class="inline"><input type="checkbox" name="remove_image"> Retirer l'image</label>
      <?php endif ?>
      <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
    </fieldset>

    <fieldset>
      <legend>Audio — mini-séance <span class="muted">(facultatif, MP3 ou M4A)</span></legend>
      <?php if ($c['audio']): ?>
        <audio controls preload="metadata" src="<?= h(media_url($c['audio'])) ?>"></audio>
        <label class="inline"><input type="checkbox" name="remove_audio"> Retirer l'audio</label>
      <?php endif ?>
      <input type="file" name="audio" accept="audio/*,.mp3,.m4a">
    </fieldset>

    <fieldset class="modes">
      <legend>Quand le montrer ?</legend>
      <label class="radio"><input type="radio" name="mode" value="auto" <?= $c['mode'] === 'auto' ? 'checked' : '' ?>>
        <span><strong>Au hasard</strong><br><small>Il pourra être tiré au sort les jours sans contenu prévu.</small></span></label>
      <label class="radio"><input type="radio" name="mode" value="date" <?= $c['mode'] === 'date' ? 'checked' : '' ?>>
        <span><strong>À une date précise</strong><br><small>Montré ce jour-là, puis il rejoint les contenus tirés au hasard.</small></span></label>
      <div class="mode-opts" data-for="date">
        <input type="date" name="publish_date" value="<?= h($c['publish_date'] ?? date('Y-m-d', strtotime('+1 day'))) ?>">
        <label class="inline"><input type="checkbox" name="repeat"> Répéter chaque année à cette date <span class="muted">(anniversaire, fête…)</span></label>
      </div>
      <label class="radio"><input type="radio" name="mode" value="yearly" <?= $c['mode'] === 'yearly' ? 'checked' : '' ?>>
        <span><strong>Chaque année</strong><br><small>Un jour précis (anniversaire, 25 décembre…) ou une période (printemps, Noël…). Jamais tiré au hasard en dehors de ces dates.</small></span></label>
      <div class="mode-opts" data-for="yearly">
        <div class="presets">
          <button type="button" data-preset="12-25,12-25">Jour de Noël</button>
          <button type="button" data-preset="01-01,01-01">1er janvier</button>
          <button type="button" data-preset="12-20,12-31">Noël</button>
          <button type="button" data-preset="12-31,01-03">Nouvel an</button>
          <button type="button" data-preset="03-20,04-15">Printemps</button>
          <button type="button" data-preset="06-21,07-10">Été</button>
          <button type="button" data-preset="08-28,09-10">Rentrée</button>
          <button type="button" data-preset="09-22,10-15">Automne</button>
          <button type="button" data-preset="12-21,01-10">Hiver</button>
        </div>
        <div class="md-range">Du <?= md_select('ys', $c['yearly_start']) ?> au <?= md_select('ye', $c['yearly_end']) ?></div>
        <p class="muted small">Pour un seul jour par an, mettez la même date au début et à la fin.</p>
      </div>
    </fieldset>

    <label class="inline"><input type="checkbox" name="active" <?= $c['active'] ? 'checked' : '' ?>> Publié <span class="muted">(décochez pour garder en brouillon)</span></label>

    <div class="actions">
      <button class="btn" type="submit">Enregistrer</button>
      <a class="btn btn-ghost" href="/admin/">Annuler</a>
    </div>
  </div></form>

  <?php if ($c['id']): ?>
    <form method="post" class="delete-form" onsubmit="return confirm('Supprimer définitivement ce contenu ?')">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
      <input type="hidden" name="action" value="delete">
      <button class="btn btn-danger">Supprimer ce contenu</button>
    </form>
  <?php endif ?>
</main>
<?php page_foot();
