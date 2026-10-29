<?php
declare(strict_types=1);

const DEFAULT_STAGES = [
    ['baru', 'Baru', 'open'],
    ['dihubungi', 'Dihubungi', 'open'],
    ['qualified', 'Qualified', 'open'],
    ['quotation', 'Quotation', 'open'],
    ['closing', 'Closing', 'won'],
    ['lost', 'Lost', 'lost'],
];

const ATTRIBUTION_FIELDS = ['src_tag', 'ctwa_clid', 'ad_id', 'fbclid', 'gclid', 'gbraid', 'wbraid', 'ttclid', 'landing_url', 'referrer', 'user_agent'];

/** Dipanggil saat tenant dibuat. Butuh konteks tenant sudah diset. */
function create_default_pipeline(): int
{
    q_tenant('INSERT INTO pipelines (tenant_id, name, is_default) VALUES (:tid, :name, 1)', ['name' => 'Pipeline utama']);
    $pipelineId = (int) db()->lastInsertId();
    foreach (DEFAULT_STAGES as $i => [$key, $label, $kind]) {
        q_tenant('INSERT INTO pipeline_stages (tenant_id, pipeline_id, stage_key, label, sort, kind)
                  VALUES (:tid, :p, :k, :l, :s, :kind)', ['p' => $pipelineId, 'k' => $key, 'l' => $label, 's' => ($i + 1) * 10, 'kind' => $kind]);
    }
    return $pipelineId;
}

function default_pipeline_id(): int
{
    $id = q_tenant('SELECT id FROM pipelines WHERE tenant_id = :tid ORDER BY is_default DESC, id LIMIT 1')->fetchColumn();
    return $id ? (int) $id : create_default_pipeline();
}

/** Stage pipeline default, urut, di-key dengan stage_key. */
function pipeline_stages(?int $pipelineId = null): array
{
    $pipelineId ??= default_pipeline_id();
    $rows = q_tenant('SELECT * FROM pipeline_stages WHERE tenant_id = :tid AND pipeline_id = :p ORDER BY sort, id', ['p' => $pipelineId])->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['stage_key']] = $r;
    }
    return $out;
}

/** Lead baru selalu masuk stage 'baru' (atau stage open pertama kalau 'baru' dihapus). */
function entry_stage(array $stages): string
{
    if (isset($stages['baru'])) {
        return 'baru';
    }
    foreach ($stages as $key => $s) {
        if ($s['kind'] === 'open') {
            return $key;
        }
    }
    return (string) array_key_first($stages);
}

function tenant_members(): array
{
    return q_tenant("SELECT u.id, u.name, tu.role FROM tenant_users tu JOIN users u ON u.id = tu.user_id
                     WHERE tu.tenant_id = :tid AND u.active = 1 ORDER BY u.name")->fetchAll();
}

function find_lead(int $id): ?array
{
    $row = q_tenant('SELECT * FROM leads WHERE tenant_id = :tid AND id = :id', ['id' => $id])->fetch();
    return $row ?: null;
}

function lead_log(int $leadId, string $type, ?string $detail = null, ?int $userId = null): void
{
    q_tenant('INSERT INTO lead_activity (tenant_id, lead_id, user_id, type, detail) VALUES (:tid, :l, :u, :t, :d)', [
        'l' => $leadId, 'u' => $userId, 't' => $type, 'd' => $detail,
    ]);
}

/**
 * Buat lead manual. Mengembalikan [leadId, null] atau [existingIdAtauNull, pesanError].
 */
function lead_create_manual(array $input, int $userId): array
{
    $phone = normalize_phone($input['phone'] ?? null);
    if ($phone === null) {
        return [null, 'Nomor HP tidak valid. Gunakan nomor Indonesia, misalnya 0812xxxx atau 62812xxxx.'];
    }
    $existing = q_tenant('SELECT id FROM leads WHERE tenant_id = :tid AND phone = :p', ['p' => $phone])->fetchColumn();
    if ($existing) {
        return [(int) $existing, 'Nomor ini sudah ada di tenant ini.'];
    }
    $source = array_key_exists($input['source'] ?? '', source_labels()) ? $input['source'] : 'manual';
    $pipelineId = default_pipeline_id();
    $stage = entry_stage(pipeline_stages($pipelineId));

    return tx(function () use ($input, $userId, $phone, $source, $pipelineId, $stage) {
        q_tenant('INSERT INTO leads (tenant_id, pipeline_id, phone, name, source, src_tag, stage, stage_at, touched_at,
                                     assigned_to, product, city, next_followup)
                  VALUES (:tid, :pl, :phone, :name, :source, :tag, :stage, UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                          :assigned, :product, :city, :fu)', [
            'pl' => $pipelineId,
            'phone' => $phone,
            'name' => $input['name'] ?: null,
            'source' => $source,
            'tag' => $input['src_tag'] ?: null,
            'stage' => $stage,
            'assigned' => $input['assigned_to'] ?: null,
            'product' => $input['product'] ?: null,
            'city' => $input['city'] ?: null,
            'fu' => $input['next_followup'] ?: null,
        ]);
        $id = (int) db()->lastInsertId();
        lead_log($id, 'created', 'Dibuat manual', $userId);
        audit('lead_create', 'lead', $id, ['via' => 'manual']);
        return [$id, null];
    });
}

