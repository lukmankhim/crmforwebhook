<?php
declare(strict_types=1);

const ROLE_ABILITIES = [
    'viewer' => ['leads.view'],
    'sales'  => ['leads.view', 'leads.edit'],
    'admin'  => ['leads.view', 'leads.edit', 'settings'],
];

function set_tenant_context(?int $tenantId): void
{
    $GLOBALS['TENANT_ID'] = $tenantId;
    $GLOBALS['TENANT_ROW'] = null;
}

function has_tenant(): bool
{
    return !empty($GLOBALS['TENANT_ID']);
}

function tenant_id(): int
{
    if (empty($GLOBALS['TENANT_ID'])) {
        throw new RuntimeException('Tenant belum dipilih');
    }
    return (int) $GLOBALS['TENANT_ID'];
}

function current_tenant(): ?array
{
    if (!has_tenant()) {
        return null;
    }
    if (empty($GLOBALS['TENANT_ROW'])) {
        $GLOBALS['TENANT_ROW'] = q('SELECT * FROM tenants WHERE id = ?', [tenant_id()])->fetch() ?: null;
    }
    return $GLOBALS['TENANT_ROW'];
}

function tenant_tz(): DateTimeZone
{
    $t = current_tenant();
    try {
        return new DateTimeZone($t['timezone'] ?? 'Asia/Jakarta');
    } catch (Exception) {
        return new DateTimeZone('Asia/Jakarta');
    }
}

/** Role user aktif di tenant aktif. Superadmin dianggap admin di tenant mana pun. */
function current_role(): ?string
{
    $user = current_user();
    if (!$user || !has_tenant()) {
        return null;
    }
    if ($user['is_super']) {
        return 'admin';
    }
    $role = q('SELECT role FROM tenant_users WHERE tenant_id = ? AND user_id = ?', [tenant_id(), $user['id']])->fetchColumn();
    return $role ?: null;
}

function can(string $ability): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    if ($ability === 'super') {
        return (bool) $user['is_super'];
    }
    $role = current_role();
    return $role !== null && in_array($ability, ROLE_ABILITIES[$role], true);
}

/** Tenant yang boleh dipakai user ini saat login. Non-superadmin hanya punya satu tenant. */
function home_tenant_for(array $user): ?int
{
    if ($user['is_super']) {
        $id = q("SELECT id FROM tenants WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
        return $id ? (int) $id : null;
    }
    $id = q("SELECT t.id FROM tenant_users tu JOIN tenants t ON t.id = tu.tenant_id
             WHERE tu.user_id = ? AND t.status = 'active' ORDER BY tu.id LIMIT 1", [$user['id']])->fetchColumn();
    return $id ? (int) $id : null;
}

/** Admin dan superadmin wajib 2FA. */
function user_requires_2fa(array $user): bool
{
    if ($user['is_super']) {
        return true;
    }
    return (bool) q("SELECT 1 FROM tenant_users WHERE user_id = ? AND role = 'admin' LIMIT 1", [$user['id']])->fetchColumn();
}
