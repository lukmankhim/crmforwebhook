<?php
declare(strict_types=1);

function page_login_form(): void
{
    if (current_user()) {
        redirect('/');
    }
    render('auth/login', ['title' => 'Masuk'], 'layout_bare');
}

function page_login_post(): never
{
    $login = str_input('login', 190);
    $result = attempt_password_login($login, (string) ($_POST['password'] ?? ''));
    if (is_string($result)) {
        flash('error', $result);
        keep_old(['login' => $login]);
        redirect('/login');
    }
    $user = $result;

    if (user_requires_2fa($user) || $user['totp_secret'] !== null) {
        session_regenerate_id(true);
        $_SESSION['pending_uid'] = (int) $user['id'];
        if ($user['totp_secret'] === null) {
            $_SESSION['pending_stage'] = 'setup';
            redirect('/login/2fa-setup');
        }
        $_SESSION['pending_stage'] = '2fa';
        redirect('/login/2fa');
    }

    if ($error = complete_login($user)) {
        flash('error', $error);
        redirect('/login');
    }
    redirect('/');
}

function pending_user(string $stage): array
{
    if (($_SESSION['pending_stage'] ?? '') !== $stage) {
        redirect(($_SESSION['pending_stage'] ?? '') === 'setup' ? '/login/2fa-setup' : '/login/2fa');
    }
    $user = q('SELECT * FROM users WHERE id = ? AND active = 1', [$_SESSION['pending_uid']])->fetch();
    if (!$user) {
        logout_session();
        redirect('/login');
    }
    return $user;
}

function page_2fa_form(): void
{
    pending_user('2fa');
    render('auth/2fa', ['title' => 'Verifikasi 2 langkah'], 'layout_bare');
}

function page_2fa_post(): never
{
    $user = pending_user('2fa');
    if (!rate_limit('2fa:' . $user['id'], 5, 300)) {
        flash('error', 'Terlalu banyak percobaan kode. Tunggu 5 menit.');
        redirect('/login/2fa');
    }
    $secret = decrypt_secret($user['totp_secret']);
    $step = $secret ? totp_verify($secret, str_input('code', 12), $user['totp_last_step'] !== null ? (int) $user['totp_last_step'] : null) : null;
    if ($step === null) {
        app_log('auth.log', 'auth 2fa-failure ip=' . client_ip());
        audit('login_2fa_failed', 'user', $user['id'], null, null, (int) $user['id']);
        flash('error', 'Kode tidak cocok. Pastikan jam di HP sudah benar.');
        redirect('/login/2fa');
    }
    q('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $user['id']]);
    if ($error = complete_login($user)) {
        logout_session();
        session_boot();
        flash('error', $error);
        redirect('/login');
    }
    redirect('/');
}

function page_2fa_setup_form(): void
{
    $user = pending_user('setup');
    $_SESSION['totp_setup'] ??= totp_new_secret();
    render('auth/2fa_setup', [
        'title' => 'Aktifkan verifikasi 2 langkah',
        'secret' => $_SESSION['totp_setup'],
        'uri' => totp_uri($_SESSION['totp_setup'], $user['username']),
        'action' => '/login/2fa-setup',
    ], 'layout_bare');
}

function page_2fa_setup_post(): never
{
    $user = pending_user('setup');
    $secret = $_SESSION['totp_setup'] ?? null;
    if (!rate_limit('2fa:' . $user['id'], 5, 300)) {
        flash('error', 'Terlalu banyak percobaan kode. Tunggu 5 menit.');
        redirect('/login/2fa-setup');
    }
    $step = $secret ? totp_verify($secret, str_input('code', 12)) : null;
    if ($step === null) {
        flash('error', 'Kode tidak cocok. Coba lagi dengan kode terbaru dari aplikasi.');
        redirect('/login/2fa-setup');
    }
    q('UPDATE users SET totp_secret = ?, totp_last_step = ? WHERE id = ?', [encrypt_secret($secret), $step, $user['id']]);
    audit('2fa_enabled', 'user', $user['id'], null, null, (int) $user['id']);
    if ($error = complete_login($user)) {
        logout_session();
        session_boot();
        flash('error', $error);
        redirect('/login');
    }
    flash('success', 'Verifikasi 2 langkah aktif.');
    redirect('/');
}

function page_logout(): never
{
    if (current_user()) {
        audit('logout', 'user', current_user()['id']);
    }
    logout_session();
    redirect('/login');
}