/**
 * Simpan hasil normalisasi webhook. Nomor yang sudah ada tidak dibuat ulang:
 * hanya atribusi yang masih kosong yang diisi. Stage dan nominal tidak pernah diambil dari luar.
 */
function lead_upsert_from_ingest(array $norm, array $source): array
{
    $existing = q_tenant('SELECT * FROM leads WHERE tenant_id = :tid AND phone = :p FOR UPDATE', ['p' => $norm['phone']])->fetch();

    $attr = [];
    foreach (ATTRIBUTION_FIELDS as $f) {
        $v = $norm[$f] ?? null;
        $attr[$f] = ($v === null || $v === '') ? null : mb_substr((string) $v, 0, $f === 'landing_url' || $f === 'referrer' ? 2000 : 500);
    }

    if ($existing) {
        $sets = [];
        $params = ['id' => (int) $existing['id']];
        foreach ($attr as $f => $v) {
            if ($v !== null && ($existing[$f] === null || $existing[$f] === '')) {
                $sets[] = "$f = :$f";
                $params[$f] = $v;
            }
        }
        if (!empty($norm['name']) && empty($existing['name'])) {
            $sets[] = 'name = :name';
            $params['name'] = mb_substr($norm['name'], 0, 150);
        }
        $sets[] = 'touched_at = UTC_TIMESTAMP()';
        q_tenant('UPDATE leads SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tid AND id = :id', $params);
        lead_log((int) $existing['id'], 'ingest_repeat', ingest_activity_text($norm, $source));
        return [(int) $existing['id'], false];
    }

    $pipelineId = default_pipeline_id();
    $leadSource = $source['lead_source'];
    if (!empty($attr['ctwa_clid'])) {
        $leadSource = 'ctwa';
    }
    $cols = ['tenant_id', 'pipeline_id', 'phone', 'name', 'source', 'stage', 'stage_at', 'touched_at'];
    $vals = [':tid', ':pl', ':phone', ':name', ':source', ':stage', 'UTC_TIMESTAMP()', 'UTC_TIMESTAMP()'];
    $params = [
        'pl' => $pipelineId,
        'phone' => $norm['phone'],
        'name' => !empty($norm['name']) ? mb_substr($norm['name'], 0, 150) : null,
        'source' => $leadSource,
        'stage' => entry_stage(pipeline_stages($pipelineId)),
    ];
    foreach ($attr as $f => $v) {
        $cols[] = $f;
        $vals[] = ':' . $f;
        $params[$f] = $v;
    }
    q_tenant('INSERT INTO leads (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')', $params);
    $id = (int) db()->lastInsertId();
    lead_log($id, 'created', ingest_activity_text($norm, $source));
    return [$id, true];
}

