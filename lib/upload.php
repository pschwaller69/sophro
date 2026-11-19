<?php
declare(strict_types=1);

const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
const AUDIO_TYPES = [
    'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3',
    'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/m4a' => 'm4a', 'video/mp4' => 'm4a',
    'audio/aac' => 'aac', 'audio/x-hx-aac-adts' => 'aac',
    'audio/ogg' => 'ogg', 'video/ogg' => 'ogg', 'audio/opus' => 'ogg',
    'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav',
    'audio/webm' => 'webm', 'video/webm' => 'webm',
];

/** Enregistre un fichier envoyé. Retourne le nom du fichier, ou null si aucun fichier. */
function handle_upload(string $field, string $kind): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(match ($f['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Le fichier est trop lourd (64 Mo maximum).',
            UPLOAD_ERR_PARTIAL => "L'envoi du fichier a été interrompu, réessayez.",
            default => "Erreur pendant l'envoi du fichier (code {$f['error']}).",
        });
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    $types = $kind === 'image' ? IMAGE_TYPES : AUDIO_TYPES;
    if (!isset($types[$mime])) {
        throw new RuntimeException($kind === 'image'
            ? "Format d'image non reconnu ($mime). Utilisez JPG, PNG ou WebP."
            : "Format audio non reconnu ($mime). Utilisez MP3 ou M4A.");
    }
    $dir = ROOT . '/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $types[$mime];
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) {
        throw new RuntimeException("Impossible d'enregistrer le fichier sur le serveur.");
    }
    if ($kind === 'image') {
        $name = optimize_image("$dir/$name", $name);
    } elseif (in_array($types[$mime], ['webm', 'ogg', 'wav'], true)) {
        $name = convert_audio_to_m4a("$dir/$name", $name);
    }
    return $name;
}

/** Remet l'image à l'endroit (photos de téléphone) et la réduit à 1600 px pour un chargement rapide. */
function optimize_image(string $path, string $name): string
{
    if (!function_exists('imagecreatefromstring') || str_ends_with($name, '.gif')) {
        return $name;
    }
    $img = @imagecreatefromstring((string) file_get_contents($path));
    if (!$img) {
        return $name;
    }
    if (function_exists('exif_read_data') && str_ends_with($name, '.jpg')) {
        $o = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        $img = match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
    }
    $w = imagesx($img);
    $hgt = imagesy($img);
    $max = 1600;
    if ($w > $max || $hgt > $max) {
        $r = min($max / $w, $max / $hgt);
        $img = imagescale($img, (int) round($w * $r), (int) round($hgt * $r), IMG_BICUBIC);
    }
    $newName = preg_replace('/\.\w+$/', '.jpg', $name);
    if (str_ends_with($name, '.png') || str_ends_with($name, '.webp')) {
        // garde la transparence
        imagesavealpha($img, true);
        $newName = preg_replace('/\.\w+$/', '.webp', $name);
        imagewebp($img, dirname($path) . '/' . $newName, 85);
    } else {
        imagejpeg($img, dirname($path) . '/' . $newName, 85);
    }
    if ($newName !== $name) {
        @unlink($path);
    }
    return $newName;
}

/**
 * WebM/Ogg/WAV → M4A (AAC) si ffmpeg est disponible sur le serveur, pour que l'audio soit lisible
 * sur iPhone. Sinon le fichier est gardé tel quel.
 */
function convert_audio_to_m4a(string $path, string $name): string
{
    if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
        return $name;
    }
    $ffmpeg = trim((string) @exec('command -v ffmpeg 2>/dev/null'));
    if ($ffmpeg === '') {
        return $name;
    }
    $newName = preg_replace('/\.\w+$/', '.m4a', $name);
    $out = dirname($path) . '/' . $newName;
    @exec(escapeshellarg($ffmpeg) . ' -y -loglevel error -i ' . escapeshellarg($path) . ' -vn -c:a aac -b:a 96k ' . escapeshellarg($out) . ' 2>&1', $o, $code);
    if ($code === 0 && is_file($out) && filesize($out) > 0) {
        @unlink($path);
        return $newName;
    }
    @unlink($out);
    return $name;
}

function delete_media(?string $file): void
{
    if ($file && !str_contains($file, '/') && is_file(ROOT . '/uploads/' . $file)) {
        @unlink(ROOT . '/uploads/' . $file);
    }
}
