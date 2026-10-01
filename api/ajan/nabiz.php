<?php
/**
 * Nabız: ajan her sorgu aralığında çağırır.
 *
 * Girdi: { bilgisayarAdi, isletimSistemi, osSurum, osDerleme, aktifKullanici, icIp, macAdres,
 *          ajanSurum, sonAcilis ("yyyy-MM-dd HH:mm:ss"), yazilimHash, donanimHash }
 * Çıktı: { araligSn, envanterIste: ["yazilim","donanim"],
 *         komutlar: [{hedefId, baslik, icerik, parametreler{}, zamanAsimiSn}] }  (komutlar yalnız ajan >= 0.2.0)
 *
 * Envanter özeti DB'dekinden farklıysa sunucu tam listeyi ister. Özet DB'ye yalnız envanter
 * başarıyla yazıldığında kaydedilir; panelden özeti NULL yapmak envanteri yeniden istetir.
 */

require __DIR__ . '/ortak.php';

$cihaz = ajanDogrula();
$g     = ajanGirdi();

$sonAcilis = null;
if (is_string($g['sonAcilis'] ?? null)) {
    $t = DateTime::createFromFormat('Y-m-d H:i:s', $g['sonAcilis']);
    $sonAcilis = $t ? $t->format('Y-m-d H:i:s') : null;
}

$icIp = kirp($g['icIp'] ?? null, 45);
if ($icIp !== null && !filter_var($icIp, FILTER_VALIDATE_IP)) {
    $icIp = null;
}
$mac = kirp($g['macAdres'] ?? null, 17);
if ($mac !== null && !preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $mac)) {
    $mac = null;
}
$anyDeskId = kirp($g['anyDeskId'] ?? null, 20);
if ($anyDeskId !== null && !preg_match('/^\d{1,20}$/', $anyDeskId)) {
    $anyDeskId = null;
}

$db->calistir(
    'UPDATE dbo.Cihazlar SET
        Cihazlar_BilgisayarAdi  = COALESCE(?, Cihazlar_BilgisayarAdi),
        Cihazlar_IsletimSistemi = ?,
        Cihazlar_OsSurum        = ?,
        Cihazlar_OsDerleme      = ?,
        Cihazlar_AktifKullanici = ?,
        Cihazlar_IcIp           = ?,
        Cihazlar_DisIp          = ?,
        Cihazlar_MacAdres       = ?,
        Cihazlar_AnyDeskId      = COALESCE(?, Cihazlar_AnyDeskId),
        Cihazlar_AjanSurum      = ?,
        Cihazlar_SonAcilis      = ?,
        Cihazlar_SonGorulme     = GETDATE()
     WHERE Cihazlar_id = ?',
    [
        kirp($g['bilgisayarAdi'] ?? null, 100),
        kirp($g['isletimSistemi'] ?? null, 150),
        kirp($g['osSurum'] ?? null, 20),
        kirp($g['osDerleme'] ?? null, 30),
        kirp($g['aktifKullanici'] ?? null, 150),
        $icIp,
        istemciIp(),
        $mac,
        $anyDeskId,
        kirp($g['ajanSurum'] ?? null, 20),
        $sonAcilis,
        $cihaz['Cihazlar_id'],
    ]
);

$envanterIste = [];
$yazilimHash = ozetAl($g['yazilimHash'] ?? null);
$donanimHash = ozetAl($g['donanimHash'] ?? null);
if ($yazilimHash === null || $yazilimHash !== strtolower((string) $cihaz['Cihazlar_YazilimHash'])) {
    $envanterIste[] = 'yazilim';
}
if ($donanimHash === null || $donanimHash !== strtolower((string) $cihaz['Cihazlar_DonanimHash'])) {
    $envanterIste[] = 'donanim';
}

