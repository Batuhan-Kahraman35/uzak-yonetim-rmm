<?php
/**
 * Ajan kaydı: kayıt kodu → cihaz GUID + token
 *
 * Girdi: { kayitKodu, bilgisayarAdi, anakartUuid, biosSeri }
 * Aynı donanım (anakart UUID + BIOS seri) daha önce kayıtlıysa o kayıt kullanılır, token yenilenir.
 * Klon imajlarda Windows MachineGuid aynı olabildiği için kimlik donanımdan üretilir.
 */

require __DIR__ . '/ortak.php';

ajanKilitKontrol();
$girdi = ajanGirdi();

$kod = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($girdi['kayitKodu'] ?? '')));
$bilgisayarAdi = kirp($girdi['bilgisayarAdi'] ?? null, 100);

if ($kod === '' || $bilgisayarAdi === null) {
    logYaz('ajan_kayit_basarisiz', ['neden' => 'eksik alan'], null);
    ajanHata('Kayıt kodu ve bilgisayar adı zorunlu.', 400);
}

// --- Donanım kimliği ---------------------------------------------------------
// Anlamsız / fabrika varsayılanı değerler kimlik sayılmaz (Ayarlar: donanim_kimlik_gecersiz, | ile ayrılmış)
$gecersiz = array_map(
    fn($d) => strtoupper(trim($d)),
    explode('|', (string) ayar('donanim_kimlik_gecersiz',
        'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF|00000000-0000-0000-0000-000000000000|03000200-0400-0500-0006-000700080009'
        . '|TO BE FILLED BY O.E.M.|DEFAULT STRING|SYSTEM SERIAL NUMBER|NONE|0|123456789|NOT APPLICABLE'))
);
$uuid = strtoupper(trim((string) ($girdi['anakartUuid'] ?? '')));
$seri = strtoupper(trim((string) ($girdi['biosSeri'] ?? '')));
if (in_array($seri, $gecersiz, true)) {
    $seri = '';
}
$donanimKimlik = ($uuid !== '' && !in_array($uuid, $gecersiz, true))
    ? hash('sha256', $uuid . '|' . $seri)
    : null;

// --- Kayıt -------------------------------------------------------------------
$token = bin2hex(random_bytes(32));

try {
    $sonuc = $db->islem(function (UzakDb $db) use ($kod, $bilgisayarAdi, $donanimKimlik, $token) {
        // Kod kontrolü + kullanım sayısını artırma tek atomik UPDATE
        $kayitKodu = $db->tek(
            'UPDATE dbo.KayitKodlari
             SET KayitKodlari_KullanimSayisi = KayitKodlari_KullanimSayisi + 1, GuncellemeTarihi = GETDATE()
             OUTPUT inserted.KayitKodlari_id, inserted.KayitKodlari_CihazGruplari_id
             WHERE KayitKodlari_KodHash = ?
               AND Durum = 1
               AND KayitKodlari_SonKullanmaTarihi > GETDATE()
               AND KayitKodlari_KullanimSayisi < KayitKodlari_KullanimLimiti',
            [hash('sha256', $kod)]
        );
        if (!$kayitKodu) {
            return null;
        }

        $mevcut = $donanimKimlik === null ? null : $db->tek(
            'SELECT Cihazlar_id, CONVERT(VARCHAR(36), Cihazlar_Guid) AS Guid
             FROM dbo.Cihazlar WITH (UPDLOCK, HOLDLOCK)
             WHERE Cihazlar_DonanimKimlik = ?',
            [$donanimKimlik]
        );

        if ($mevcut) {
            // Yeniden kurulum: kayıt korunur, token yenilenir, envanter baştan istenir
            $db->calistir(
                'UPDATE dbo.Cihazlar
                 SET Cihazlar_TokenHash = ?, Cihazlar_BilgisayarAdi = ?,
                     Cihazlar_YazilimHash = NULL, Cihazlar_DonanimHash = NULL,
                     Durum = 1, GuncellemeTarihi = GETDATE()
                 WHERE Cihazlar_id = ?',
                [tokenOzet($token), $bilgisayarAdi, $mevcut['Cihazlar_id']]
            );
            return ['id' => (int) $mevcut['Cihazlar_id'], 'guid' => strtolower($mevcut['Guid']), 'yeni' => false, 'kodId' => (int) $kayitKodu['KayitKodlari_id']];
        }

        $yeni = $db->tek(
            'INSERT INTO dbo.Cihazlar
                (Cihazlar_Guid, Cihazlar_TokenHash, Cihazlar_DonanimKimlik, Cihazlar_CihazGruplari_id, Cihazlar_BilgisayarAdi)
             OUTPUT inserted.Cihazlar_id, CONVERT(VARCHAR(36), inserted.Cihazlar_Guid) AS Guid
             VALUES (NEWID(), ?, ?, ?, ?)',
            [tokenOzet($token), $donanimKimlik, $kayitKodu['KayitKodlari_CihazGruplari_id'], $bilgisayarAdi]
        );
        return ['id' => (int) $yeni['Cihazlar_id'], 'guid' => strtolower($yeni['Guid']), 'yeni' => true, 'kodId' => (int) $kayitKodu['KayitKodlari_id']];
    });
} catch (Throwable $h) {
    error_log('ajan kayit: ' . $h->getMessage());
    ajanHata('Kayıt sırasında sunucu hatası.', 500);
}

if ($sonuc === null) {
    logYaz('ajan_kayit_basarisiz', ['neden' => 'geçersiz kod', 'bilgisayar' => $bilgisayarAdi], null);
    ajanHata('Kayıt kodu geçersiz, süresi dolmuş ya da kullanım limiti dolmuş.', 403);
}

logYaz('ajan_kayit', [
    'cihazId'       => $sonuc['id'],
    'bilgisayar'    => $bilgisayarAdi,
    'yeniKayit'     => $sonuc['yeni'],
    'kayitKoduId'   => $sonuc['kodId'],
    'donanimKimlik' => $donanimKimlik !== null,
], null, $sonuc['id']);

jsonCevap([
    'basarili'  => true,
    'guid'      => $sonuc['guid'],
    'token'     => $token,
    'yeniKayit' => $sonuc['yeni'],
]);
