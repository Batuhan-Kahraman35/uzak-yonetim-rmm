<?php
/**
 * Kurulum betiğini (ajan/kur.ps1) sunar. Kimlik gerektirmez; betik sır içermez,
 * kayıt için ayrıca geçerli bir kayıt kodu gerekir.
 */

require __DIR__ . '/ortak.php';

$dosya = __DIR__ . '/../../ajan/kur.ps1';
if (!is_file($dosya)) {
    ajanHata('Kurulum betiği bulunamadı.', 404);
}

// Betikteki sunucu adresi yer tutucusu, isteğin geldiği adresle doldurulur (kodda alan adı sabit kalmaz)
$host = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host)) {
    ajanHata('Geçersiz sunucu adı.', 400);
}
$icerik = str_replace('__UZAK_YONETIM_SUNUCU__', 'https://' . $host, file_get_contents($dosya));

header('Content-Type: text/plain; charset=utf-8');
header('X-Sha256: ' . hash('sha256', $icerik));
echo $icerik;