function ingest_activity_text(array $norm, array $source): string
{
    $text = 'Masuk lewat webhook ' . $source['label'];
    if (!empty($norm['campaign'])) {
        $text .= ', campaign: ' . mb_substr($norm['campaign'], 0, 150);
    }
    if (!empty($norm['message'])) {
        $text .= "\nPesan: " . mb_substr($norm['message'], 0, 500);
    }
    return $text;
}

/** Pindah stage. qualified_at dan deal_at diisi sekali saat pertama kali tercapai. */
function lead_change_stage(array $lead, string $stageKey, int $userId, ?string $lostReason): ?string
{
    $stages = pipeline_stages((int) $lead['pipeline_id']);
    if (!isset($stages[$stageKey])) {
        return 'Stage tidak dikenal.';
    }
    if ($stageKey === $lead['stage']) {
        return null;
    }
    $target = $stages[$stageKey];
    if ($target['kind'] === 'lost' && ($lostReason === null || $lostReason === '')) {
        return 'Isi alasan lost dulu.';
    }

    $sets = ['stage = :stage', 'stage_at = UTC_TIMESTAMP()', 'touched_at = UTC_TIMESTAMP()'];
    $params = ['stage' => $stageKey, 'id' => (int) $lead['id']];
    $qualifiedSort = isset($stages['qualified']) ? (int) $stages['qualified']['sort'] : null;
    if ($lead['qualified_at'] === null && $target['kind'] !== 'lost' && $qualifiedSort !== null && (int) $target['sort'] >= $qualifiedSort) {
        $sets[] = 'qualified_at = UTC_TIMESTAMP()';
    }
    if ($lead['deal_at'] === null && $target['kind'] === 'won') {
        $sets[] = 'deal_at = UTC_TIMESTAMP()';
    }
    if ($target['kind'] === 'lost') {
        $sets[] = 'lost_reason = :lr';
        $params['lr'] = $lostReason;
    }

    tx(function () use ($sets, $params, $lead, $stages, $stageKey, $userId, $lostReason) {
        q_tenant('UPDATE leads SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tid AND id = :id', $params);
        $from = $stages[$lead['stage']]['label'] ?? $lead['stage'];
        $detail = $from . ' ke ' . $stages[$stageKey]['label'] . ($lostReason ? " (alasan: $lostReason)" : '');
        lead_log((int) $lead['id'], 'stage', $detail, $userId);
        audit('lead_stage', 'lead', $lead['id'], ['from' => $lead['stage'], 'to' => $stageKey]);
    });
    return null;
}

const LEAD_EDITABLE = ['name', 'product', 'city', 'assigned_to', 'quote_value', 'deal_value', 'next_followup', 'lost_reason'];

function lead_update_fields(array $lead, array $input, int $userId): ?string
{
    if ($input['assigned_to'] !== null) {
        $memberIds = array_map(fn($m) => (int) $m['id'], tenant_members());
        if (!in_array((int) $input['assigned_to'], $memberIds, true)) {
            return 'Penanggung jawab harus user di tenant ini.';
        }
    }
    if ($input['next_followup'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['next_followup'])) {
        return 'Format tanggal follow up tidak valid.';
    }

    $changes = [];
    foreach (LEAD_EDITABLE as $f) {
        $old = $lead[$f] === null ? null : (string) $lead[$f];
        $new = $input[$f] === null ? null : (string) $input[$f];
        if (in_array($f, ['quote_value', 'deal_value'], true) && $old !== null) {
            $old = (string) (int) round((float) $old);
        }
        if ($old !== $new) {
            $changes[$f] = ['dari' => $old, 'ke' => $new];
        }
    }
    if (!$changes) {
        return null;
    }

    tx(function () use ($lead, $input, $changes, $userId) {
        $sets = [];
        $params = ['id' => (int) $lead['id']];
        foreach (array_keys($changes) as $f) {
            $sets[] = "$f = :$f";
            $params[$f] = $input[$f];
        }
        $sets[] = 'touched_at = UTC_TIMESTAMP()';
        q_tenant('UPDATE leads SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tid AND id = :id', $params);
        lead_log((int) $lead['id'], 'update', 'Ubah: ' . implode(', ', array_keys($changes)), $userId);
        audit('lead_update', 'lead', $lead['id'], $changes);
    });
    return null;
}
