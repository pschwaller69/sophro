<?php
declare(strict_types=1);

/*
 * Image de partage générée : le texte sur fond doux (1200×630, aperçu des liens partagés).
 * Avec &format=jpg : image JPG pour Facebook/Instagram (la photo du contenu, ou une carte carrée 1080×1080).
 */

require __DIR__ . '/lib/bootstrap.php';

$c = get_content((int) ($_GET['p'] ?? 0));
if (!$c || !is_public($c) || !function_exists('imagettftext')) {
    header('Location: /assets/og-default.png');
    exit;
}

$jpg = ($_GET['format'] ?? '') === 'jpg';   // carré/Instagram ; sinon aperçu large 1200×630
$ext = $jpg ? 'jpg' : 'og.jpg';
$cacheDir = ROOT . '/uploads/og';
$hash = md5($c['updated_at'] . $c['title'] . $c['body'] . $c['image']);
$cacheFile = $cacheDir . '/' . (int) $c['id'] . '-' . $hash . '.' . $ext;

if (!is_file($cacheFile)) {
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0775, true);
    }
    foreach (glob($cacheDir . '/' . (int) $c['id'] . '-*') ?: [] as $old) {
        if (!str_contains($old, $hash)) {
            @unlink($old); // anciennes versions de l'image
        }
    }
}

if (!is_file($cacheFile) && $jpg && $c['image']) {
    // Photo du contenu en JPG, recadrée entre 4:5 et 1.91:1 (formats acceptés par Instagram)
    $src = @imagecreatefromstring((string) file_get_contents(ROOT . '/uploads/' . $c['image']));
    if ($src) {
        $w = imagesx($src);
        $hh = imagesy($src);
        $cw = $w;
        $ch = $hh;
        if ($w / $hh < 0.8) {
            $ch = (int) round($w / 0.8);
        } elseif ($w / $hh > 1.91) {
            $cw = (int) round($hh * 1.91);
        }
        $scale = min(1, 1440 / $cw);
        $dst = imagecreatetruecolor((int) round($cw * $scale), (int) round($ch * $scale));
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, (int) (($w - $cw) / 2), (int) (($hh - $ch) / 2), imagesx($dst), imagesy($dst), $cw, $ch);
        imagejpeg($dst, $cacheFile, 88);
    }
}

if (!is_file($cacheFile) && !$jpg && $c['image']) {
    // Aperçu des liens partagés : la photo recadrée au même format 1200×630 que les instants texte,
    // avec le titre sur un léger voile en bas.
    $src = @imagecreatefromstring((string) file_get_contents(ROOT . '/uploads/' . $c['image']));
    if ($src) {
        $W = 1200;
        $H = 630;
        $w = imagesx($src);
        $hh = imagesy($src);
        $scale = max($W / $w, $H / $hh);
        $cw = (int) round($W / $scale);
        $ch = (int) round($H / $scale);
        $img = imagecreatetruecolor($W, $H);
        imagecopyresampled($img, $src, 0, 0, (int) (($w - $cw) / 2), (int) (($hh - $ch) / 2), $W, $H, $cw, $ch);
        $font = ROOT . '/assets/fonts/Lora.ttf';
        $label = trim((string) $c['title']) !== '' ? trim((string) $c['title']) : excerpt((string) $c['body'], 70);
        $band = 150;
        for ($y = 0; $y < $band; $y++) {
            $a = (int) (127 - 85 * ($y / $band));           // dégradé transparent → voile sombre
            imageline($img, 0, $H - $band + $y, $W, $H - $band + $y, imagecolorallocatealpha($img, 30, 26, 45, $a));
        }
        $white = imagecolorallocate($img, 255, 255, 255);
        $soft = imagecolorallocatealpha($img, 255, 255, 255, 30);
        if ($label !== '') {
            $size = mb_strlen($label) > 45 ? 28 : 36;
            $box = imagettfbbox($size, 0, $font, $label);
            imagettftext($img, $size, 0, (int) (($W - ($box[2] - $box[0])) / 2), $H - 62, $white, $font, $label);
        }
        $brand = (string) cfg('site_name') . '  ·  ' . parse_url(base_url(), PHP_URL_HOST);
        $box = imagettfbbox(17, 0, $font, $brand);
        imagettftext($img, 17, 0, (int) (($W - ($box[2] - $box[0])) / 2), $H - 24, $soft, $font, $brand);
        imagejpeg($img, $cacheFile, 88);
    }
}

if (!is_file($cacheFile)) {
    $W = $jpg ? 1080 : 1200;
    $H = $jpg ? 1080 : 630;
    $img = imagecreatetruecolor($W, $H);
    // dégradé vert sauge → sable
    [$r1, $g1, $b1] = [0xE8, 0xEF, 0xE9];
    [$r2, $g2, $b2] = [0xF6, 0xEF, 0xE4];
    for ($y = 0; $y < $H; $y++) {
        $t = $y / $H;
        $col = imagecolorallocate($img, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t));
        imageline($img, 0, $y, $W, $y, $col);
    }
    $green = imagecolorallocate($img, 0x5F, 0x7F, 0x6E);
    $ink = imagecolorallocate($img, 0x2F, 0x3A, 0x34);
    $soft = imagecolorallocatealpha($img, 0x5F, 0x7F, 0x6E, 100);
    $lav = imagecolorallocatealpha($img, 0x8A, 0x78, 0xC0, 95);
    imagefilledellipse($img, $W - 120, 110, 360, 360, $lav);
    imagefilledellipse($img, 90, $H - 40, 260, 260, $soft);

    $font = ROOT . '/assets/fonts/Lora.ttf';
    $fontIt = ROOT . '/assets/fonts/Lora-Italic.ttf';
    $text = trim((string) $c['body']) !== '' ? excerpt((string) $c['body'], 230) : content_label($c);
    $title = trim((string) $c['title']);

    // taille de police adaptée à la longueur
    $size = (mb_strlen($text) < 80 ? 46 : (mb_strlen($text) < 150 ? 38 : 31)) + ($jpg ? 4 : 0);
    $maxW = $W - ($jpg ? 160 : 200);
    $lines = [];
    $line = '';
    foreach (preg_split('/\s+/u', $text) as $word) {
        $try = $line === '' ? $word : "$line $word";
        $box = imagettfbbox($size, 0, $fontIt, $try);
        if ($box[2] - $box[0] > $maxW && $line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $try;
        }
    }
    $lines[] = $line;
    $lineH = (int) ($size * 1.5);
    $blockH = count($lines) * $lineH + ($title !== '' && $title !== $text ? 70 : 0);
    $y = (int) (($H - $blockH) / 2) + $size;
    if ($title !== '' && $title !== $text) {
        $box = imagettfbbox(26, 0, $font, mb_strtoupper($title));
        imagettftext($img, 26, 0, (int) (($W - ($box[2] - $box[0])) / 2), $y - 10, $green, $font, mb_strtoupper($title));
        $y += 70;
    }
    foreach ($lines as $l) {
        $box = imagettfbbox($size, 0, $fontIt, $l);
        imagettftext($img, $size, 0, (int) (($W - ($box[2] - $box[0])) / 2), $y, $ink, $fontIt, $l);
        $y += $lineH;
    }
    $brand = (string) cfg('site_name') . '  ·  ' . parse_url(base_url(), PHP_URL_HOST);
    $box = imagettfbbox(20, 0, $font, $brand);
    imagettftext($img, 20, 0, (int) (($W - ($box[2] - $box[0])) / 2), $H - 40, $green, $font, $brand);
    imagejpeg($img, $cacheFile, 90);
}

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400');
readfile($cacheFile);
