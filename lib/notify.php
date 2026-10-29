<?php
declare(strict_types=1);

/** Kirim pesan ke Telegram kalau bot sudah dikonfigurasi. Gagal kirim hanya dicatat di log. */
function notify_telegram(string $text): void
{
    $token = (string) config('telegram.bot_token', '');
    $chat = (string) config('telegram.chat_id', '');
    if ($token === '' || $chat === '') {
        app_log('notify.log', 'telegram belum dikonfigurasi: ' . str_replace("\n", ' | ', $text));
        return;
    }
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chat, 'text' => mb_substr($text, 0, 3500)]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($ok === false || $code !== 200) {
        app_log('notify.log', "telegram gagal http={$code}");
    }
}