// --- Komut kuyruğu ------------------------------------------------------------
// Komut çalıştırma ajan 0.2.0 ile geldi; eski ajana teslim edilen komut çalışmaz ve kaybolurdu.
$komutlar = [];
$ajanSurum = (string) ($g['ajanSurum'] ?? '0');
if (preg_match('/^\d+(\.\d+)*$/', $ajanSurum) && version_compare($ajanSurum, '0.2.0', '>=')) {
    try {
        $cihazId = (int) $cihaz['Cihazlar_id'];

        // Süresi dolmuş bekleyenler + sonuç gelmeyen alınmış/çalışanlar (zaman aşımı + 5 dk pay)
        $db->calistir(
            'UPDATE h SET KomutHedefleri_Tanim_KomutDurumlari_id = ?, KomutHedefleri_BitisTarihi = GETDATE(), GuncellemeTarihi = GETDATE()
             FROM dbo.KomutHedefleri h INNER JOIN dbo.Komutlar k ON k.Komutlar_id = h.KomutHedefleri_Komutlar_id
             WHERE h.KomutHedefleri_Cihazlar_id = ? AND h.KomutHedefleri_Tanim_KomutDurumlari_id = ? AND k.Komutlar_SonGecerlilik <= GETDATE()',
            [komutDurumId('suresidoldu'), $cihazId, komutDurumId('bekliyor')]
        );
        $db->calistir(
            'UPDATE h SET KomutHedefleri_Tanim_KomutDurumlari_id = ?, KomutHedefleri_BitisTarihi = GETDATE(),
                    KomutHedefleri_Hata = ISNULL(KomutHedefleri_Hata, N\'Ajandan sonuç gelmedi.\'), GuncellemeTarihi = GETDATE()
             FROM dbo.KomutHedefleri h INNER JOIN dbo.Komutlar k ON k.Komutlar_id = h.KomutHedefleri_Komutlar_id
             WHERE h.KomutHedefleri_Cihazlar_id = ? AND h.KomutHedefleri_Tanim_KomutDurumlari_id IN (?, ?)
               AND DATEADD(SECOND, k.Komutlar_ZamanAsimiSn + 300, h.KomutHedefleri_AlinmaTarihi) <= GETDATE()',
            [komutDurumId('zamanasimi'), $cihazId, komutDurumId('alindi'), komutDurumId('calisiyor')]
        );

        // Teslim: oluşturulma sırasıyla en fazla 5 komut (ilk kurulum adımları sıralı çalışmalı);
        // 'alindi' işaretlemesi ve okuma tek ifadede (OUTPUT)
        $teslim = $db->hepsi(
            'UPDATE dbo.KomutHedefleri
             SET KomutHedefleri_Tanim_KomutDurumlari_id = ?, KomutHedefleri_AlinmaTarihi = GETDATE(), GuncellemeTarihi = GETDATE()
             OUTPUT inserted.KomutHedefleri_id, inserted.KomutHedefleri_Komutlar_id
             WHERE KomutHedefleri_id IN (
                 SELECT TOP (5) h.KomutHedefleri_id
                 FROM dbo.KomutHedefleri h INNER JOIN dbo.Komutlar k ON k.Komutlar_id = h.KomutHedefleri_Komutlar_id
                 WHERE h.KomutHedefleri_Cihazlar_id = ? AND h.KomutHedefleri_Tanim_KomutDurumlari_id = ?
                   AND k.Durum = 1 AND k.Komutlar_SonGecerlilik > GETDATE()
                 ORDER BY h.KomutHedefleri_Komutlar_id
             )',
            [komutDurumId('alindi'), $cihazId, komutDurumId('bekliyor')]
        );
        if ($teslim) {
            $hedefler = array_column($teslim, 'KomutHedefleri_id', 'KomutHedefleri_Komutlar_id');
            $yer = implode(',', array_fill(0, count($hedefler), '?'));
            $satirlar = $db->hepsi(
                "SELECT Komutlar_id, Komutlar_Baslik, Komutlar_Icerik, Komutlar_Parametreler, Komutlar_ZamanAsimiSn
                 FROM dbo.Komutlar WHERE Komutlar_id IN ($yer) ORDER BY Komutlar_id",
                array_keys($hedefler)
            );
            foreach ($satirlar as $k) {
                $komutlar[] = [
                    'hedefId'     => (int) $hedefler[$k['Komutlar_id']],
                    'baslik'      => $k['Komutlar_Baslik'],
                    'icerik'      => $k['Komutlar_Icerik'],
                    'parametreler'=> (object) komutParametreleriCoz($k['Komutlar_Parametreler'], $cihazId),
                    'zamanAsimiSn'=> (int) $k['Komutlar_ZamanAsimiSn'],
                ];
            }
        }
    } catch (Throwable $h) {
        // Kuyruk hatası nabzı düşürmez; ajan bir sonraki nabızda tekrar dener
        error_log("ajan nabiz komut (cihaz {$cihaz['Cihazlar_id']}): " . $h->getMessage());
    }
}

jsonCevap([
    'basarili'     => true,
    'araligSn'     => max(15, (int) ayar('ajan_sorgu_araligi_sn', 60)),
    // Ajan envanter özetini bu aralıkla yeniden hesaplar (her nabızda CIM sorgusu yapılmasın)
    'envanterKontrolDk' => max(1, (int) ayar('envanter_kontrol_dk', 15)),
    // Boş disk alanı özete bu adımla yuvarlanarak girer; yoksa her nabızda envanter gönderilirdi
    'diskBosAdimGb'     => max(1, (int) ayar('disk_bos_adim_gb', 5)),
    'envanterIste' => $envanterIste,
    'komutlar'     => $komutlar,
]);
