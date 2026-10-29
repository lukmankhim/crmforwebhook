<?php
declare(strict_types=1);

/**
 * Branding aplikasi dari config server (nama, logo, favicon). File logo ada di public/brand/ yang tidak ikut git,
 * supaya repo public tidak memuat nama atau aset bisnis.
 */
function brand(string $key): ?string
{
    $defaults = ['name' => 'CRM', 'logo' => null, 'favicon' => null];
    $value = config('brand.' . $key, $defaults[$key] ?? null);
    if (in_array($key, ['logo', 'favicon'], true)) {
        // Hanya file di public/brand/ yang benar-benar ada.
        if (!is_string($value) || !preg_match('#^/brand/[A-Za-z0-9._-]+$#', $value) || !is_file(ROOT . '/public' . $value)) {
            return null;
        }
    }
    return $value === null ? null : (string) $value;
}

function brand_head(): string
{
    $fav = brand('favicon') ?? brand('logo');
    return $fav ? '<link rel="icon" href="' . e($fav) . '">' : '';
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function render(string $view, array $data = [], ?string $layout = 'layout'): void
{
    $content = render_partial($view, $data);
    if ($layout === null) {
        echo $content;
        return;
    }
    echo render_partial($layout, $data + ['content' => $content]);
}

function render_partial(string $view, array $data = []): string
{
    extract($data, EXTR_SKIP);
    ob_start();
    require ROOT . '/views/' . $view . '.php';
    return (string) ob_get_clean();
}

function redirect(string $path, array $query = []): never
{
    header('Location: ' . url($path, $query), true, 303);
    exit;
}

function url(string $path, array $query = []): string
{
    $query = array_filter($query, fn($v) => $v !== null && $v !== '');
    return $path . ($query ? '?' . http_build_query($query) : '');
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/** Simpan input form terakhir supaya tidak hilang saat validasi gagal. */
function keep_old(array $input): void
{
    $_SESSION['old'] = $input;
}

function old(string $key, mixed $default = ''): string
{
    static $old = null;
    if ($old === null) {
        $old = $_SESSION['old'] ?? [];
        unset($_SESSION['old']);
    }
    return (string) ($old[$key] ?? $default);
}

/** Tampilkan waktu UTC dari DB dalam zona waktu tenant. */
function fmt_dt(?string $utc, string $format = 'd M Y H:i'): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return id_month($dt->setTimezone(tenant_tz())->format($format));
}

function id_month(string $formatted): string
{
    return strtr($formatted, ['May' => 'Mei', 'Aug' => 'Agu', 'Oct' => 'Okt', 'Dec' => 'Des']);
}

function fmt_date(?string $date): string
{
    if ($date === null || $date === '') {
        return '';
    }
    return id_month((new DateTimeImmutable($date))->format('d M Y'));
}

function fmt_money(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function json_response(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function error_page(int $status, string $message = ''): never
{
    http_response_code($status);
    $titles = [403 => 'Akses ditolak', 404 => 'Halaman tidak ditemukan', 405 => 'Metode tidak diizinkan', 419 => 'Sesi form kedaluwarsa', 500 => 'Terjadi kesalahan'];
    render('errors/error', [
        'title' => $titles[$status] ?? 'Kesalahan',
        'status' => $status,
        'message' => $message,
    ], current_user() ? 'layout' : 'layout_bare');
    exit;
}

function str_input(string $key, int $max = 255): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

function nullable_input(string $key, int $max = 255): ?string
{
    $v = str_input($key, $max);
    return $v === '' ? null : $v;
}

function money_input(string $key): ?string
{
    $v = str_input($key, 30);
    if ($v === '') {
        return null;
    }
    $digits = preg_replace('/[^\d]/', '', $v);
    return $digits === '' ? null : $digits;
}

function source_labels(): array
{
    return [
        'ctwa' => 'Click to WhatsApp',
        'lp' => 'Landing page',
        'gads' => 'Google Ads',
        'organik' => 'Organik',
        'manual' => 'Input manual',
        'import' => 'Import',
    ];
}

function role_labels(): array
{
    return ['admin' => 'Admin', 'sales' => 'Sales', 'viewer' => 'Viewer'];
}
