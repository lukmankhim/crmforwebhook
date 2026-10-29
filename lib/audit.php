<?php
declare(strict_types=1);

/**
 * Catat aksi penting: login, ubah lead, ubah stage, ekspor, ubah user, ubah pengaturan.
 * $tenantId dan $userId default dari konteks aktif.
 */
function audit(string $action, ?string $object = null, int|string|null $objectId = null, ?array $detail = null, ?int $tenantId = null, ?int $userId = null): void
{
    $tenantId ??= has_tenant() ? tenant_id() : null;
    $userId ??= current_user()['id'] ?? null;
    q('INSERT INTO audit_log (tenant_id, user_id, action, object, object_id, detail, ip, user_agent)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        $tenantId,
        $userId,
        $action,
        $object,
        $objectId === null ? null : (string) $objectId,
        $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        PHP_SAPI === 'cli' ? 'cli' : client_ip(),
        PHP_SAPI === 'cli' ? 'cli' : mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
}
