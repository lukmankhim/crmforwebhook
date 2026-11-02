<?php
declare(strict_types=1);

function page_payment_create(int $leadId): never
{
    $lead = lead_or_404($leadId);
    $in = [
        'kind' => str_input('kind', 20),
        'amount' => (int) (money_input('amount') ?? 0),
        'paid_on' => str_input('paid_on', 10),
        'method' => str_input('pay_method', 60),
        'description' => str_input('description', 255),
        'note' => str_input('note', 255),
    ];
    [$id, $error] = payment_create($lead, $in, (int) current_user()['id']);
    if ($error) {
        keep_old($in);
        flash('error', $error);
        redirect('/leads/' . $leadId);
    }
    $payment = find_payment($id);
    $sent = lead_wants_receipt($lead);
    flash('success', "Pembayaran tersimpan, kuitansi {$payment['receipt_no']}." . ($sent ? " Email kuitansi dikirim ke {$lead['email']}." : ''));
    redirect('/leads/' . $leadId);
}

function payment_or_404(int $id): array
{
    $p = find_payment($id);
    if (!$p) {
        error_page(404);
    }
    return $p;
}

function page_payment_void(int $id): never
{
    $p = payment_or_404($id);
    $error = payment_void($p, str_input('void_reason', 255), (int) current_user()['id']);
    flash($error ? 'error' : 'success', $error ?: "Kuitansi {$p['receipt_no']} dibatalkan.");
    redirect('/leads/' . $p['lead_id']);
}

function page_payment_resend(int $id): never
{
    $p = payment_or_404($id);
    $lead = lead_or_404((int) $p['lead_id']);
    $error = payment_resend($p, $lead, (int) current_user()['id']);
    flash($error ? 'error' : 'success', $error ?: "Kuitansi {$p['receipt_no']} masuk antrian kirim ke {$lead['email']}.");
    redirect('/leads/' . $p['lead_id']);
}

/** Halaman kuitansi publik lewat token. Tanpa login, hanya data kuitansi itu. */
function page_receipt_public(int|string $token): never
{
    $token = (string) $token;
    if (!preg_match('/^[a-f0-9]{64}$/', $token) || !rate_limit('receipt:' . client_ip(), 120, 60)) {
        error_page(404);
    }
    $p = q('SELECT * FROM payments WHERE public_token = ?', [$token])->fetch();
    if (!$p) {
        error_page(404);
    }
    set_tenant_context((int) $p['tenant_id']);
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    render('receipts/public', receipt_view_data($p) + ['title' => 'Kuitansi ' . $p['receipt_no']], null);
    exit;
}

/** Warna aksen kuitansi per tenant (CSP melarang style inline). */
function page_receipt_theme(int|string $slug): never
{
    $slug = (string) $slug;
    $tenant = q("SELECT id FROM tenants WHERE slug = ? AND status = 'active'", [$slug])->fetch();
    if (!$tenant) {
        error_page(404);
    }
    set_tenant_context((int) $tenant['id']);
    $a = receipt_accent();
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo ".receipt {\n  --r-accent: {$a['accent']};\n  --r-accent-soft: {$a['soft']};\n}\n";
    exit;
}

/** Webhook status email Brevo: POST /hooks/brevo/<tenant>, header Authorization: Bearer <token tenant>. */
function page_hook_brevo(int|string $slug): never
{
    $slug = (string) $slug;
    $ip = client_ip();
    if (!rate_limit('hook-brevo:' . $ip, 600, 60)) {
        json_response(['error' => 'rate_limited'], 429);
    }
    $tenant = q("SELECT id FROM tenants WHERE slug = ? AND status = 'active'", [$slug])->fetch();
    if (!$tenant) {
        rate_limit('hook-bad:' . $ip, 30, 60);
        json_response(['error' => 'unknown_tenant'], 404);
    }
    set_tenant_context((int) $tenant['id']);
    $expected = tenant_setting('brevo_webhook_token');
    $sent = (string) bearer_token();
    if ($expected === '' || !hash_equals($expected, $sent)) {
        if (!rate_limit('hook-bad:' . $ip, 30, 60)) {
            json_response(['error' => 'rate_limited'], 429);
        }
        json_response(['error' => 'unauthorized'], 401);
    }
    $body = (string) file_get_contents('php://input', false, null, 0, 262144);
    $data = json_decode($body, true);
    if (!is_array($data)) {
        json_response(['error' => 'invalid_json'], 400);
    }
    // Brevo bisa kirim satu event atau array event.
    $events = array_is_list($data) ? $data : [$data];
    $results = [];
    foreach (array_slice($events, 0, 200) as $ev) {
        if (is_array($ev)) {
            $results[] = brevo_webhook_event($ev);
        }
    }
    json_response(['status' => 'ok', 'processed' => count($results)]);
}

