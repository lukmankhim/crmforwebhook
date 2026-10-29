<?php
declare(strict_types=1);

const IMPORT_MAX_BYTES = 2 * 1024 * 1024;
const IMPORT_MAX_ROWS = 5000;

/** Field yang bisa diisi dari CSV. Import hanya untuk admin, jadi stage dan nominal boleh dibawa dari sistem lama. */
function import_fields(): array
{
    return [
        'phone' => 'Nomor HP (wajib)',
        'name' => 'Nama',
        'product' => 'Produk',
        'city' => 'Kota',
        'src_tag' => 'Tag sumber / campaign',
        'stage' => 'Stage',
        'quote_value' => 'Nilai quotation',
        'deal_value' => 'Nilai deal',
        'next_followup' => 'Follow up berikutnya',
        'created_at' => 'Tanggal masuk',
    ];
}

/** Tebak kolom dari judul. Urutan penting: pola yang lebih spesifik dicek dulu. */
function import_guess_field(string $header): ?string
{
    $h = strtolower(trim($header));
    $rules = [
        'next_followup' => '/follow|fu\b/',
        'quote_value' => '/quot|penawaran/',
        'deal_value' => '/deal|closing|nilai/',
        'phone' => '/\bhp\b|phone|telp|telepon|nomor|no\.? ?wa|whatsapp|\bwa\b|mobile/',
        'name' => '/^nama|name/',
        'product' => '/produk|product/',
        'city' => '/kota|city/',
        'stage' => '/stage|status|tahap/',
        'src_tag' => '/campaign|sumber|source|tag|utm/',
        'created_at' => '/tanggal|date|created|masuk|waktu/',
    ];
    foreach ($rules as $field => $re) {
        if (preg_match($re, $h)) {
            return $field;
        }
    }
    return null;
}

/** Baca file upload, ubah ke UTF-8, deteksi pemisah. Mengembalikan [rows, delimiter] atau string error. */
function import_read_csv(string $path): array|string
{
    $raw = (string) file_get_contents($path);
    if ($raw === '') {
        return 'File kosong.';
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $firstLine = strtok($raw, "\n") ?: '';
    $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
    arsort($counts);
    $delimiter = (string) array_key_first($counts);

    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $rows = [];
    while (($row = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
        if ($row === [null] || implode('', array_map('trim', $row)) === '') {
            continue;
        }
        $rows[] = array_map(fn($v) => trim((string) $v), $row);
        if (count($rows) > IMPORT_MAX_ROWS + 1) {
            fclose($fh);
            return 'Maksimal ' . number_format(IMPORT_MAX_ROWS, 0, ',', '.') . ' baris per file. Pecah file jadi beberapa bagian.';
        }
    }
    fclose($fh);
    if (!$rows) {
        return 'Tidak ada baris yang terbaca.';
    }
    return [$rows, $delimiter];
}

function import_dir(): string
{
    $dir = storage_path('imports');
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

/** File import disimpan per tenant di luar docroot, dirujuk lewat token acak di session. */
function import_file_path(string $token): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        throw new RuntimeException('Token import tidak valid');
    }
    return import_dir() . '/' . tenant_id() . '-' . $token . '.json';
}

function import_parse_date(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y', 'j/n/Y'] as $fmt) {
        $dt = DateTimeImmutable::createFromFormat('!' . $fmt, $value, tenant_tz());
        $errors = DateTimeImmutable::getLastErrors();
        if ($dt !== false && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $dt;
        }
    }
    return null;
}

function import_money(string $value): ?string
{
    $digits = preg_replace('/[^\d]/', '', preg_replace('/[,.]\d{1,2}$/', '', trim($value)));
    return $digits === '' ? null : $digits;
}

/**
 * Proses baris CSV jadi lead. Nomor yang sudah ada di tenant dilewati (tidak ditimpa).
 * $map: field => index kolom.
 */
