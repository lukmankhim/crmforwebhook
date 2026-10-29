<?php
declare(strict_types=1);

function page_settings_home(): never
{
    redirect('/settings/tenant');
}

function page_settings_tenant(): void
{
    render('settings/tenant', [
        'title' => 'Pengaturan tenant',
        'nav' => 'settings',
        'tab' => 'tenant',
        'tenant' => current_tenant(),
        'timezones' => ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'],
    ]);
}

function page_settings_tenant_post(): never
{
    $name = str_input('name', 150);
    $tz = str_input('timezone', 64);
    if ($name === '') {
        flash('error', 'Nama tenant wajib diisi.');
        redirect('/settings/tenant');
    }
    if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
        flash('error', 'Zona waktu tidak dikenal.');
        redirect('/settings/tenant');
    }
    $old = current_tenant();
    q('UPDATE tenants SET name = ?, timezone = ? WHERE id = ?', [$name, $tz, tenant_id()]);
    audit('tenant_update', 'tenant', tenant_id(), ['name' => [$old['name'], $name], 'timezone' => [$old['timezone'], $tz]]);
    flash('success', 'Pengaturan tenant tersimpan.');
    redirect('/settings/tenant');
}

function page_settings_users(): void
{
    $users = q_tenant('SELECT u.id, u.username, u.name, u.email, u.active, u.last_login_at, u.totp_secret IS NOT NULL AS has_2fa, tu.role
                       FROM tenant_users tu JOIN users u ON u.id = tu.user_id
                       WHERE tu.tenant_id = :tid ORDER BY u.active DESC, u.name')->fetchAll();
    render('settings/users', [
        'title' => 'Pengguna',
        'nav' => 'settings',
        'tab' => 'users',
        'users' => $users,
        'shown_password' => $_SESSION['shown_password'] ?? null,
    ]);
    unset($_SESSION['shown_password']);
}

function page_settings_users_create(): never
{
    $username = strtolower(str_input('username', 64));
    $name = str_input('name', 150);
    $email = nullable_input('email', 190);
    $role = str_input('role', 10);
    keep_old(compact('username', 'name', 'email', 'role'));

    if (!preg_match('/^[a-z0-9._-]{3,64}$/', $username)) {
        flash('error', 'Username 3-64 karakter: huruf kecil, angka, titik, minus, garis bawah.');
        redirect('/settings/users');
    }
    if ($name === '' || !isset(role_labels()[$role])) {
        flash('error', 'Nama dan role wajib diisi.');
        redirect('/settings/users');
    }
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Format email tidak valid.');
        redirect('/settings/users');
    }
    if (q('SELECT 1 FROM users WHERE username = ? OR (email IS NOT NULL AND email = ?)', [$username, $email])->fetchColumn()) {
        flash('error', 'Username atau email sudah dipakai.');
        redirect('/settings/users');
    }

    $password = random_password();
    tx(function () use ($username, $name, $email, $role, $password) {
        q('INSERT INTO users (username, name, email, pass_hash) VALUES (?, ?, ?, ?)', [$username, $name, $email, hash_password($password)]);
        $userId = (int) db()->lastInsertId();
        q_tenant('INSERT INTO tenant_users (tenant_id, user_id, role) VALUES (:tid, :u, :r)', ['u' => $userId, 'r' => $role]);
        audit('user_create', 'user', $userId, ['username' => $username, 'role' => $role]);
    });
    unset($_SESSION['old']);
    $_SESSION['shown_password'] = ['username' => $username, 'password' => $password];
    flash('success', 'Pengguna dibuat. Salin password sementara di bawah, hanya tampil sekali.');
    redirect('/settings/users');
}