function page_settings_receipts(): void
{
    $s = tenant_settings();
    $tenant = current_tenant();
    $logos = [];
    foreach (glob(ROOT . '/public/brand/*.{png,jpg,jpeg,svg,webp}', GLOB_BRACE) ?: [] as $f) {
        $logos[] = '/brand/' . basename($f);
    }
    render('settings/receipts', [
        'title' => 'Kuitansi dan iklan',
        'nav' => 'settings',
        'tab' => 'receipts',
        's' => $s,
        'has_secret' => ['brevo_api_key' => $s['brevo_api_key'] !== '', 'brevo_webhook_token' => $s['brevo_webhook_token'] !== '', 'meta_access_token' => $s['meta_access_token'] !== ''],
        'logos' => $logos,
        'webhook_url' => rtrim((string) config('app_url'), '/') . '/hooks/brevo/' . $tenant['slug'],
        'email_ready' => receipt_email_ready(),
        'meta_ready' => meta_capi_ready(),
        'waiting' => (int) q_tenant("SELECT COUNT(*) FROM conversion_events WHERE tenant_id = :tid AND status = 'waiting_config'")->fetchColumn(),
        'accent' => receipt_accent(),
    ]);
}

function page_settings_receipts_post(): never
{
    $section = str_input('section', 20);
    $changed = [];
    $error = null;
    $plain = [
        'receipt' => ['receipt_business_name' => 150, 'receipt_address' => 255, 'receipt_contact' => 255, 'receipt_footer' => 255, 'receipt_prefix' => 10, 'receipt_accent' => 7, 'receipt_logo' => 120],
        'email' => ['mail_from_email' => 190, 'mail_from_name' => 100, 'mail_reply_to' => 190],
        'meta' => ['meta_dataset_id' => 20, 'meta_test_event_code' => 40],
    ][$section] ?? null;
    $secrets = ['email' => ['brevo_api_key', 'brevo_webhook_token'], 'meta' => ['meta_access_token']][$section] ?? [];
    if ($plain === null) {
        error_page(400);
    }
    $values = [];
    foreach ($plain as $key => $max) {
        $values[$key] = str_input($key, $max);
    }
    if ($section === 'receipt') {
        $values['receipt_prefix'] = strtoupper($values['receipt_prefix']);
        if ($values['receipt_prefix'] !== '' && !preg_match('/^[A-Z0-9-]{1,10}$/', $values['receipt_prefix'])) {
            $error = 'Awalan nomor hanya huruf, angka, dan tanda minus.';
        } elseif ($values['receipt_accent'] !== '' && accent_palette($values['receipt_accent']) === null) {
            $error = 'Warna aksen harus hex 6 digit, misalnya #be185d.';
        } elseif ($values['receipt_logo'] !== '' && !preg_match('#^/brand/[A-Za-z0-9._-]+$#', $values['receipt_logo'])) {
            $error = 'Pilih logo dari daftar.';
        }
    } elseif ($section === 'email') {
        foreach (['mail_from_email', 'mail_reply_to'] as $k) {
            if ($values[$k] !== '' && !filter_var($values[$k], FILTER_VALIDATE_EMAIL)) {
                $error = 'Format email tidak valid.';
            }
        }
    } elseif ($values['meta_dataset_id'] !== '' && !preg_match('/^\d{5,20}$/', $values['meta_dataset_id'])) {
        $error = 'ID dataset Meta berupa angka.';
    }
    if ($error) {
        flash('error', $error);
        redirect('/settings/receipts');
    }
    $old = tenant_settings();
    foreach ($values as $k => $v) {
        if ($old[$k] !== $v) {
            tenant_setting_set($k, $v);
            $changed[] = $k;
        }
    }
    foreach ($secrets as $k) {
        $v = str_input($k, 300);
        if (($_POST['clear_' . $k] ?? '') === '1') {
            tenant_setting_set($k, '');
            $changed[] = $k . ' (dihapus)';
        } elseif ($v !== '') {
            tenant_setting_set($k, $v);
            $changed[] = $k;
        }
    }
    if ($section === 'meta' && meta_capi_ready()) {
        // Event yang menunggu pengaturan siap dikirim worker (yang terlalu lama akan ditandai kedaluwarsa).
        q_tenant("UPDATE conversion_events SET status = 'pending', next_try_at = UTC_TIMESTAMP() WHERE tenant_id = :tid AND status = 'waiting_config'");
    }
    if ($changed) {
        audit('tenant_settings', 'tenant', tenant_id(), ['changed' => $changed]);
    }
    flash('success', $changed ? 'Pengaturan tersimpan.' : 'Tidak ada perubahan.');
    redirect('/settings/receipts');
}
