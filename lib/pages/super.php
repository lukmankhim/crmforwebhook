<?php
declare(strict_types=1);

function page_tenants(): void
{
    $tenants = q("SELECT t.*, (SELECT COUNT(*) FROM tenant_users tu WHERE tu.tenant_id = t.id) AS user_count,
                  (SELECT COUNT(*) FROM leads l WHERE l.tenant_id = t.id) AS lead_count
                  FROM tenants t ORDER BY t.status, t.name")->fetchAll();
    render('super/tenants', [
        'title' => 'Tenant',
        'nav' => 'tenants',
        'tenants' => $tenants,
    ]);
}

function page_tenants_create(): never
{
    $slug = strtolower(str_input('slug', 64));
    $name = str_input('name', 150);
    $kind = str_input('kind', 10);
    keep_old(compact('slug', 'name', 'kind'));
    if (!preg_match('/^[a-z0-9-]{2,64}$/', $slug)) {
        flash('error', 'Slug 2-64 karakter: huruf kecil, angka, minus. Dipakai di URL webhook.');
        redirect('/tenants');
    }
    if ($name === '' || !in_array($kind, ['own', 'client'], true)) {
        flash('error', 'Nama dan jenis tenant wajib diisi.');
        redirect('/tenants');
    }
    if (q('SELECT 1 FROM tenants WHERE slug = ?', [$slug])->fetchColumn()) {
        flash('error', 'Slug sudah dipakai.');
        redirect('/tenants');
    }
    $id = tx(function () use ($slug, $name, $kind) {
        q('INSERT INTO tenants (slug, name, kind) VALUES (?, ?, ?)', [$slug, $name, $kind]);
        $id = (int) db()->lastInsertId();
        set_tenant_context($id);
        create_default_pipeline();
        audit('tenant_create', 'tenant', $id, ['slug' => $slug, 'kind' => $kind]);
        return $id;
    });
    unset($_SESSION['old']);
    $_SESSION['tid'] = $id;
    flash('success', "Tenant $name dibuat dan sekarang aktif. Tambahkan pengguna di Pengaturan.");
    redirect('/settings/users');
}

function page_tenants_update(int $id): never
{
    $tenant = q('SELECT * FROM tenants WHERE id = ?', [$id])->fetch();
    if (!$tenant) {
        error_page(404);
    }
    $status = str_input('status', 10);
    $kind = str_input('kind', 10);
    if (!in_array($status, ['active', 'suspended'], true) || !in_array($kind, ['own', 'client'], true)) {
        error_page(400);
    }
    q('UPDATE tenants SET status = ?, kind = ? WHERE id = ?', [$status, $kind, $id]);
    audit('tenant_update', 'tenant', $id, ['status' => [$tenant['status'], $status], 'kind' => [$tenant['kind'], $kind]], $id);
    flash('success', 'Tenant diperbarui.');
    redirect('/tenants');
}

function page_switch_tenant(): never
{
    $id = (int) ($_POST['tenant_id'] ?? 0);
    $tenant = q('SELECT id, name FROM tenants WHERE id = ?', [$id])->fetch();
    if (!$tenant) {
        error_page(404);
    }
    $_SESSION['tid'] = (int) $tenant['id'];
    set_tenant_context((int) $tenant['id']);
    audit('tenant_switch', 'tenant', $tenant['id']);
    $back = (string) ($_POST['back'] ?? '/leads');
    // Hanya path internal, dan halaman detail milik tenant lama tidak dibuka di tenant baru.
    if (!preg_match('#^/(leads|settings(/[a-z]+)?|tenants|audit|account)?$#', $back)) {
        $back = '/leads';
    }
    redirect($back);
}

function page_audit(): void
{
    $tenantFilter = (int) ($_GET['tenant'] ?? 0);
    $action = preg_replace('/[^a-z0-9_]/', '', (string) ($_GET['action'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $where = ['1 = 1'];
    $params = [];
    if ($tenantFilter) {
        $where[] = 'a.tenant_id = :t';
        $params['t'] = $tenantFilter;
    }
    if ($action !== '') {
        $where[] = 'a.action = :a';
        $params['a'] = $action;
    }
    $per = 100;
    $whereSql = implode(' AND ', $where);
    // audit_log lintas tenant, khusus superadmin.
    $rows = q("SELECT a.*, u.username, t.name AS tenant_name FROM audit_log a
               LEFT JOIN users u ON u.id = a.user_id LEFT JOIN tenants t ON t.id = a.tenant_id
               WHERE $whereSql ORDER BY a.id DESC LIMIT :lim OFFSET :off", $params + ['lim' => $per + 1, 'off' => ($page - 1) * $per])->fetchAll();
    $hasMore = count($rows) > $per;
    render('super/audit', [
        'title' => 'Audit log',
        'nav' => 'audit',
        'rows' => array_slice($rows, 0, $per),
        'tenants' => q('SELECT id, name FROM tenants ORDER BY name')->fetchAll(),
        'actions' => q('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN),
        'filters' => ['tenant' => $tenantFilter ?: '', 'action' => $action],
        'page' => $page,
        'has_more' => $hasMore,
    ]);
}
