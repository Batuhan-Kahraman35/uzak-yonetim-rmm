<?php
/**
 * Ekran görüntüsü yükleme: script içindeki Get-UzakEkranGoruntusu yardımcısı çağırır.
 *
 * Girdi: { hedefId, jpeg: base64, oturum? }
 * Çıktı: { basarili, dosya }
 * Yalnız bu cihaza ait ve şu an çalışan hedefe yüklenir. Tablo tutulmaz; kayıt dosya adındadır:
 *   ajan/ekran/<cihazId>/<YYYYMMDD-HHMMSS>_h<hedefId>.jpg   (web'den 403)
 * Boyut ekran_goruntusu_azami_kb, adet ekran_goruntusu_komut_basina ayarıyla sınırlanır.
 */

require __DIR__ . '/ortak.php';

const EKRAN_KLASOR = __DIR__ . '/../../ajan/ekran/';

$cihaz   = ajanDogrula();
$g       = ajanGirdi();
$cihazId = (int) $cihaz['Cihazlar_id'];

$hedefId = (int) ($g['hedefId'] ?? 0);
if ($hedefId < 1 || !is_string($g['jpeg'] ?? null)) {
    ajanHata('Geçersiz hedef ya da görüntü.', 400);
}

// Gövde sınırı 4 MB; base64 şişmesi (~%33) nedeniyle ham görüntü 3000 KB'ı geçemez
$sinir = min(3000, max(64, (int) ayar('ekran_goruntusu_azami_kb', 2048))) * 1024;
$veri  = base64_decode($g['jpeg'], true);
if ($veri === false || $veri === '') {
    ajanHata('Görüntü çözülemedi.', 400);
}
if (strlen($veri) > $sinir) {
    ajanHata('Görüntü ' . round($sinir / 1024) . ' KB sınırını aşıyor.', 413);
}
$bilgi = @getimagesizefromstring($veri);
if (!$bilgi || $bilgi[2] !== IMAGETYPE_JPEG) {
    ajanHata('Yalnız JPEG kabul edilir.', 415);
}

try {
    $calisiyor = $db->deger(
        'SELECT COUNT(*) FROM dbo.KomutHedefleri
         WHERE KomutHedefleri_id = ? AND KomutHedefleri_Cihazlar_id = ? AND KomutHedefleri_Tanim_KomutDurumlari_id = ?',
        [$hedefId, $cihazId, komutDurumId('calisiyor')]
    );
    if ((int) $calisiyor === 0) {
        jsonCevap(['basarili' => false, 'mesaj' => 'Hedef bulunamadı ya da çalışmıyor.'], 409);
    }

    $klasor = EKRAN_KLASOR . $cihazId . '/';
    if (!is_dir($klasor) && !mkdir($klasor, 0750, true) && !is_dir($klasor)) {
        throw new RuntimeException('Ekran klasörü oluşturulamadı.');
    }
    if (count(glob($klasor . "*_h{$hedefId}.jpg") ?: []) >= max(1, (int) ayar('ekran_goruntusu_komut_basina', 5))) {
        ajanHata('Bu komut için ekran görüntüsü sınırına ulaşıldı.', 429);
    }

    // Aynı saniyede ikinci yükleme önceki dosyayı ezmesin
    $ad = date('Ymd-His') . "_h{$hedefId}.jpg";
    for ($i = 2; is_file($klasor . $ad); $i++) {
        $ad = date('Ymd-His') . "_h{$hedefId}-{$i}.jpg";
    }
    if (file_put_contents($klasor . $ad, $veri, LOCK_EX) !== strlen($veri)) {
        throw new RuntimeException('Görüntü diske yazılamadı.');
    }
} catch (Throwable $h) {
    error_log("ajan ekran-goruntusu (cihaz $cihazId, hedef $hedefId): " . $h->getMessage());
    ajanHata('Görüntü kaydedilemedi.', 500);
}

logYaz('ekran_goruntusu', [
    'hedefId'    => $hedefId,
    'dosya'      => $ad,
    'boyut'      => strlen($veri),
    'cozunurluk' => $bilgi[0] . 'x' . $bilgi[1],
    'oturum'     => kirp($g['oturum'] ?? null, 256),
], null, $cihazId);
jsonCevap(['basarili' => true, 'dosya' => $ad]);
