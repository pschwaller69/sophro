<?php
declare(strict_types=1);

/*
 * Import de contenus depuis un fichier CSV (Excel, LibreOffice, Google Sheets…).
 * Colonnes reconnues (1re ligne) : titre ; texte ; quand ; date ; debut ; fin
 *   quand = hasard (par défaut), date (avec la colonne date : JJ/MM/AAAA) ou saison (avec debut et fin : JJ/MM)
 * Les contenus déjà présents (même titre et même texte) sont ignorés.
 */

require dirname(__DIR__) . '/lib/bootstrap.php';
require ROOT . '/lib/auth.php';
require ROOT . '/lib/daily.php';
require ROOT . '/lib/layout.php';

require_admin();

function norm(string $s): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
}

function find_col(array $header, array $names): ?int
{
    foreach ($names as $n) {
        $i = array_search($n, $header, true);
        if ($i !== false) {
            return (int) $i;
        }
    }
    return null;
}

/** JJ/MM → MM-JJ */
function parse_md(string $s): ?string
{
    if (preg_match('#^\s*(\d{1,2})\s*[/.-]\s*(\d{1,2})\s*$#', $s, $m) && checkdate((int) $m[2], (int) $m[1], 2024)) {
        return sprintf('%02d-%02d', $m[2], $m[1]);
    }
    return null;
}

/** JJ/MM/AAAA ou AAAA-MM-JJ → AAAA-MM-JJ */
function parse_date(string $s): ?string
{
    $s = trim($s);
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return $s;
    }
    if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $s, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    return null;
}

$report = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        if (empty($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Choisissez un fichier CSV.');
        }
        $raw = (string) file_get_contents($_FILES['csv']['tmp_name']);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);           // BOM UTF-8
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'); // ancien Excel
        }
        $firstLine = strtok($raw, "\n");
        $delim = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        if (substr_count($firstLine, "\t") > substr_count($firstLine, $delim)) {
            $delim = "\t";
        }

        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $header = array_map(fn($h) => norm((string) $h), fgetcsv($fh, 0, $delim, '"', '') ?: []);
        $cTitle = find_col($header, ['titre', 'title']);
        $cText = find_col($header, ['texte', 'phrase', 'contenu', 'body']);
        $cMode = find_col($header, ['quand', 'mode', 'type']);
        $cDate = find_col($header, ['date', 'jour']);
        $cStart = find_col($header, ['debut', 'début']);
        $cEnd = find_col($header, ['fin']);
        if ($cTitle === null && $cText === null) {
            throw new RuntimeException('La première ligne doit contenir les noms de colonnes, au moins « titre » ou « texte ».');
        }

        $existing = [];
        foreach (db()->query('SELECT title, body FROM contents') as $r) {
            $existing[norm($r['title']) . '|' . norm($r['body'])] = true;
        }

        $active = isset($_POST['draft']) ? 0 : 1;
        $ins = db()->prepare('INSERT INTO contents (title, body, mode, publish_date, yearly_start, yearly_end, active) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $added = $dupes = $empty = 0;
        $errors = [];
        $line = 1;
        db()->beginTransaction();
        while (($row = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $line++;
            $get = fn(?int $i) => $i === null ? '' : trim((string) ($row[$i] ?? ''));
            $title = $get($cTitle);
            $text = $get($cText);
            if ($title === '' && $text === '') {
                $empty++;
                continue;
            }
            $key = norm($title) . '|' . norm($text);
            if (isset($existing[$key])) {
                $dupes++;
                continue;
            }
            $mode = norm($get($cMode));
            $date = $start = $end = null;
            if (in_array($mode, ['date', 'date précise'], true)) {
                $mode = 'date';
                $date = parse_date($get($cDate));
                if (!$date) {
                    $errors[] = "Ligne $line : date invalide (« {$get($cDate)} », attendu JJ/MM/AAAA).";
                    continue;
                }
            } elseif (in_array($mode, ['saison', 'chaque année', 'chaque annee', 'tous les ans', 'annuel', 'yearly'], true)) {
                $mode = 'yearly';
                $start = parse_md($get($cStart));
                $end = parse_md($get($cEnd));
                // « chaque année » avec seulement une date (JJ/MM ou JJ/MM/AAAA) : ce jour-là chaque année
                $one = parse_md($get($cDate)) ?? (($d = parse_date($get($cDate))) ? substr($d, 5) : null);
                if ($one && !$start && !$end) {
                    $start = $end = $one;
                }
                $start = $start ?? $end;
                $end = $end ?? $start;
                if (!$start || !$end) {
                    $errors[] = "Ligne $line : période invalide (attendu debut et fin au format JJ/MM).";
                    continue;
                }
            } else {
                $mode = 'auto';
            }
            $ins->execute([$title, $text, $mode, $date, $start, $end, $active]);
            $existing[$key] = true;
            $added++;
        }
        db()->commit();
        reset_today_if_needed(null);
        clear_future_days();
        $report = compact('added', 'dupes', 'empty', 'errors');
    } catch (RuntimeException $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $error = $e->getMessage();
    }
}

