<?php
declare(strict_types=1);

/* Image de partage (1200×630) générée pour les contenus sans image : le texte sur fond doux. */

require __DIR__ . '/lib/bootstrap.php';

$c = get_content((int) ($_GET['p'] ?? 0));
if (!$c || !is_public($c) || !function_exists('imagettftext')) {
    header('Location: /assets/og-default.png');
    exit;
}

$cacheDir = ROOT . '/uploads/og';
$cacheFile = $cacheDir . '/' . (int) $c['id'] . '-' . md5($c['updated_at'] . $c['title'] . $c['body']) . '.png';

if (!is_file($cacheFile)) {
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0775, true);
    }
    foreach (glob($cacheDir . '/' . (int) $c['id'] . '-*.png') ?: [] as $old) {
        @unlink($old);
    }
    $W = 1200;
    $H = 630;
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
    imagefilledellipse($img, $W - 120, 110, 360, 360, $soft);
    imagefilledellipse($img, 90, $H - 40, 260, 260, $soft);

    $font = ROOT . '/assets/fonts/Lora.ttf';
    $fontIt = ROOT . '/assets/fonts/Lora-Italic.ttf';
    $text = trim((string) $c['body']) !== '' ? excerpt((string) $c['body'], 230) : content_label($c);
    $title = trim((string) $c['title']);

    // taille de police adaptée à la longueur
    $size = mb_strlen($text) < 80 ? 46 : (mb_strlen($text) < 150 ? 38 : 31);
    $maxW = $W - 200;
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
    imagepng($img, $cacheFile, 6);
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
readfile($cacheFile);
