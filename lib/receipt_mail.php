<?php
declare(strict_types=1);

// Email kuitansi lewat API transaksional Brevo + status dari webhook Brevo.

const EMAIL_MAX_ATTEMPTS = 5;
// Urutan kemajuan: status yang lebih rendah tidak menimpa yang lebih tinggi (event bisa datang tidak urut).
const EMAIL_STATUS_RANK = ['queued' => 0, 'failed' => 1, 'sent' => 2, 'delivered' => 3, 'opened' => 4, 'blocked' => 5, 'spam' => 6, 'bounced' => 7];

/** Kirim satu email antrian. Dipanggil worker dengan konteks tenant sudah diset. */
function receipt_email_send(array $msg): void
{
    $payment = find_payment((int) $msg['payment_id']);
    if (!$payment || $payment['voided_at'] !== null) {
        q_tenant("UPDATE email_messages SET status = 'failed', error = 'Kuitansi tidak ada atau dibatalkan' WHERE tenant_id = :tid AND id = :id", ['id' => $msg['id']]);
        return;
    }
    if (!receipt_email_ready()) {
        // Belum diatur: tunda 30 menit, jangan habiskan percobaan.
        q_tenant("UPDATE email_messages SET error = 'Pengaturan email tenant belum lengkap', next_try_at = UTC_TIMESTAMP() + INTERVAL 30 MINUTE
                  WHERE tenant_id = :tid AND id = :id", ['id' => $msg['id']]);
        return;
    }
    $s = tenant_settings();
    $data = receipt_view_data($payment);
    $subject = "Kuitansi {$data['kind_label']} {$payment['receipt_no']} dari {$data['business']}";
    $body = [
        'sender' => ['email' => $s['mail_from_email'], 'name' => $s['mail_from_name'] !== '' ? $s['mail_from_name'] : $data['business']],
        'to' => [array_filter(['email' => $msg['to_email'], 'name' => $data['lead']['name'] ?: null])],
        'subject' => $subject,
        'htmlContent' => render_partial('receipts/email', $data + ['accent' => receipt_accent()]),
        'textContent' => receipt_email_text($data),
        'tags' => ['kuitansi'],
        'headers' => ['X-Crm-Message' => (string) $msg['id']],
    ];
    if (filter_var($s['mail_reply_to'], FILTER_VALIDATE_EMAIL)) {
        $body['replyTo'] = ['email' => $s['mail_reply_to']];
    }
    $endpoint = (string) config('brevo.endpoint', 'https://api.brevo.com/v3/smtp/email');
    [$status, $resp] = http_post_json($endpoint, $body, ['api-key: ' . $s['brevo_api_key']]);
    $attempts = (int) $msg['attempts'] + 1;
    if ($status >= 200 && $status < 300 && !empty($resp['messageId'])) {
        q_tenant("UPDATE email_messages SET status = IF(status = 'queued', 'sent', status), provider_msg_id = :m, attempts = :a,
                  error = NULL, next_try_at = NULL, last_event_at = UTC_TIMESTAMP() WHERE tenant_id = :tid AND id = :id",
            ['m' => mb_substr((string) $resp['messageId'], 0, 255), 'a' => $attempts, 'id' => $msg['id']]);
        lead_log((int) $payment['lead_id'], 'email', "Kuitansi {$payment['receipt_no']} dikirim ke {$msg['to_email']}");
        return;
    }
    $error = mb_substr("HTTP {$status}: " . ($resp['message'] ?? $resp['error']['message'] ?? json_encode($resp, JSON_UNESCAPED_UNICODE)), 0, 480);
    $final = $attempts >= EMAIL_MAX_ATTEMPTS || in_array($status, [400, 401, 403], true);
    q_tenant('UPDATE email_messages SET status = :s, attempts = :a, error = :e, next_try_at = UTC_TIMESTAMP() + INTERVAL :d MINUTE
              WHERE tenant_id = :tid AND id = :id', [
        's' => $final ? 'failed' : 'queued', 'a' => $attempts, 'e' => $error, 'd' => [1, 5, 15, 60, 240][$attempts - 1] ?? 240, 'id' => $msg['id'],
    ]);
    app_log('outbox.log', "email={$msg['id']} tenant={$msg['tenant_id']} attempt={$attempts} {$error}");
    if ($final) {
        $tenant = current_tenant();
        notify_telegram("Email kuitansi gagal\nTenant: {$tenant['name']}\nKuitansi: {$payment['receipt_no']}\n{$error}");
    }
}

