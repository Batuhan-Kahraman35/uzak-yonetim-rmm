<?php
/**
 * Komut sonucu: ajan komuta başlarken 'calisiyor', bitince son durumu bildirir.
 *
 * Girdi: { hedefId, durum: calisiyor|basarili|hatali|zamanasimi, cikisKodu?, cikti?, hata? }
 * Çıktı: { basarili }
 * Yalnız bu cihaza ait ve henüz bitmemiş hedef güncellenir; çıktı komut_cikti_azami_kb ile kırpılır.
 */

require __DIR__ . '/ortak.php';

$cihaz = ajanDogrula();
$g     = ajanGirdi();

$hedefId = (int) ($g['hedefId'] ?? 0);
$durum   = (string) ($g['durum'] ?? '');
if ($hedefId < 1 || !in_array($durum, ['calisiyor', 'basarili', 'hatali', 'zamanasimi'], true)) {
    ajanHata('Geçersiz hedef ya da durum.', 400);
}

$sinir = max(16, (int) ayar('komut_cikti_azami_kb', 1024)) * 1024;
$kirpMetin = function ($metin) use ($sinir): ?string {
    if (!is_string($metin) || $metin === '') {
        return null;
    }
    return strlen($metin) > $sinir
        ? mb_strcut($metin, 0, $sinir, 'UTF-8') . "\n... [çıktı " . round($sinir / 1024) . ' KB sınırında kırpıldı]'
        : $metin;
};

try {
    $acik = [komutDurumId('alindi'), komutDurumId('calisiyor')];

    if ($durum === 'calisiyor') {
        $adet = $db->calistir(
            'UPDATE dbo.KomutHedefleri
             SET KomutHedefleri_Tanim_KomutDurumlari_id = ?, KomutHedefleri_BaslamaTarihi = GETDATE(), GuncellemeTarihi = GETDATE()
             WHERE KomutHedefleri_id = ? AND KomutHedefleri_Cihazlar_id = ? AND KomutHedefleri_Tanim_KomutDurumlari_id IN (?, ?)',
            [komutDurumId('calisiyor'), $hedefId, $cihaz['Cihazlar_id'], ...$acik]
        );
    } else {
        $adet = $db->calistir(
            'UPDATE dbo.KomutHedefleri
             SET KomutHedefleri_Tanim_KomutDurumlari_id = ?,
                 KomutHedefleri_BaslamaTarihi = ISNULL(KomutHedefleri_BaslamaTarihi, KomutHedefleri_AlinmaTarihi),
                 KomutHedefleri_BitisTarihi = GETDATE(),
                 KomutHedefleri_CikisKodu = ?, KomutHedefleri_Cikti = ?, KomutHedefleri_Hata = ?,
                 GuncellemeTarihi = GETDATE()
             WHERE KomutHedefleri_id = ? AND KomutHedefleri_Cihazlar_id = ? AND KomutHedefleri_Tanim_KomutDurumlari_id IN (?, ?)',
            [
                komutDurumId($durum),
                is_numeric($g['cikisKodu'] ?? null) ? (int) $g['cikisKodu'] : null,
                $kirpMetin($g['cikti'] ?? null),
                $kirpMetin($g['hata'] ?? null),
                $hedefId, $cihaz['Cihazlar_id'], ...$acik,
            ]
        );
    }
} catch (Throwable $h) {
    error_log("ajan komut-sonuc (cihaz {$cihaz['Cihazlar_id']}, hedef $hedefId): " . $h->getMessage());
    ajanHata('Sonuç kaydedilemedi.', 500);
}

// Hedef yok / başka cihazın / zaten bitmiş (ör. sunucu zaman aşımına düşürdü): ajan tekrar denemesin
if ($adet === 0) {
    jsonCevap(['basarili' => false, 'mesaj' => 'Hedef bulunamadı ya da zaten sonuçlanmış.'], 409);
}
jsonCevap(['basarili' => true]);
