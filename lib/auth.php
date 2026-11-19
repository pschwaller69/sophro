<?php
declare(strict_types=1);

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('sophro_admin');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path'     => '/',
        'secure'   => str_starts_with(base_url(), 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
    session_start();
}

function is_admin(): bool
{
    start_session();
    return !empty($_SESSION['admin']);
}

function try_login(string $password): bool
{
    $hash = (string) cfg('admin_password_hash');
    if ($hash === '' || !password_verify($password, $hash)) {
        usleep(800000); // ralentit les essais au hasard
        return false;
    }
    start_session();
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    return true;
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function check_csrf(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Session expirée, rechargez la page.');
    }
}

function require_admin(): void
{
    if (!is_admin()) {
        header('Location: /admin/');
        exit;
    }
}
