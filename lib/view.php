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

/**
 * Turunan warna aksen dari satu hex. Teks putih di atas aksen dan aksen di atas latar (#ffffff, #f5f4ef)
 * wajib minimal 4,5:1; kalau kurang, aksen digelapkan sampai lolos.
 *
 * @return array{accent: string, hover: string, soft: string, adjusted: bool}|null null kalau bukan hex 6 digit
 */
function accent_palette(string $hex): ?array
{
    if (!preg_match('/^#?([0-9a-f]{6})$/i', trim($hex), $m)) {
        return null;
    }
    $rgb = array_map('hexdec', str_split($m[1], 2));
    $adjusted = false;
    while (min(contrast_ratio($rgb, [255, 255, 255]), contrast_ratio($rgb, [245, 244, 239])) < 4.5) {
        $rgb = array_map(fn ($c) => (int) floor($c * 0.95), $rgb);
        $adjusted = true;
    }
    $hex = fn (array $c) => sprintf('#%02x%02x%02x', ...$c);
    return [
        'accent' => $hex($rgb),
        'hover' => $hex(array_map(fn ($c) => (int) round($c * 0.8), $rgb)),
        'soft' => $hex(array_map(fn ($c) => (int) round(255 - (255 - $c) * 0.12), $rgb)),
        'adjusted' => $adjusted,
    ];
}

function contrast_ratio(array $a, array $b): float
{
    $lum = function (array $rgb): float {
        $l = array_map(function ($c) {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);
        return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
    };
    [$hi, $lo] = [max($lum($a), $lum($b)), min($lum($a), $lum($b))];
    return ($hi + 0.05) / ($lo + 0.05);
}

/** Aksen dari brand.accent di config, atau null kalau tidak diisi atau tidak valid (pakai warna bawaan app.css). */
function brand_accent(): ?array
{
    static $palette = false;
    if ($palette !== false) {
        return $palette;
    }
    $hex = config('brand.accent');
    if ($hex === null || $hex === '') {
        return $palette = null;
    }
    $palette = is_string($hex) ? accent_palette($hex) : null;
    if ($palette === null) {
        app_log('app.log', 'brand.accent diabaikan: harus hex 6 digit, misalnya #123456');
    } elseif ($palette['adjusted']) {
        app_log('app.log', 'brand.accent ' . $hex . ' kontrasnya kurang dari 4,5:1, digelapkan jadi ' . $palette['accent']);
    }
    return $palette;
}

/** Dimuat setelah app.css supaya variabel aksen di :root tertimpa. */
function brand_theme_link(): string
{
    $p = brand_accent();
    return $p ? '<link rel="stylesheet" href="/theme.css?v=' . substr(md5($p['accent']), 0, 8) . '">' : '';
}

function page_theme_css(): void
{
    $p = brand_accent();
    if (!$p) {
        error_page(404);
    }
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    echo ":root {\n  --accent: {$p['accent']};\n  --accent-hover: {$p['hover']};\n  --accent-soft: {$p['soft']};\n}\n";
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
