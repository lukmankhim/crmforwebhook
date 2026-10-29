<?php
declare(strict_types=1);

/**
 * Normalisasi nomor HP Indonesia ke format 62xxxxxxxxxx.
 * Menerima 0812..., 812..., +62 812..., 62-812... Selain nomor Indonesia ditolak (null).
 */
function normalize_phone(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $digits = preg_replace('/\D/', '', $raw);
    if ($digits === '') {
        return null;
    }
    if (str_starts_with($digits, '0')) {
        $digits = '62' . substr($digits, 1);
    } elseif (str_starts_with($digits, '8')) {
        $digits = '62' . $digits;
    }
    return preg_match('/^62[1-9]\d{7,12}$/', $digits) ? $digits : null;
}

/** Untuk tampilan daftar: 6281234567890 jadi 62812****890. Nomor penuh hanya di halaman detail. */
function mask_phone(string $phone): string
{
    $len = strlen($phone);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return substr($phone, 0, 5) . str_repeat('*', $len - 8) . substr($phone, -3);
}
