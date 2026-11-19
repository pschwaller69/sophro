<?php
declare(strict_types=1);

/*
 * Notifications Web Push sans bibliothèque externe.
 * On envoie des « pushs » vides (sans contenu chiffré) signés avec une clé VAPID :
 * le service worker reçoit le signal, va chercher le contenu du jour, affiche la notification
 * et pose la pastille sur l'icône de l'application.
 */

function b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** Clés VAPID, créées automatiquement la première fois et gardées dans la base. */
function vapid_keys(): array
{
    $priv = setting('vapid_private');
    $pub = setting('vapid_public');
    if ($priv && $pub) {
        return [$priv, $pub];
    }
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$key) {
        throw new RuntimeException('OpenSSL ne peut pas créer de clé EC sur ce serveur.');
    }
    openssl_pkey_export($key, $pem);
    $d = openssl_pkey_get_details($key)['ec'];
    $raw = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
    set_setting('vapid_private', $pem);
    set_setting('vapid_public', b64url($raw));
    return [$pem, b64url($raw)];
}

function vapid_public_key(): string
{
    return vapid_keys()[1];
}

/** Signature ES256 au format JWT (r||s de 64 octets, et non DER). */
function es256_sign(string $data, string $pem): string
{
    if (!openssl_sign($data, $der, $pem, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Signature VAPID impossible.');
    }
    $pos = 2;
    if (ord($der[1]) & 0x80) {
        $pos += ord($der[1]) & 0x7f;
    }
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = ltrim(substr($der, $pos + 2, $len), "\0");
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

function vapid_header(string $endpoint): string
{
    [$pem, $pub] = vapid_keys();
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $sub = str_starts_with(base_url(), 'https://') ? base_url() : 'mailto:contact@schwaller.fr';
    $jwt = b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
        . '.' . b64url(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub]));
    $jwt .= '.' . b64url(es256_sign($jwt, $pem));
    return "vapid t=$jwt, k=$pub";
}

function save_subscription(string $endpoint): void
{
    $host = (string) parse_url($endpoint, PHP_URL_HOST);
    $known = '/(^|\.)(googleapis\.com|push\.apple\.com|push\.services\.mozilla\.com|notify\.windows\.com)$/';
    if (!str_starts_with($endpoint, 'https://') || strlen($endpoint) > 1000 || !preg_match($known, $host)) {
        throw new InvalidArgumentException('Abonnement invalide.');
    }
    db()->prepare('INSERT OR IGNORE INTO push_subscriptions(endpoint) VALUES(?)')->execute([$endpoint]);
}

function delete_subscription(string $endpoint): void
{
    db()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')->execute([$endpoint]);
}

function subscription_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn();
}

/** Envoie le signal à tous les abonnés. Retourne [envoyés, échecs, supprimés]. */
function push_all(): array
{
    $ok = $fail = $removed = 0;
    $rows = db()->query('SELECT endpoint FROM push_subscriptions')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $endpoint) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . vapid_header($endpoint),
                'TTL: 43200',          // la notification reste valable 12 h si le téléphone est éteint
                'Urgency: normal',
                'Content-Length: 0',
            ],
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            $ok++;
            db()->prepare("UPDATE push_subscriptions SET last_ok = datetime('now') WHERE endpoint = ?")->execute([$endpoint]);
        } elseif ($code === 404 || $code === 410) {
            delete_subscription($endpoint); // l'utilisateur s'est désabonné ou a désinstallé l'app
            $removed++;
        } else {
            $fail++;
        }
    }
    return [$ok, $fail, $removed];
}
