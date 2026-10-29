<?php
declare(strict_types=1);

function page_ingest_index(): void
{
    $status = (string) ($_GET['status'] ?? '');
    $statuses = ['received', 'retry', 'processed', 'failed', 'rejected'];
    $logWhere = 'tenant_id = :tid';
    $params = [];
    if (in_array($status, $statuses, true)) {
        $logWhere .= ' AND status = :st';
        $params['st'] = $status;
    }
    render('settings/ingest', [
        'title' => 'Webhook & API',
        'nav' => 'settings',
        'tab' => 'ingest',
        'tenant' => current_tenant(),
        'keys' => q_tenant('SELECT * FROM api_keys WHERE tenant_id = :tid ORDER BY active DESC, id DESC')->fetchAll(),
        'sources' => q_tenant("SELECT s.*, (SELECT COUNT(*) FROM ingest_log g WHERE g.tenant_id = s.tenant_id AND g.source_id = s.id
                               AND g.created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY) AS hits_7d
                               FROM ingest_sources s WHERE s.tenant_id = :tid ORDER BY s.label")->fetchAll(),
        'logs' => q_tenant("SELECT id, source, external_id, status, attempts, error, lead_id, created_at, next_retry_at
                            FROM ingest_log WHERE $logWhere ORDER BY id DESC LIMIT 50", $params)->fetchAll(),
        'status' => $status,
        'statuses' => $statuses,
        'adapters' => ingest_adapters(),
        'shown_token' => $_SESSION['shown_token'] ?? null,
    ]);
    unset($_SESSION['shown_token']);
}

function page_ingest_key_create(): never
{
    $name = str_input('name', 100);
    if ($name === '') {
        flash('error', 'Beri nama token, misalnya "Landing page" atau "Gateway WA".');
        redirect('/settings/ingest');
    }
    $token = new_api_token();
    q_tenant("INSERT INTO api_keys (tenant_id, name, key_prefix, key_hash, scopes) VALUES (:tid, :n, :p, :h, 'ingest')", [
        'n' => $name, 'p' => substr($token, 0, 12), 'h' => token_hash($token),
    ]);
    audit('api_key_create', 'api_key', db()->lastInsertId(), ['name' => $name]);
    $_SESSION['shown_token'] = ['name' => $name, 'token' => $token];
    flash('success', 'Token dibuat. Salin sekarang, token hanya tampil sekali.');
    redirect('/settings/ingest');
}

function page_ingest_key_revoke(int $id): never
{
    $n = q_tenant('UPDATE api_keys SET active = 0 WHERE tenant_id = :tid AND id = :id AND active = 1', ['id' => $id])->rowCount();
    if ($n) {
        audit('api_key_revoke', 'api_key', $id);
        flash('success', 'Token dicabut. Request dengan token itu akan ditolak.');
    }
    redirect('/settings/ingest');
}

function page_ingest_source_create(): never
{
    $slug = strtolower(str_input('slug', 64));
    $label = str_input('label', 100);
    $adapter = str_input('adapter', 32);
    $adapters = ingest_adapters();
    if (!preg_match('/^[a-z0-9-]{2,64}$/', $slug) || $label === '' || !isset($adapters[$adapter])) {
        flash('error', 'Isi kode sumber (huruf kecil, angka, minus), nama, dan jenis.');
        redirect('/settings/ingest');
    }
    if (q_tenant('SELECT 1 FROM ingest_sources WHERE tenant_id = :tid AND slug = :s', ['s' => $slug])->fetchColumn()) {
        flash('error', 'Kode sumber sudah dipakai.');
        redirect('/settings/ingest');
    }
    $leadSource = $adapter === 'google_lead_form' ? 'gads' : ($adapter === 'qontak' ? 'ctwa' : 'lp');
    q_tenant('INSERT INTO ingest_sources (tenant_id, slug, label, adapter, auth_type, mapping_json, lead_source)
              VALUES (:tid, :s, :l, :a, :auth, :m, :ls)', [
        's' => $slug, 'l' => $label, 'a' => $adapter, 'auth' => $adapters[$adapter]['auth'],
        'm' => json_encode($adapters[$adapter]['mapping'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 'ls' => $leadSource,
    ]);
    $id = (int) db()->lastInsertId();
    audit('ingest_source_create', 'ingest_source', $id, ['slug' => $slug, 'adapter' => $adapter]);
    flash('success', 'Sumber dibuat. Cek mapping dan keamanan di bawah.');
    redirect('/settings/ingest/sources/' . $id);
}

function source_or_404(int $id): array
{
    $s = q_tenant('SELECT * FROM ingest_sources WHERE tenant_id = :tid AND id = :id', ['id' => $id])->fetch();
    if (!$s) {
        error_page(404);
    }
    return $s;
}

function page_ingest_source_show(int $id, ?array $test = null, ?string $samplePayload = null): void
{
    $source = source_or_404($id);
    $last = q_tenant('SELECT id, payload, status, created_at FROM ingest_log
                      WHERE tenant_id = :tid AND source_id = :s AND payload IS NOT NULL ORDER BY id DESC LIMIT 1', ['s' => $id])->fetch();
    render('settings/ingest_source', [
        'title' => 'Sumber: ' . $source['label'],
        'nav' => 'settings',
        'tab' => 'ingest',
        'source' => $source,
        'endpoint' => ingest_endpoint_url(current_tenant()['slug'], $source),
        'adapters' => ingest_adapters(),
        'last' => $last,
        'test' => $test,
        'sample' => $samplePayload ?? ($last['payload'] ?? ''),
        'has_secret' => $source['secret_enc'] !== null,
    ]);
}

function page_ingest_source_update(int $id): never
{
    $source = source_or_404($id);
    $label = str_input('label', 100);
    $auth = str_input('auth_type', 20);
    $sig = str_input('signature_type', 20);
    $leadSource = str_input('lead_source', 20);
    $mappingRaw = (string) ($_POST['mapping_json'] ?? '');
    $active = isset($_POST['active']) ? 1 : 0;

    $mapping = json_decode($mappingRaw, true);
    if ($source['adapter'] !== 'google_lead_form' && (!is_array($mapping) || empty($mapping['phone_path']))) {
        flash('error', 'Mapping harus JSON valid dan minimal berisi phone_path.');
        redirect('/settings/ingest/sources/' . $id);
    }
    if ($label === '' || !in_array($auth, ['bearer', 'query', 'google_key'], true) || !in_array($sig, ['none', 'hmac_sha256'], true) || !isset(source_labels()[$leadSource])) {
        flash('error', 'Data tidak lengkap.');
        redirect('/settings/ingest/sources/' . $id);
    }

    $params = [
        'l' => $label, 'a' => $auth, 'sig' => $sig, 'ls' => $leadSource, 'act' => $active, 'id' => $id,
        'm' => json_encode($mapping ?: new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ];
    $secretSql = '';
    $newSecret = (string) ($_POST['secret'] ?? '');
    if (isset($_POST['clear_secret'])) {
        $secretSql = ', secret_enc = NULL';
    } elseif ($newSecret !== '') {
        $secretSql = ', secret_enc = :sec';
        $params['sec'] = encrypt_secret($newSecret);
    }
    $hasSecret = isset($params['sec']) || ($source['secret_enc'] !== null && !isset($_POST['clear_secret']));
    if (($sig === 'hmac_sha256' || $auth === 'google_key') && !$hasSecret) {
        flash('error', 'Signature HMAC dan google_key butuh secret. Isi secret dulu.');
        redirect('/settings/ingest/sources/' . $id);
    }

    q_tenant("UPDATE ingest_sources SET label = :l, auth_type = :a, signature_type = :sig, lead_source = :ls,
              active = :act, mapping_json = :m $secretSql WHERE tenant_id = :tid AND id = :id", $params);
    audit('ingest_source_update', 'ingest_source', $id, ['auth' => $auth, 'signature' => $sig, 'active' => $active, 'secret_changed' => $secretSql !== '']);
    flash('success', 'Sumber tersimpan.');
    redirect('/settings/ingest/sources/' . $id);
}

/** Uji mapping dengan contoh payload tanpa menyimpan lead. */
function page_ingest_source_test(int $id): void
{
    $source = source_or_404($id);
    $sample = (string) ($_POST['sample'] ?? '');
    $payload = json_decode($sample, true);
    $test = is_array($payload) ? ingest_normalize($source, $payload) : ['error' => 'Contoh payload bukan JSON valid.'];
    page_ingest_source_show($id, $test, $sample);
}

function page_ingest_log_show(int $id): void
{
    $row = q_tenant('SELECT * FROM ingest_log WHERE tenant_id = :tid AND id = :id', ['id' => $id])->fetch();
    if (!$row) {
        error_page(404);
    }
    $pretty = $row['payload'];
    $decoded = $row['payload'] !== null ? json_decode($row['payload'], true) : null;
    if (is_array($decoded)) {
        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    audit('ingest_payload_view', 'ingest_log', $id);
    render('settings/ingest_log', [
        'title' => 'Log masuk #' . $id,
        'nav' => 'settings',
        'tab' => 'ingest',
        'row' => $row,
        'pretty' => $pretty,
    ]);
}

function page_ingest_log_retry(int $id): never
{
    $n = q_tenant("UPDATE ingest_log SET status = 'retry', attempts = 0, next_retry_at = UTC_TIMESTAMP()
                   WHERE tenant_id = :tid AND id = :id AND status = 'failed' AND payload IS NOT NULL", ['id' => $id])->rowCount();
    if ($n) {
        audit('ingest_retry', 'ingest_log', $id);
        flash('success', 'Dijadwalkan ulang. Worker akan memproses dalam 1 menit.');
    } else {
        flash('error', 'Hanya log berstatus failed dengan payload tersimpan yang bisa diulang.');
    }
    redirect('/settings/ingest/log/' . $id);
}