function page_settings_users_update(int $userId): never
{
    $member = q_tenant('SELECT u.*, tu.role FROM tenant_users tu JOIN users u ON u.id = tu.user_id
                        WHERE tu.tenant_id = :tid AND u.id = :u', ['u' => $userId])->fetch();
    if (!$member) {
        error_page(404);
    }
    $me = current_user();
    $action = str_input('action', 20);
    $isSelf = (int) $me['id'] === $userId;

    if ($isSelf && in_array($action, ['role', 'deactivate', 'reset_2fa'], true)) {
        flash('error', 'Tidak bisa mengubah role, menonaktifkan, atau mereset 2FA akun sendiri di sini.');
        redirect('/settings/users');
    }
    if ($member['is_super'] && !$me['is_super']) {
        error_page(403);
    }

    switch ($action) {
        case 'role':
            $role = str_input('role', 10);
            if (!isset(role_labels()[$role])) {
                error_page(400);
            }
            q_tenant('UPDATE tenant_users SET role = :r WHERE tenant_id = :tid AND user_id = :u', ['r' => $role, 'u' => $userId]);
            audit('user_role', 'user', $userId, ['dari' => $member['role'], 'ke' => $role]);
            flash('success', 'Role diperbarui.');
            break;
        case 'deactivate':
        case 'activate':
            q('UPDATE users SET active = ? WHERE id = ?', [$action === 'activate' ? 1 : 0, $userId]);
            audit('user_' . $action, 'user', $userId);
            flash('success', $action === 'activate' ? 'Pengguna diaktifkan.' : 'Pengguna dinonaktifkan.');
            break;
        case 'reset_password':
            $password = random_password();
            q('UPDATE users SET pass_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?', [hash_password($password), $userId]);
            audit('user_reset_password', 'user', $userId);
            $_SESSION['shown_password'] = ['username' => $member['username'], 'password' => $password];
            flash('success', 'Password direset. Salin password baru di bawah, hanya tampil sekali.');
            break;
        case 'reset_2fa':
            q('UPDATE users SET totp_secret = NULL, totp_last_step = NULL WHERE id = ?', [$userId]);
            audit('user_reset_2fa', 'user', $userId);
            flash('success', '2FA direset. Pengguna akan diminta mengatur ulang saat login berikutnya (wajib untuk admin).');
            break;
        default:
            error_page(400);
    }
    redirect('/settings/users');
}

function page_settings_pipeline(): void
{
    $pipelineId = default_pipeline_id();
    $usage = [];
    foreach (q_tenant('SELECT stage, COUNT(*) AS n FROM leads WHERE tenant_id = :tid AND pipeline_id = :p GROUP BY stage', ['p' => $pipelineId]) as $r) {
        $usage[$r['stage']] = (int) $r['n'];
    }
    render('settings/pipeline', [
        'title' => 'Pipeline',
        'nav' => 'settings',
        'tab' => 'pipeline',
        'stages' => pipeline_stages($pipelineId),
        'usage' => $usage,
    ]);
}

function page_settings_pipeline_post(): never
{
    $pipelineId = default_pipeline_id();
    $stages = pipeline_stages($pipelineId);
    $action = str_input('action', 20);
    $kinds = ['open', 'won', 'lost'];

    if ($action === 'save') {
        $rows = $_POST['stages'] ?? [];
        if (!is_array($rows)) {
            error_page(400);
        }
        tx(function () use ($rows, $stages, $kinds, $pipelineId) {
            foreach ($rows as $key => $row) {
                if (!isset($stages[$key]) || !is_array($row)) {
                    continue;
                }
                $label = mb_substr(trim((string) ($row['label'] ?? '')), 0, 80);
                $kind = in_array($row['kind'] ?? '', $kinds, true) ? $row['kind'] : $stages[$key]['kind'];
                if ($key === 'baru') {
                    $kind = 'open';
                }
                q_tenant('UPDATE pipeline_stages SET label = :l, sort = :s, kind = :k
                          WHERE tenant_id = :tid AND pipeline_id = :p AND stage_key = :key', [
                    'l' => $label !== '' ? $label : $stages[$key]['label'],
                    's' => (int) ($row['sort'] ?? $stages[$key]['sort']),
                    'k' => $kind,
                    'p' => $pipelineId,
                    'key' => $key,
                ]);
            }
            audit('pipeline_update', 'pipeline', $pipelineId);
        });
        flash('success', 'Pipeline tersimpan.');
    } elseif ($action === 'add') {
        $label = str_input('label', 80);
        $kind = in_array($_POST['kind'] ?? '', $kinds, true) ? $_POST['kind'] : 'open';
        $key = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
        if ($label === '' || $key === '') {
            flash('error', 'Nama stage wajib diisi (huruf atau angka).');
        } elseif (isset($stages[$key])) {
            flash('error', 'Stage dengan nama itu sudah ada.');
        } else {
            $maxSort = $stages ? max(array_map(fn($s) => (int) $s['sort'], $stages)) : 0;
            q_tenant('INSERT INTO pipeline_stages (tenant_id, pipeline_id, stage_key, label, sort, kind) VALUES (:tid, :p, :k, :l, :s, :kind)', [
                'p' => $pipelineId, 'k' => mb_substr($key, 0, 40), 'l' => $label, 's' => $maxSort + 10, 'kind' => $kind,
            ]);
            audit('pipeline_stage_add', 'pipeline', $pipelineId, ['stage' => $key]);
            flash('success', 'Stage ditambahkan.');
        }
    } elseif ($action === 'delete') {
        $key = str_input('stage_key', 40);
        $used = (int) q_tenant('SELECT COUNT(*) FROM leads WHERE tenant_id = :tid AND pipeline_id = :p AND stage = :s', ['p' => $pipelineId, 's' => $key])->fetchColumn();
        if (!isset($stages[$key])) {
            error_page(404);
        } elseif ($key === 'baru') {
            flash('error', 'Stage "baru" dipakai untuk lead masuk, tidak bisa dihapus.');
        } elseif ($used > 0) {
            flash('error', "Stage masih dipakai $used lead. Pindahkan dulu lead-nya.");
        } else {
            q_tenant('DELETE FROM pipeline_stages WHERE tenant_id = :tid AND pipeline_id = :p AND stage_key = :k', ['p' => $pipelineId, 'k' => $key]);
            audit('pipeline_stage_delete', 'pipeline', $pipelineId, ['stage' => $key]);
            flash('success', 'Stage dihapus.');
        }
    } else {
        error_page(400);
    }
    redirect('/settings/pipeline');
}