page_head(['title' => 'Importer des instants', 'noindex' => true], 'admin');
?>
<header class="site-header">
  <a href="/admin/" class="brand"><img src="/assets/icon.svg" alt="" width="36" height="36"><span>Administration</span></a>
  <nav><a href="/admin/">← Retour</a></nav>
</header>
<main class="wrap">
  <h1 class="admin-title">Importer des instants</h1>
  <?php if ($error): ?><p class="alert error"><?= h($error) ?></p><?php endif ?>
  <?php if ($report): ?>
    <p class="alert ok">
      <strong><?= $report['added'] ?></strong> instant(s) ajouté(s).
      <?php if ($report['dupes']): ?><?= $report['dupes'] ?> déjà présent(s), ignoré(s).<?php endif ?>
      <?php if ($report['empty']): ?><?= $report['empty'] ?> ligne(s) vide(s).<?php endif ?>
      <a href="/admin/">Voir la liste →</a>
    </p>
    <?php if ($report['errors']): ?>
      <div class="alert error"><?= count($report['errors']) ?> ligne(s) non importée(s) :<br><?= implode('<br>', array_map('h', array_slice($report['errors'], 0, 30))) ?></div>
    <?php endif ?>
  <?php endif ?>

  <form method="post" enctype="multipart/form-data" class="form card"><div class="card-body">
    <?= csrf_field() ?>
    <label>Fichier CSV
      <input type="file" name="csv" accept=".csv,text/csv,text/plain" required>
    </label>
    <label class="inline"><input type="checkbox" name="draft"> Importer en brouillon <span class="muted">(à relire avant publication)</span></label>
    <div class="actions"><button class="btn">Importer</button></div>
  </div></form>

  <section class="card" style="margin-top:20px"><div class="card-body small">
    <h2>Format du fichier</h2>
    <p>Un tableau enregistré au format CSV (Excel, LibreOffice ou Google Sheets), avec ces colonnes sur la première ligne :</p>
    <ul>
      <li><strong>titre</strong> et <strong>texte</strong> : au moins l'un des deux ;</li>
      <li><strong>quand</strong> : <em>hasard</em> (par défaut), <em>date</em> (une seule fois) ou <em>chaque année</em> (aussi accepté : <em>saison</em>) ;</li>
      <li><strong>date</strong> : pour <em>date</em>, au format JJ/MM/AAAA ; pour <em>chaque année</em> sur un seul jour, JJ/MM (ex. 25/12) ;</li>
      <li><strong>debut</strong> et <strong>fin</strong> : pour <em>chaque année</em> sur une période, au format JJ/MM (ex. 18/12 et 26/12).</li>
    </ul>
    <p class="muted">Un instant qui existe déjà (même titre et même texte) n'est pas importé une deuxième fois : vous pouvez réimporter le même fichier sans risque.</p>
  </div></section>
</main>
<?php page_foot();
