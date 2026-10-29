<?php
declare(strict_types=1);

function page_integrations(): void
{
    $refs = google_credential_refs();
    $emails = [];
    foreach (array_keys($refs) as $ref) {
        $emails[$ref] = google_client_email($ref);
    }
    $runs = q_tenant('SELECT * FROM sync_runs WHERE tenant_id = :tid ORDER BY id DESC LIMIT 10')->fetchAll();
    render('settings/integrations', [
        'title' => 'Integrasi',
        'nav' => 'settings',
        'tab' => 'integrations',
        'providers' => integration_providers(),
        'integrations' => tenant_integrations(),
        'refs' => $refs,
        'emails' => $emails,
        'runs' => $runs,
        'test_result' => $_SESSION['integration_test'] ?? null,
    ]);
    unset($_SESSION['integration_test']);
}

function page_integrations_post(): never
{
    $key = str_input('provider', 16);
    $provider = integration_providers()[$key] ?? null;
    if (!$provider) {
        error_page(400);
    }
    $current = tenant_integrations()[$key] ?? null;
    $action = str_input('action', 20);

    if ($action === 'save') {
        $account = ($provider['normalize'])(str_input('account_ref', 255));
        $ref = str_input('credential_ref', 64);
        if ($account === null) {
            flash('error', $provider['account_label'] . ' tidak valid. ' . $provider['account_help']);
            redirect('/settings/integrations');
        }
        if (!isset(google_credential_refs()[$ref])) {
            flash('error', 'Pilih kredensial yang terdaftar di server.');
            redirect('/settings/integrations');
        }
        if ($current) {
            $changed = $current['account_ref'] !== $account;
            // Ganti properti = backfill ulang dari awal untuk properti baru.
            q_tenant('UPDATE integrations SET account_ref = :a, credential_ref = :c, sync_requested_at = UTC_TIMESTAMP()'
                . ($changed ? ', backfilled_at = NULL, last_error = NULL, consecutive_failures = 0' : '')
                . ' WHERE tenant_id = :tid AND id = :id', ['a' => $account, 'c' => $ref, 'id' => $current['id']]);
        } else {
            q_tenant('INSERT INTO integrations (tenant_id, provider, account_ref, credential_ref, sync_requested_at)
                      VALUES (:tid, :p, :a, :c, UTC_TIMESTAMP())', ['p' => $key, 'a' => $account, 'c' => $ref]);
        }
        audit('integration_save', 'integration', $key, ['account_ref' => $account, 'credential_ref' => $ref]);
        flash('success', $provider['label'] . ' tersimpan. Sync pertama (90 hari ke belakang) berjalan dalam 1 menit lewat cron.');
        redirect('/settings/integrations');
    }

    if (!$current) {
        error_page(404);
    }

    switch ($action) {
        case 'test':
            if (!rate_limit('integration-test:' . tenant_id(), 10, 60)) {
                flash('error', 'Terlalu sering tes. Tunggu 1 menit.');
                break;
            }
            try {
                $msg = ($provider['test'])($current);
                $_SESSION['integration_test'] = ['provider' => $key, 'ok' => true, 'message' => $msg];
            } catch (Throwable $e) {
                $_SESSION['integration_test'] = ['provider' => $key, 'ok' => false, 'message' => mb_substr($e->getMessage(), 0, 400)];
            }
            break;
        case 'sync':
            q_tenant('UPDATE integrations SET sync_requested_at = UTC_TIMESTAMP() WHERE tenant_id = :tid AND id = :id', ['id' => $current['id']]);
            flash('success', 'Sync dijadwalkan, berjalan dalam 1 menit.');
            break;
        case 'pause':
        case 'resume':
            q_tenant('UPDATE integrations SET status = :s WHERE tenant_id = :tid AND id = :id', ['s' => $action === 'pause' ? 'paused' : 'active', 'id' => $current['id']]);
            audit('integration_' . $action, 'integration', $key);
            flash('success', $action === 'pause' ? 'Sync dijeda. Data lama tetap ada.' : 'Sync diaktifkan lagi.');
            break;
        case 'disconnect':
            q_tenant('DELETE FROM integrations WHERE tenant_id = :tid AND id = :id', ['id' => $current['id']]);
            if (isset($_POST['delete_data'])) {
                $table = $key === 'ga4' ? 'site_daily' : 'search_daily';
                q_tenant("DELETE FROM $table WHERE tenant_id = :tid");
            }
            audit('integration_disconnect', 'integration', $key, ['delete_data' => isset($_POST['delete_data'])]);
            flash('success', $provider['label'] . ' diputus' . (isset($_POST['delete_data']) ? ' dan datanya dihapus.' : '. Data yang sudah ditarik tetap tersimpan.'));
            break;
        default:
            error_page(400);
    }
    redirect('/settings/integrations');
}
