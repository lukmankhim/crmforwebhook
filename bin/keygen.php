<?php
declare(strict_types=1);

// Cetak app_key baru untuk config/config.php. Jangan ganti kunci di server yang sudah berisi data.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)) . "\n";
