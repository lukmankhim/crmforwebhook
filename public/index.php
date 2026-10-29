<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require ROOT . '/lib/pages/auth.php';
require ROOT . '/lib/pages/leads.php';
require ROOT . '/lib/pages/settings.php';
require ROOT . '/lib/pages/ingest.php';
require ROOT . '/lib/pages/account.php';
require ROOT . '/lib/pages/super.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Cache-Control: no-store');

set_exception_handler(function (Throwable $e) {
    app_log('app.log', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!is_production()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $e;
        exit;
    }
    error_page(500, 'Kesalahan sudah dicatat. Coba lagi beberapa saat lagi.');
});

session_boot();

// [method, pola path, handler, akses]. Akses: public | pending | auth | leads.view | leads.edit | settings | super
$routes = [
    ['GET',  '#^/login$#', 'page_login_form', 'public'],
    ['POST', '#^/login$#', 'page_login_post', 'public'],
    ['GET',  '#^/login/2fa$#', 'page_2fa_form', 'pending'],
    ['POST', '#^/login/2fa$#', 'page_2fa_post', 'pending'],
    ['GET',  '#^/login/2fa-setup$#', 'page_2fa_setup_form', 'pending'],
    ['POST', '#^/login/2fa-setup$#', 'page_2fa_setup_post', 'pending'],
    ['POST', '#^/logout$#', 'page_logout', 'public'],

    ['GET',  '#^/$#', 'page_home', 'auth'],
    ['GET',  '#^/leads$#', 'page_leads_index', 'leads.view'],
    ['GET',  '#^/leads/new$#', 'page_leads_new', 'leads.edit'],
    ['POST', '#^/leads$#', 'page_leads_create', 'leads.edit'],
    ['GET',  '#^/leads/(\d+)$#', 'page_leads_show', 'leads.view'],
    ['POST', '#^/leads/(\d+)$#', 'page_leads_update', 'leads.edit'],
    ['POST', '#^/leads/(\d+)/stage$#', 'page_leads_stage', 'leads.edit'],
    ['POST', '#^/leads/(\d+)/note$#', 'page_leads_note', 'leads.edit'],

    ['GET',  '#^/settings$#', 'page_settings_home', 'settings'],
    ['GET',  '#^/settings/tenant$#', 'page_settings_tenant', 'settings'],
    ['POST', '#^/settings/tenant$#', 'page_settings_tenant_post', 'settings'],
    ['GET',  '#^/settings/users$#', 'page_settings_users', 'settings'],
    ['POST', '#^/settings/users$#', 'page_settings_users_create', 'settings'],
    ['POST', '#^/settings/users/(\d+)$#', 'page_settings_users_update', 'settings'],
    ['GET',  '#^/settings/pipeline$#', 'page_settings_pipeline', 'settings'],
    ['POST', '#^/settings/pipeline$#', 'page_settings_pipeline_post', 'settings'],

    ['GET',  '#^/settings/ingest$#', 'page_ingest_index', 'settings'],
    ['POST', '#^/settings/ingest/keys$#', 'page_ingest_key_create', 'settings'],
    ['POST', '#^/settings/ingest/keys/(\d+)/revoke$#', 'page_ingest_key_revoke', 'settings'],
    ['POST', '#^/settings/ingest/sources$#', 'page_ingest_source_create', 'settings'],
    ['GET',  '#^/settings/ingest/sources/(\d+)$#', 'page_ingest_source_show', 'settings'],
    ['POST', '#^/settings/ingest/sources/(\d+)$#', 'page_ingest_source_update', 'settings'],
    ['POST', '#^/settings/ingest/sources/(\d+)/test$#', 'page_ingest_source_test', 'settings'],
    ['GET',  '#^/settings/ingest/log/(\d+)$#', 'page_ingest_log_show', 'settings'],
    ['POST', '#^/settings/ingest/log/(\d+)/retry$#', 'page_ingest_log_retry', 'settings'],

    ['GET',  '#^/account$#', 'page_account', 'auth'],
    ['POST', '#^/account/password$#', 'page_account_password', 'auth'],
    ['POST', '#^/account/2fa$#', 'page_account_2fa', 'auth'],

    ['GET',  '#^/tenants$#', 'page_tenants', 'super'],
    ['POST', '#^/tenants$#', 'page_tenants_create', 'super'],
    ['POST', '#^/tenants/(\d+)$#', 'page_tenants_update', 'super'],
    ['POST', '#^/switch-tenant$#', 'page_switch_tenant', 'super'],
    ['GET',  '#^/audit$#', 'page_audit', 'super'],
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

$matchedPath = false;
foreach ($routes as [$m, $pattern, $handler, $access]) {
    if (!preg_match($pattern, $path, $params)) {
        continue;
    }
    $matchedPath = true;
    if ($m !== $method) {
        continue;
    }
    if ($method === 'POST' && !csrf_valid()) {
        error_page(419, 'Muat ulang halaman lalu coba lagi.');
    }
    authorize($access);
    $handler(...array_map('intval', array_slice($params, 1)));
    exit;
}
error_page($matchedPath ? 405 : 404);

function authorize(string $access): void
{
    if ($access === 'public') {
        return;
    }
    if ($access === 'pending') {
        if (empty($_SESSION['pending_uid'])) {
            redirect('/login');
        }
        return;
    }
    if (!current_user()) {
        redirect('/login');
    }
    if (!can('super') && has_tenant() && (current_tenant()['status'] ?? '') !== 'active') {
        logout_session();
        redirect('/login');
    }
    if ($access === 'auth') {
        return;
    }
    if ($access === 'super') {
        if (!can('super')) {
            error_page(403);
        }
        return;
    }
    if (!has_tenant()) {
        if (can('super')) {
            flash('info', 'Pilih atau buat tenant dulu.');
            redirect('/tenants');
        }
        error_page(403, 'Akun ini belum terhubung ke tenant aktif.');
    }
    if (!can($access)) {
        error_page(403);
    }
}

function page_home(): never
{
    if (!has_tenant() && can('super')) {
        redirect('/tenants');
    }
    redirect('/leads');
}