function receipt_email_text(array $d): string
{
    $p = $d['payment'];
    $lines = [
        "Kuitansi {$d['kind_label']}",
        "{$d['business']}",
        '',
        "Nomor: {$p['receipt_no']}",
        'Tanggal bayar: ' . fmt_date($p['paid_on']),
        'Diterima dari: ' . ($d['lead']['name'] ?: '-'),
        'Untuk: ' . ($p['description'] ?: '-'),
        'Metode: ' . $p['method'],
        'Jumlah: ' . fmt_money($p['amount']),
        'Terbilang: ' . terbilang((int) round((float) $p['amount'])),
    ];
    if ($d['summary']['deal'] !== null) {
        $lines[] = 'Total sudah dibayar: ' . fmt_money($d['summary']['paid']) . ', sisa: ' . fmt_money($d['summary']['remaining']);
    }
    $lines[] = '';
    $lines[] = "Lihat atau cetak kuitansi: {$d['url']}";
    if ($d['footer'] !== '') {
        $lines[] = $d['footer'];
    }
    return implode("\n", $lines);
}

/** Petakan nama event Brevo ke status kita. null = event diabaikan. */
function brevo_event_status(string $event): ?string
{
    return match (strtolower(str_replace([' ', '-'], '_', $event))) {
        'delivered' => 'delivered',
        'opened', 'unique_opened', 'open', 'unique_open', 'proxy_open', 'unique_proxy_open', 'click', 'clicks' => 'opened',
        'hard_bounce', 'hardbounce', 'invalid_email', 'invalid' => 'bounced',
        'soft_bounce', 'softbounce', 'deferred', 'error' => null,
        'spam', 'complaint' => 'spam',
        'blocked' => 'blocked',
        default => null,
    };
}

/**
 * Proses satu event webhook. Mengembalikan teks singkat untuk respons/log.
 * Hanya mengubah email_messages milik tenant pemilik token.
 */
function brevo_webhook_event(array $ev): string
{
    $msgId = (string) ($ev['message-id'] ?? $ev['message_id'] ?? $ev['messageId'] ?? '');
    $event = (string) ($ev['event'] ?? '');
    if ($msgId === '' || $event === '') {
        return 'skip: tanpa message-id/event';
    }
    $status = brevo_event_status($event);
    $msg = q_tenant('SELECT * FROM email_messages WHERE tenant_id = :tid AND provider_msg_id = :m ORDER BY id DESC LIMIT 1', ['m' => $msgId])->fetch();
    if (!$msg) {
        return 'skip: message-id tidak dikenal';
    }
    q_tenant('UPDATE email_messages SET last_event_at = UTC_TIMESTAMP() WHERE tenant_id = :tid AND id = :id', ['id' => $msg['id']]);
    if ($status === null || EMAIL_STATUS_RANK[$status] <= EMAIL_STATUS_RANK[$msg['status']]) {
        return 'ok: status tetap ' . $msg['status'];
    }
    q_tenant('UPDATE email_messages SET status = :s, error = :e WHERE tenant_id = :tid AND id = :id', [
        's' => $status, 'e' => $status === 'bounced' || $status === 'blocked' ? mb_substr((string) ($ev['reason'] ?? $event), 0, 480) : null, 'id' => $msg['id'],
    ]);
    $payment = find_payment((int) $msg['payment_id']);
    if ($payment) {
        lead_log((int) $payment['lead_id'], 'email', "Kuitansi {$payment['receipt_no']}: " . EMAIL_STATUS_LABELS[$status]);
        if ($status === 'bounced') {
            q_tenant('UPDATE leads SET email_bounced_at = UTC_TIMESTAMP() WHERE tenant_id = :tid AND id = :id AND email = :e',
                ['id' => $payment['lead_id'], 'e' => $msg['to_email']]);
        }
    }
    return 'ok: ' . $status;
}
