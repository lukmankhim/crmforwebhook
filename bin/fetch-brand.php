<?php
declare(strict_types=1);

// Ambil logo dan favicon dari situs bisnis, simpan ke public/brand/ (tidak ikut git).
// Pakai di server: php bin/fetch-brand.php https://situs-anda.com [--name="Nama CRM"]
// Hasilnya dicetak sebagai potongan config untuk ditempel ke config/config.php.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

const BRAND_MAX_BYTES = 1024 * 1024;
const BRAND_TYPES = [
    'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg',
    'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico', 'image/gif' => 'gif',
];

$args = array_slice($argv, 1);
$site = null;
$name = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--name=')) {
        $name = substr($a, 7);
    } elseif ($site === null) {
        $site = $a;
    }
}
if (!$site || !preg_match('#^https?://[^/\s]+#i', $site)) {
    fwrite(STDERR, "Pakai: php bin/fetch-brand.php https://situs-anda.com [--name=\"Nama CRM\"]\n");
    exit(1);
}

function fetch(string $url): array
{
    $ch = curl_init($url);
    $data = '';
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'crm-fetch-brand/1.0',
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$data) {
            $data .= $chunk;
            return strlen($data) > 2 * BRAND_MAX_BYTES ? 0 : strlen($chunk); // hentikan file yang terlalu besar
        },
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = strtolower(trim(explode(';', (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = curl_error($ch);
    curl_close($ch);
    return ['status' => $status, 'type' => $type, 'body' => $data, 'url' => $final ?: $url, 'error' => $error];
}

function absolute_url(string $href, string $base): ?string
{
    $href = trim($href);
    if ($href === '' || str_starts_with($href, 'data:')) {
        return null;
    }
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    $p = parse_url($base);
    $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($href, '//')) {
        return $p['scheme'] . ':' . $href;
    }
    if (str_starts_with($href, '/')) {
        return $origin . $href;
    }
    $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
    return $origin . $dir . $href;
}

/** SVG bisa membawa script. Tolak yang mencurigakan, karena file disajikan dari domain aplikasi. */
function svg_is_safe(string $svg): bool
{
    return !preg_match('/<script|<foreignObject|\son[a-z]+\s*=|javascript:|<iframe|<embed|<object|(?:xlink:)?href\s*=\s*["\']\s*(?:https?:)?\/\//i', $svg);
}

function save_image(string $url, string $basename, string $dir): ?string
{
    $r = fetch($url);
    if ($r['status'] !== 200) {
        echo "  lewati {$url}: HTTP {$r['status']} {$r['error']}\n";
        return null;
    }
    $body = $r['body'];
    $type = $r['type'];
    if (!isset(BRAND_TYPES[$type]) && str_starts_with(ltrim($body), '<svg')) {
        $type = 'image/svg+xml';
    }
    if (!isset(BRAND_TYPES[$type])) {
        echo "  lewati {$url}: bukan gambar ({$type})\n";
        return null;
    }
    if (strlen($body) > BRAND_MAX_BYTES) {
        echo "  lewati {$url}: lebih dari 1MB\n";
        return null;
    }
    if ($type === 'image/svg+xml') {
        if (!svg_is_safe($body)) {
            echo "  lewati {$url}: SVG berisi script atau referensi luar\n";
            return null;
        }
    } elseif ($type !== 'image/x-icon' && $type !== 'image/vnd.microsoft.icon' && @getimagesizefromstring($body) === false) {
        echo "  lewati {$url}: isi file bukan gambar yang valid\n";
        return null;
    }
    $file = $basename . '.' . BRAND_TYPES[$type];
    foreach (glob($dir . '/' . $basename . '.*') ?: [] as $old) {
        unlink($old);
    }
    file_put_contents($dir . '/' . $file, $body);
    chmod($dir . '/' . $file, 0644);
    echo "  tersimpan public/brand/{$file} dari {$url}\n";
    return '/brand/' . $file;
}

echo "Mengambil {$site}\n";
$page = fetch($site);
if ($page['status'] !== 200 || !str_contains($page['type'], 'html')) {
    fwrite(STDERR, "Gagal membuka halaman: HTTP {$page['status']} {$page['error']}\n");
    exit(1);
}

$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML($page['body'], LIBXML_NONET | LIBXML_NOERROR);
$xp = new DOMXPath($doc);
$base = $page['url'];

// Favicon: apple-touch-icon (paling besar) lebih tajam, lalu icon biasa, lalu /favicon.ico.
$icons = [];
foreach ($xp->query('//link[@rel and @href]') as $link) {
    $rel = strtolower($link->getAttribute('rel'));
    if (!preg_match('/\b(icon|apple-touch-icon)\b/', $rel)) {
        continue;
    }
    $size = (int) preg_replace('/x.*$/', '', $link->getAttribute('sizes') ?: '0');
    $score = (str_contains($rel, 'apple-touch-icon') ? 1000 : 0) + $size + (str_ends_with(strtolower($link->getAttribute('href')), '.svg') ? 500 : 0);
    $url = absolute_url($link->getAttribute('href'), $base);
    if ($url) {
        $icons[$url] = $score;
    }
}
arsort($icons);
$icons = array_keys($icons);
$icons[] = absolute_url('/favicon.ico', $base);

// Logo: gambar yang ditandai logo (class, id, alt, src), termasuk pola WordPress custom-logo.
$logos = [];
foreach ($xp->query('//img') as $img) {
    $src = $img->getAttribute('src') ?: $img->getAttribute('data-src');
    $hint = strtolower($img->getAttribute('class') . ' ' . $img->getAttribute('id') . ' ' . $img->getAttribute('alt') . ' ' . $src);
    $parentHint = $img->parentNode instanceof DOMElement ? strtolower($img->parentNode->getAttribute('class')) : '';
    if (str_contains($hint, 'logo') || str_contains($parentHint, 'logo')) {
        $url = absolute_url($src, $base);
        if ($url) {
            $logos[] = $url;
        }
    }
}
foreach ($xp->query('//*[contains(translate(@class, "LOGO", "logo"), "logo")]//svg | //svg[contains(translate(@class, "LOGO", "logo"), "logo")]') as $svg) {
    $logos[] = 'inline-svg:' . $doc->saveHTML($svg);
    break;
}

$dir = dirname(__DIR__) . '/public/brand';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

echo "Favicon:\n";
$favicon = null;
foreach (array_unique($icons) as $u) {
    if ($favicon = save_image($u, 'favicon', $dir)) {
        break;
    }
}

echo "Logo:\n";
$logo = null;
foreach (array_unique($logos) as $u) {
    if (str_starts_with($u, 'inline-svg:')) {
        $svg = substr($u, 11);
        if (svg_is_safe($svg) && strlen($svg) < BRAND_MAX_BYTES) {
            foreach (glob($dir . '/logo.*') ?: [] as $old) {
                unlink($old);
            }
            file_put_contents($dir . '/logo.svg', str_contains($svg, 'xmlns=') ? $svg : preg_replace('/<svg\b/i', '<svg xmlns="http://www.w3.org/2000/svg"', $svg, 1));
            chmod($dir . '/logo.svg', 0644);
            echo "  tersimpan public/brand/logo.svg (SVG di halaman)\n";
            $logo = '/brand/logo.svg';
            break;
        }
        continue;
    }
    if ($logo = save_image($u, 'logo', $dir)) {
        break;
    }
}
if (!$logo) {
    echo "  logo tidak ketemu otomatis. Taruh file logo manual di public/brand/ lalu isi 'logo' di config.\n";
}

$theme = $xp->query('//meta[@name="theme-color"]/@content')->item(0)?->nodeValue;
if ($theme) {
    echo "\nWarna tema situs (theme-color): {$theme}\n";
}

$nameLine = var_export($name ?? 'CRM', true);
echo "\nTempel ke config/config.php:\n\n";
echo "    'brand' => [\n";
echo "        'name' => {$nameLine},\n";
echo $logo ? "        'logo' => '{$logo}',\n" : "        // 'logo' => '/brand/logo.png',\n";
echo $favicon ? "        'favicon' => '{$favicon}',\n" : "        // 'favicon' => '/brand/favicon.png',\n";
echo "    ],\n";
