<?php
/**
 * Ajan dosyalarını sunar (IIS .ps1 uzantısını doğrudan sunmaz). Kimlik gerektirir.
 * GET ?ad=baslatici.ps1 | ajan.ps1
 * Yanıt başlığı X-Sha256: ajan indirdiği dosyayı bununla doğrular.
 * (6. adımda AjanSurumleri + Ed25519 imzasıyla değiştirilecek.)
 */

require __DIR__ . '/ortak.php';

ajanDogrula();

$izinli = ['baslatici.ps1', 'ajan.ps1'];
$ad     = (string) ($_GET['ad'] ?? '');
if (!in_array($ad, $izinli, true)) {
    ajanHata('Geçersiz dosya.', 400);
}

$dosya = __DIR__ . '/../../ajan/' . $ad;
if (!is_file($dosya)) {
    ajanHata('Dosya bulunamadı.', 404);
}

header('Content-Type: text/plain; charset=utf-8');
header('X-Sha256: ' . hash_file('sha256', $dosya));
readfile($dosya);
