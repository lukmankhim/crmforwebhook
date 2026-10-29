<?php
declare(strict_types=1);

function page_account(): void
{
    $user = current_user();
    $setupSecret = null;
    if ($user['totp_secret'] === null) {
        $_SESSION['totp_setup'] ??= totp_new_secret();
        $setupSecret = $_SESSION['totp_setup'];
    }
    render('account/index', [
        'title' => 'Akun saya',
        'nav' => 'account',
        'user' => $user,
        'required_2fa' => user_requires_2fa($user),
        'setup_secret' => $setupSecret,
        'setup_uri' => $setupSecret ? totp_uri($setupSecret, $user['username']) : null,
    ]);
}

function page_account_password(): never
{
    $user = q('SELECT * FROM users WHERE id = ?', [current_user()['id']])->fetch();
    $new = (string) ($_POST['new_password'] ?? '');
    if (!rate_limit('pwchange:' . $user['id'], 5, 900)) {
        flash('error', 'Terlalu banyak percobaan. Coba lagi 15 menit lagi.');
        redirect('/account');
    }
    if (!password_verify((string) ($_POST['current_password'] ?? ''), $user['pass_hash'])) {
        flash('error', 'Password lama salah.');
        redirect('/account');
    }
    if ($error = validate_new_password($new)) {
        flash('error', $error);
        redirect('/account');
    }
    if ($new !== (string) ($_POST['confirm_password'] ?? '')) {
        flash('error', 'Konfirmasi password tidak sama.');
        redirect('/account');
    }
    q('UPDATE users SET pass_hash = ? WHERE id = ?', [hash_password($new), $user['id']]);
    session_regenerate_id(true);
    audit('password_change', 'user', $user['id']);
    flash('success', 'Password diganti.');
    redirect('/account');
}

/** Aktifkan 2FA (opsional untuk sales/viewer) atau matikan (hanya kalau tidak wajib). */
function page_account_2fa(): never
{
    $user = current_user();
    $action = str_input('action', 10);
    if ($action === 'enable') {
        $secret = $_SESSION['totp_setup'] ?? null;
        $step = $secret ? totp_verify($secret, str_input('code', 12)) : null;
        if ($step === null) {
            flash('error', 'Kode tidak cocok. Coba lagi dengan kode terbaru.');
            redirect('/account');
        }
        q('UPDATE users SET totp_secret = ?, totp_last_step = ? WHERE id = ?', [encrypt_secret($secret), $step, $user['id']]);
        unset($_SESSION['totp_setup']);
        audit('2fa_enabled', 'user', $user['id']);
        flash('success', 'Verifikasi 2 langkah aktif.');
    } elseif ($action === 'disable') {
        if (user_requires_2fa($user)) {
            flash('error', '2FA wajib untuk admin dan superadmin.');
            redirect('/account');
        }
        q('UPDATE users SET totp_secret = NULL, totp_last_step = NULL WHERE id = ?', [$user['id']]);
        audit('2fa_disabled', 'user', $user['id']);
        flash('success', 'Verifikasi 2 langkah dimatikan.');
    }
    redirect('/account');
}
