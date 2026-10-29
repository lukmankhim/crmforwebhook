<?php
declare(strict_types=1);

const SESSION_IDLE_SECONDS = 8 * 3600;
const LOGIN_MAX_FAILS = 5;
const LOGIN_LOCK_MINUTES = 15;

function session_boot(): void
{
    $dir = storage_path('sessions');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_IDLE_SECONDS);
    session_save_path($dir);
    session_name('crmsid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_production() || is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $last = $_SESSION['last_seen'] ?? null;
    if ($last !== null && time() - $last > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = time();

    if (!empty($_SESSION['uid'])) {
        $user = current_user();
        if (!$user) {
            logout_session();
            return;
        }
        set_tenant_context(isset($_SESSION['tid']) ? (int) $_SESSION['tid'] : null);
    }
}

function current_user(): ?array
{
    static $cache = [];
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) {
        return null;
    }
    if (!array_key_exists($uid, $cache)) {
        $row = q('SELECT id, username, name, email, is_super, active, totp_secret FROM users WHERE id = ?', [$uid])->fetch();
        $cache[$uid] = ($row && $row['active']) ? $row : null;
    }
    return $cache[$uid];
}

function find_user_by_login(string $login): ?array
{
    $row = q('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1', [$login, $login])->fetch();
    return $row ?: null;
}

/**
 * Cek username/email + password dengan batas percobaan per IP dan per akun.
 * Mengembalikan user atau string pesan error.
 */
function attempt_password_login(string $login, string $password): array|string
{
    $ip = client_ip();
    $generic = 'Username/email atau password salah.';

    if (!rate_limit('login-ip:' . $ip, 20, 900)) {
        app_log('auth.log', "auth rate-limited ip={$ip}");
        return 'Terlalu banyak percobaan dari alamat ini. Coba lagi 15 menit lagi.';
    }

    $user = $login !== '' ? find_user_by_login($login) : null;
    if ($user && $user['locked_until'] !== null && strtotime($user['locked_until'] . ' UTC') > time()) {
        app_log('auth.log', "auth locked ip={$ip} user={$user['id']}");
        return 'Akun dikunci sementara karena terlalu banyak percobaan. Coba lagi nanti.';
    }

    if (!$user || !$user['active'] || !password_verify($password, $user['pass_hash'])) {
        if ($user) {
            $fails = (int) $user['failed_logins'] + 1;
            $lock = $fails >= LOGIN_MAX_FAILS ? gmdate('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60) : null;
            q('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?', [$lock ? 0 : $fails, $lock, $user['id']]);
        }
        app_log('auth.log', "auth failure ip={$ip}");
        audit('login_failed', 'user', $user['id'] ?? null, ['login' => mb_substr($login, 0, 100)], null, $user['id'] ?? null);
        return $generic;
    }

    if (password_needs_rehash($user['pass_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
        q('UPDATE users SET pass_hash = ? WHERE id = ?', [hash_password($password), $user['id']]);
    }
    q('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$user['id']]);
    return $user;
}

/** Login penuh setelah password (dan 2FA kalau wajib) lolos. */
function complete_login(array $user): ?string
{
    $tenantId = home_tenant_for($user);
    if (!$user['is_super'] && $tenantId === null) {
        return 'Akun ini belum terhubung ke tenant aktif. Hubungi admin.';
    }
    session_regenerate_id(true);
    unset($_SESSION['pending_uid'], $_SESSION['pending_stage'], $_SESSION['totp_setup']);
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['tid'] = $tenantId;
    set_tenant_context($tenantId);
    q('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$user['id']]);
    audit('login', 'user', $user['id'], null, $tenantId, (int) $user['id']);
    return null;
}

function logout_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'],
            'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

function validate_new_password(string $password): ?string
{
    if (mb_strlen($password) < 10) {
        return 'Password minimal 10 karakter.';
    }
    if (mb_strlen($password) > 200) {
        return 'Password terlalu panjang.';
    }
    return null;
}
