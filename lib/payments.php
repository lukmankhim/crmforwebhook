<?php
declare(strict_types=1);

const PAYMENT_KINDS = ['dp' => 'DP', 'pelunasan' => 'Pelunasan', 'lainnya' => 'Pembayaran'];
const PAYMENT_METHODS = ['Transfer bank', 'QRIS', 'Tunai', 'E-wallet', 'Lainnya'];
const EMAIL_STATUS_LABELS = [
    'queued' => 'Menunggu kirim', 'sent' => 'Terkirim ke Brevo', 'delivered' => 'Sampai', 'opened' => 'Dibuka',
    'bounced' => 'Mental', 'spam' => 'Ditandai spam', 'blocked' => 'Diblokir', 'failed' => 'Gagal',
];

/** Angka ke kata Rupiah: 1500000 -> "satu juta lima ratus ribu rupiah". */
function terbilang(int $n): string
{
    $words = trim(terbilang_raw($n));
    return ($words === '' ? 'nol' : $words) . ' rupiah';
}

function terbilang_raw(int $n): string
{
    $units = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($n < 12) {
        return $units[$n];
    }
    if ($n < 20) {
        return terbilang_raw($n - 10) . ' belas';
    }
    if ($n < 100) {
        return terbilang_raw(intdiv($n, 10)) . ' puluh ' . terbilang_raw($n % 10);
    }
    if ($n < 200) {
        return 'seratus ' . terbilang_raw($n - 100);
    }
    if ($n < 1000) {
        return terbilang_raw(intdiv($n, 100)) . ' ratus ' . terbilang_raw($n % 100);
    }
    if ($n < 2000) {
        return 'seribu ' . terbilang_raw($n - 1000);
    }
    foreach ([1000000000000 => 'triliun', 1000000000 => 'miliar', 1000000 => 'juta', 1000 => 'ribu'] as $size => $name) {
        if ($n >= $size) {
            return trim(terbilang_raw(intdiv($n, $size))) . " $name " . terbilang_raw($n % $size);
        }
    }
    return '';
}

function payment_receipt_url(array $payment): string
{
    return rtrim((string) config('app_url'), '/') . '/r/' . $payment['public_token'];
}

