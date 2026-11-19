<?php
declare(strict_types=1);

/*
 * Publication automatique du contenu du jour sur une Page Facebook et un compte Instagram professionnel,
 * via l'API Graph de Meta. Les identifiants sont saisis dans l'admin (menu « Réseaux sociaux »)
 * et stockés uniquement dans la base du serveur.
 */

const GRAPH = 'https://graph.facebook.com';

function social_caption(array $c): string
{
    $parts = [];
    if (trim((string) $c['title']) !== '') {
        $parts[] = trim((string) $c['title']);
    }
    if (trim((string) $c['body']) !== '') {
        $parts[] = trim((string) $c['body']);
    }
    if ($c['audio']) {
        $parts[] = '🎧 La mini-séance audio est à écouter sur l\'application.';
    }
    return implode("\n\n", $parts);
}

/** Image JPG publique du contenu (photo du contenu ou carte générée avec le texte). */
function social_image_url(array $c): string
{
    return base_url() . '/og.php?p=' . (int) $c['id'] . '&format=jpg&v=' . substr(md5((string) $c['updated_at']), 0, 6);
}

function graph_post(string $path, array $params): array
{
    $ch = curl_init(GRAPH . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $body = (string) curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $json = json_decode($body, true) ?: [];
    if ($err || isset($json['error']) || !$json) {
        throw new RuntimeException($json['error']['message'] ?? ($err ?: 'Réponse inattendue de Facebook'));
    }
    return $json;
}

function post_facebook(array $c): string
{
    $page = trim((string) setting('fb_page_id'));
    $token = trim((string) setting('fb_token'));
    $message = social_caption($c) . "\n\n👉 " . content_url($c);
    $r = graph_post("$page/photos", ['url' => social_image_url($c), 'caption' => $message, 'access_token' => $token]);
    return 'Facebook : publié (' . ($r['post_id'] ?? $r['id'] ?? '?') . ')';
}

function post_instagram(array $c): string
{
    $ig = trim((string) setting('ig_user_id'));
    $token = trim((string) setting('fb_token'));
    // Instagram n'affiche pas les liens cliquables dans les légendes : on renvoie vers le lien du profil.
    $caption = social_caption($c) . "\n\n✨ Retrouvez chaque jour une pause sophrologie : lien dans la bio.";
    $media = graph_post("$ig/media", ['image_url' => social_image_url($c), 'caption' => $caption, 'access_token' => $token]);
    // Instagram traite l'image quelques secondes avant de pouvoir la publier
    $last = null;
    for ($i = 0; $i < 6; $i++) {
        sleep($i === 0 ? 3 : 5);
        try {
            $r = graph_post("$ig/media_publish", ['creation_id' => $media['id'], 'access_token' => $token]);
            return 'Instagram : publié (' . ($r['id'] ?? '?') . ')';
        } catch (RuntimeException $e) {
            $last = $e;
        }
    }
    throw $last ?? new RuntimeException('Instagram : publication impossible');
}

/** Publie le contenu sur les réseaux activés. Retourne la liste des messages (succès ou erreurs). */
function post_to_socials(array $c): array
{
    $log = [];
    if (setting('fb_enabled') === '1' && setting('fb_page_id') && setting('fb_token')) {
        try {
            $log[] = post_facebook($c);
        } catch (Throwable $e) {
            $log[] = 'Facebook : ERREUR – ' . $e->getMessage();
        }
    }
    if (setting('ig_enabled') === '1' && setting('ig_user_id') && setting('fb_token')) {
        try {
            $log[] = post_instagram($c);
        } catch (Throwable $e) {
            $log[] = 'Instagram : ERREUR – ' . $e->getMessage();
        }
    }
    if (!$log) {
        $log[] = 'Aucun réseau activé.';
    }
    $line = date('Y-m-d H:i') . ' · ' . content_label($c) . ' · ' . implode(' · ', $log);
    $hist = array_slice(array_filter(explode("\n", (string) setting('social_log'))), -19);
    $hist[] = $line;
    set_setting('social_log', implode("\n", $hist));
    return $log;
}
