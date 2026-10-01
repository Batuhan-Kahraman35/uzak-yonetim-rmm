<?php
/**
 * İlk kurulum isteği: kurulum dosyasında "[2] Ajan + ilk kurulum islemleri" seçilince kur.ps1 çağırır.
 * Scriptler_IlkKurulumSira dolu script'ler sırasıyla bu cihaz için kuyruğa alınır.
 * Bekleyen / çalışan ilk kurulum varsa tekrar eklenmez.
 *
 * Girdi: {}  Çıktı: { basarili, eklenen }
 */

require __DIR__ . '/ortak.php';

$cihaz = ajanDogrula();
ajanGirdi();

try {
    $eklenen = ilkKurulumKuyrugaAl((int) $cihaz['Cihazlar_id']);
} catch (Throwable $h) {
    error_log("ajan ilk-kurulum (cihaz {$cihaz['Cihazlar_id']}): " . $h->getMessage());
    ajanHata('İlk kurulum kuyruğa alınamadı.', 500);
}

logYaz('ilk_kurulum_iste', ['eklenen' => $eklenen], null, (int) $cihaz['Cihazlar_id']);
jsonCevap(['basarili' => true, 'eklenen' => $eklenen]);