/** Nomor berikutnya, aman dari dobel karena dikunci baris counter di dalam transaksi pemanggil. */
function next_receipt_no(): string
{
    $now = new DateTimeImmutable('now', tenant_tz());
    $period = $now->format('Ym');
    // Upsert mengunci baris counter sampai transaksi selesai, jadi SELECT berikutnya membaca nomor milik kita.
    q_tenant('INSERT INTO receipt_counters (tenant_id, period, last_no) VALUES (:tid, :p, 1)
              ON DUPLICATE KEY UPDATE last_no = last_no + 1', ['p' => $period]);
    $no = (int) q_tenant('SELECT last_no FROM receipt_counters WHERE tenant_id = :tid AND period = :p', ['p' => $period])->fetchColumn();
    $prefix = preg_replace('/[^A-Z0-9-]/', '', strtoupper(tenant_setting('receipt_prefix'))) ?: 'KW';
    return sprintf('%s/%s/%s/%04d', $prefix, $now->format('Y'), $now->format('m'), $no);
}

function lead_payments(int $leadId): array
{
    return q_tenant('SELECT p.*, u.name AS user_name,
                            (SELECT m.status FROM email_messages m WHERE m.tenant_id = p.tenant_id AND m.payment_id = p.id ORDER BY m.id DESC LIMIT 1) AS email_status,
                            (SELECT m.to_email FROM email_messages m WHERE m.tenant_id = p.tenant_id AND m.payment_id = p.id ORDER BY m.id DESC LIMIT 1) AS email_to
                     FROM payments p LEFT JOIN users u ON u.id = p.created_by
                     WHERE p.tenant_id = :tid AND p.lead_id = :l ORDER BY p.paid_on DESC, p.id DESC', ['l' => $leadId])->fetchAll();
}

/** @return array{paid: float, deal: ?float, remaining: ?float} */
function lead_payment_summary(array $lead): array
{
    $paid = (float) q_tenant('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE tenant_id = :tid AND lead_id = :l AND voided_at IS NULL',
        ['l' => $lead['id']])->fetchColumn();
    $deal = $lead['deal_value'] !== null ? (float) $lead['deal_value'] : null;
    return ['paid' => $paid, 'deal' => $deal, 'remaining' => $deal !== null ? max(0.0, $deal - $paid) : null];
}

function lead_wants_receipt(array $lead): bool
{
    return $lead['receipt_optin_at'] !== null && $lead['email'] !== null && $lead['email'] !== '' && $lead['email_bounced_at'] === null;
}

/**
 * Catat pembayaran. Mengembalikan [paymentId, null] atau [null, pesanError].
 * Email kuitansi dan konversi iklan dimasukkan antrian, dikirim worker.
 */
function payment_create(array $lead, array $in, int $userId): array
{
    if (!isset(PAYMENT_KINDS[$in['kind']])) {
        return [null, 'Pilih jenis pembayaran.'];
    }
    $amount = (int) ($in['amount'] ?? 0);
    if ($amount <= 0 || $amount > 999999999999) {
        return [null, 'Nominal harus lebih dari 0.'];
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $in['paid_on']);
    if (!$date || $date->format('Y-m-d') !== $in['paid_on']) {
        return [null, 'Tanggal bayar tidak valid.'];
    }
    if ($date > new DateTimeImmutable('tomorrow', tenant_tz())) {
        return [null, 'Tanggal bayar tidak boleh di masa depan.'];
    }
    if ($in['method'] === '') {
        return [null, 'Isi metode pembayaran.'];
    }

    return tx(function () use ($lead, $in, $amount, $userId) {
        q_tenant('SELECT id FROM leads WHERE tenant_id = :tid AND id = :id FOR UPDATE', ['id' => $lead['id']]);
        $receiptNo = next_receipt_no();
        q_tenant('INSERT INTO payments (tenant_id, lead_id, kind, amount, paid_on, method, description, note, receipt_no, public_token, created_by)
                  VALUES (:tid, :l, :k, :a, :d, :m, :desc, :n, :no, :tok, :u)', [
            'l' => $lead['id'], 'k' => $in['kind'], 'a' => $amount, 'd' => $in['paid_on'], 'm' => $in['method'],
            'desc' => $in['description'] ?: null, 'n' => $in['note'] ?: null, 'no' => $receiptNo,
            'tok' => bin2hex(random_bytes(32)), 'u' => $userId,
        ]);
        $id = (int) db()->lastInsertId();
        $text = PAYMENT_KINDS[$in['kind']] . ' ' . fmt_money($amount) . " ({$in['method']}), kuitansi {$receiptNo}";
        lead_log((int) $lead['id'], 'payment', $text, $userId);
        audit('payment_create', 'payment', $id, ['lead' => (int) $lead['id'], 'kind' => $in['kind'], 'amount' => $amount, 'receipt' => $receiptNo]);
        if (lead_wants_receipt($lead)) {
            receipt_email_queue($id, (string) $lead['email']);
        }
        conversion_queue_purchase($lead, $id, $amount, $in['paid_on']);
        q_tenant('UPDATE leads SET touched_at = UTC_TIMESTAMP() WHERE tenant_id = :tid AND id = :id', ['id' => $lead['id']]);
        return [$id, null];
    });
}

function find_payment(int $id): ?array
{
    $row = q_tenant('SELECT * FROM payments WHERE tenant_id = :tid AND id = :id', ['id' => $id])->fetch();
    return $row ?: null;
}

function payment_void(array $payment, string $reason, int $userId): ?string
{
    if ($payment['voided_at'] !== null) {
        return 'Kuitansi ini sudah dibatalkan.';
    }
    if ($reason === '') {
        return 'Isi alasan pembatalan.';
    }
    tx(function () use ($payment, $reason, $userId) {
        q_tenant('UPDATE payments SET voided_at = UTC_TIMESTAMP(), void_reason = :r WHERE tenant_id = :tid AND id = :id',
            ['r' => $reason, 'id' => $payment['id']]);
        q_tenant("UPDATE email_messages SET status = 'failed', error = 'Kuitansi dibatalkan sebelum terkirim'
                  WHERE tenant_id = :tid AND payment_id = :p AND status = 'queued'", ['p' => $payment['id']]);
        q_tenant("UPDATE conversion_events SET status = 'cancelled'
                  WHERE tenant_id = :tid AND payment_id = :p AND status IN ('waiting_config', 'pending', 'failed')", ['p' => $payment['id']]);
        lead_log((int) $payment['lead_id'], 'payment_void', "Kuitansi {$payment['receipt_no']} dibatalkan: {$reason}", $userId);
        audit('payment_void', 'payment', $payment['id'], ['reason' => $reason]);
    });
    return null;
}

function receipt_email_queue(int $paymentId, string $to): void
{
    q_tenant("INSERT INTO email_messages (tenant_id, payment_id, to_email, status, next_try_at)
              VALUES (:tid, :p, :e, 'queued', UTC_TIMESTAMP())", ['p' => $paymentId, 'e' => $to]);
}

function payment_resend(array $payment, array $lead, int $userId): ?string
{
    if ($payment['voided_at'] !== null) {
        return 'Kuitansi yang dibatalkan tidak bisa dikirim.';
    }
    if ($lead['email'] === null || $lead['email'] === '') {
        return 'Lead ini belum punya email.';
    }
    $pending = q_tenant("SELECT 1 FROM email_messages WHERE tenant_id = :tid AND payment_id = :p AND status = 'queued'",
        ['p' => $payment['id']])->fetchColumn();
    if ($pending) {
        return 'Email kuitansi ini masih di antrian.';
    }
    receipt_email_queue((int) $payment['id'], (string) $lead['email']);
    lead_log((int) $lead['id'], 'payment', "Kirim ulang kuitansi {$payment['receipt_no']} ke {$lead['email']}", $userId);
    audit('payment_resend', 'payment', $payment['id'], ['to' => $lead['email']]);
    return null;
}

/** Data siap tampil untuk halaman dan email kuitansi. */
function receipt_view_data(array $payment): array
{
    $lead = find_lead((int) $payment['lead_id']);
    $settings = tenant_settings();
    $tenant = current_tenant();
    return [
        'payment' => $payment,
        'lead' => $lead,
        'summary' => lead_payment_summary($lead),
        'business' => $settings['receipt_business_name'] !== '' ? $settings['receipt_business_name'] : $tenant['name'],
        'address' => $settings['receipt_address'],
        'contact' => $settings['receipt_contact'],
        'footer' => $settings['receipt_footer'],
        'logo' => receipt_logo_path(),
        'kind_label' => PAYMENT_KINDS[$payment['kind']] ?? 'Pembayaran',
        'url' => payment_receipt_url($payment),
    ];
}

/** Warna aksen kuitansi tenant, digelapkan otomatis kalau kontras kurang. */
function receipt_accent(): array
{
    $p = accent_palette(tenant_setting('receipt_accent'));
    return $p ?? ['accent' => '#357a4e', 'hover' => '#2a623e', 'soft' => '#e7efea', 'adjusted' => false];
}