function import_run(array $rows, array $map, string $fileName, int $userId): array
{
    @set_time_limit(300);
    $pipelineId = default_pipeline_id();
    $stages = pipeline_stages($pipelineId);
    $entry = entry_stage($stages);
    $stageLookup = [];
    foreach ($stages as $key => $s) {
        $stageLookup[strtolower($key)] = $key;
        $stageLookup[strtolower($s['label'])] = $key;
    }
    $qualifiedSort = isset($stages['qualified']) ? (int) $stages['qualified']['sort'] : null;

    $existing = array_flip(q_tenant('SELECT phone FROM leads WHERE tenant_id = :tid')->fetchAll(PDO::FETCH_COLUMN));
    $result = ['inserted' => 0, 'existing' => 0, 'dup_in_file' => 0, 'invalid' => [], 'unknown_stage' => 0, 'bad_date' => 0, 'scientific' => false];
    $seen = [];
    $get = fn(array $row, string $field) => isset($map[$field]) ? (string) ($row[$map[$field]] ?? '') : '';
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    tx(function () use ($rows, $get, $stages, $stageLookup, $entry, $qualifiedSort, $pipelineId, $fileName, $now, &$existing, &$seen, &$result) {
        foreach ($rows as $lineNo => $row) {
            $rawPhone = $get($row, 'phone');
            $phone = normalize_phone($rawPhone);
            if ($phone === null) {
                if (preg_match('/\d[,.]\d+E\+?\d+/i', $rawPhone)) {
                    $result['scientific'] = true;
                }
                if (count($result['invalid']) < 50) {
                    $result['invalid'][] = $lineNo;
                } else {
                    $result['invalid_more'] = true;
                }
                $result['invalid_count'] = ($result['invalid_count'] ?? 0) + 1;
                continue;
            }
            if (isset($seen[$phone])) {
                $result['dup_in_file']++;
                continue;
            }
            $seen[$phone] = true;
            if (isset($existing[$phone])) {
                $result['existing']++;
                continue;
            }

            $stageRaw = strtolower(trim($get($row, 'stage')));
            $stage = $entry;
            if ($stageRaw !== '') {
                if (isset($stageLookup[$stageRaw])) {
                    $stage = $stageLookup[$stageRaw];
                } else {
                    $result['unknown_stage']++;
                }
            }
            $created = $now;
            $dateRaw = $get($row, 'created_at');
            if ($dateRaw !== '') {
                $parsed = import_parse_date($dateRaw);
                if ($parsed) {
                    $created = $parsed->setTimezone(new DateTimeZone('UTC'));
                } else {
                    $result['bad_date']++;
                }
            }
            $createdSql = $created->format('Y-m-d H:i:s');
            $fu = import_parse_date($get($row, 'next_followup'));
            $target = $stages[$stage];
            $qualified = $target['kind'] !== 'lost' && $qualifiedSort !== null && (int) $target['sort'] >= $qualifiedSort;

            q_tenant('INSERT INTO leads (tenant_id, pipeline_id, phone, name, source, src_tag, stage, stage_at, qualified_at, deal_at,
                                         touched_at, product, city, quote_value, deal_value, lost_reason, next_followup, created_at)
                      VALUES (:tid, :pl, :phone, :name, \'import\', :tag, :stage, :c1, :qa, :da, :c2, :product, :city, :qv, :dv, :lr, :fu, :c3)', [
                'pl' => $pipelineId,
                'phone' => $phone,
                'name' => mb_substr($get($row, 'name'), 0, 150) ?: null,
                'tag' => mb_substr($get($row, 'src_tag'), 0, 150) ?: null,
                'stage' => $stage,
                'c1' => $createdSql,
                'qa' => $qualified ? $createdSql : null,
                'da' => $target['kind'] === 'won' ? $createdSql : null,
                'c2' => $createdSql,
                'product' => mb_substr($get($row, 'product'), 0, 150) ?: null,
                'city' => mb_substr($get($row, 'city'), 0, 100) ?: null,
                'qv' => import_money($get($row, 'quote_value')),
                'dv' => import_money($get($row, 'deal_value')),
                'lr' => $target['kind'] === 'lost' ? 'Import CSV' : null,
                'fu' => $fu?->format('Y-m-d'),
                'c3' => $createdSql,
            ]);
            lead_log((int) db()->lastInsertId(), 'created', 'Import CSV: ' . mb_substr($fileName, 0, 100));
            $existing[$phone] = true;
            $result['inserted']++;
        }
    });
    audit('lead_import', 'lead', null, [
        'file' => mb_substr($fileName, 0, 100), 'inserted' => $result['inserted'], 'existing' => $result['existing'],
        'invalid' => $result['invalid_count'] ?? 0, 'dup_in_file' => $result['dup_in_file'],
    ]);
    return $result;
}
