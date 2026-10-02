<?php
/**
 * Uzak Yönetim - Cihaz Detay
 * Özet, donanım, diskler, yazılımlar (server-side) ve işlem geçmişi.
 * Menüde ayrı kaydı yoktur; yetki Cihazlar sayfasından (uzak-cihazlar.php) okunur.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/UzakYonetim.php';
requireAuth();

$user  = Auth::user();
$db    = Database::getInstance();
$udb   = UzakDb::al();
$yetki = uzakSayfaYetkisi($user, 'uzak-cihazlar.php');

$cihazId      = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$cevrimdisiDk = max(1, (int) ayar('cihaz_cevrimdisi_dk', 5));

$cihaz = $cihazId > 0 ? $udb->tek(
    "SELECT c.Cihazlar_id, CONVERT(VARCHAR(36), c.Cihazlar_Guid) AS Guid, c.Cihazlar_BilgisayarAdi, c.Cihazlar_Aciklama,
            c.Cihazlar_CihazGruplari_id, g.CihazGruplari_Ad, c.Cihazlar_IsletimSistemi, c.Cihazlar_OsSurum, c.Cihazlar_OsDerleme,
            c.Cihazlar_AktifKullanici, c.Cihazlar_IcIp, c.Cihazlar_DisIp, c.Cihazlar_MacAdres, c.Cihazlar_AjanSurum,
            c.Cihazlar_PilotMu, c.Cihazlar_RustDeskId, c.Cihazlar_AnyDeskId,
            CONVERT(VARCHAR(19), c.Cihazlar_SonGorulme, 120) AS SonGorulme,
            CONVERT(VARCHAR(19), c.Cihazlar_SonAcilis, 120) AS SonAcilis,
            CONVERT(VARCHAR(19), c.OlusturmaTarihi, 120) AS KayitTarihi,
            CASE WHEN c.Cihazlar_SonGorulme >= DATEADD(MINUTE, -?, GETDATE()) THEN 1 ELSE 0 END AS CevrimiciMi,
            CASE WHEN c.Cihazlar_DonanimKimlik IS NULL THEN 0 ELSE 1 END AS DonanimKimlikVar
     FROM dbo.Cihazlar c
     LEFT JOIN dbo.CihazGruplari g ON g.CihazGruplari_id = c.Cihazlar_CihazGruplari_id
     WHERE c.Cihazlar_id = ? AND c.Durum = 1",
    [$cevrimdisiDk, $cihazId]
) : null;

$ajanKomutAlir = $cihaz && preg_match('/^\d+(\.\d+)*$/', (string) $cihaz['Cihazlar_AjanSurum'])
    && version_compare((string) $cihaz['Cihazlar_AjanSurum'], '0.2.0', '>=');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfDogrula();
    if (!$cihaz) {
        jsonCevap(['basarili' => false, 'mesaj' => 'Cihaz bulunamadı.'], 404);
    }

    try {
        switch ($_POST['action'] ?? '') {
            case 'yazilimlar':
                $draw   = (int) ($_POST['draw'] ?? 1);
                $start  = max(0, (int) ($_POST['start'] ?? 0));
                $length = (int) ($_POST['length'] ?? 25);
                $length = ($length < 1 || $length > 500) ? 25 : $length;

                // Sıralama: kolon index → kolon adı (whitelist)
                $siralanabilir = [
                    0 => 'CihazYazilimlari_Ad',
                    1 => 'CihazYazilimlari_Surum',
                    2 => 'CihazYazilimlari_Yayinci',
                    3 => 'CihazYazilimlari_KurulumTarihi',
                    4 => 'CihazYazilimlari_Mimari',
                ];
                $siraKolon = $siralanabilir[(int) ($_POST['order'][0]['column'] ?? 0)] ?? 'CihazYazilimlari_Ad';
                $siraYon   = strtolower((string) ($_POST['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

                $kosul = ['CihazYazilimlari_Cihazlar_id = ?'];
                $param = [$cihazId];
                $mimari = trim((string) ($_POST['mimari'] ?? ''));
                if ($mimari !== '') {
                    $kosul[] = 'CihazYazilimlari_Mimari = ?';
                    $param[] = $mimari;
                }
                $ara = trim((string) ($_POST['ara'] ?? ''));
                if ($ara !== '') {
                    $kosul[] = '(CihazYazilimlari_Ad LIKE ? OR CihazYazilimlari_Yayinci LIKE ? OR CihazYazilimlari_Surum LIKE ?)';
                    array_push($param, ...array_fill(0, 3, '%' . $ara . '%'));
                }
                $where = implode(' AND ', $kosul);

                $recordsTotal    = (int) $udb->deger('SELECT COUNT(*) FROM dbo.CihazYazilimlari WHERE CihazYazilimlari_Cihazlar_id = ?', [$cihazId]);
                $recordsFiltered = (int) $udb->deger("SELECT COUNT(*) FROM dbo.CihazYazilimlari WHERE $where", $param);

                $veri = $recordsFiltered === 0 ? [] : $udb->hepsi(
                    "SELECT CihazYazilimlari_Ad, CihazYazilimlari_Surum, CihazYazilimlari_Yayinci,
                            CONVERT(VARCHAR(10), CihazYazilimlari_KurulumTarihi, 104) AS KurulumTarihi,
                            CihazYazilimlari_Mimari
                     FROM dbo.CihazYazilimlari
                     WHERE $where
                     ORDER BY $siraKolon $siraYon, CihazYazilimlari_id
                     OFFSET $start ROWS FETCH NEXT $length ROWS ONLY",
                    $param
                );

                jsonCevap([
                    'draw'            => $draw,
                    'recordsTotal'    => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered,
                    'data'            => $veri,
                ]);

            case 'komutlar':
                // Hareket tablosu → server-side; cihaz başına sınırsız büyür
                $draw   = (int) ($_POST['draw'] ?? 1);
                $start  = max(0, (int) ($_POST['start'] ?? 0));
                $length = (int) ($_POST['length'] ?? 25);
                $length = ($length < 1 || $length > 500) ? 25 : $length;
                $siraYon = strtolower((string) ($_POST['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

                $recordsTotal = (int) $udb->deger('SELECT COUNT(*) FROM dbo.KomutHedefleri WHERE KomutHedefleri_Cihazlar_id = ?', [$cihazId]);
                $veri = $recordsTotal === 0 ? [] : $udb->hepsi(
                    "WITH Sayfa AS (
                         SELECT KomutHedefleri_id FROM dbo.KomutHedefleri
                         WHERE KomutHedefleri_Cihazlar_id = ?
                         ORDER BY KomutHedefleri_id $siraYon
                         OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
                     )
                     SELECT h.KomutHedefleri_id, k.Komutlar_Baslik, h.KomutHedefleri_CikisKodu,
                            d.Tanim_KomutDurumlari_Kod AS DurumKod, d.Tanim_KomutDurumlari_Ad AS DurumAd,
                            d.Tanim_KomutDurumlari_Renk AS DurumRenk, d.Tanim_KomutDurumlari_BittiMi AS BittiMi,
                            CONVERT(VARCHAR(19), h.OlusturmaTarihi, 120) AS Olusturma,
                            CONVERT(VARCHAR(19), h.KomutHedefleri_BitisTarihi, 120) AS Bitis,
                            DATEDIFF(SECOND, h.KomutHedefleri_BaslamaTarihi, h.KomutHedefleri_BitisTarihi) AS SureSn,
                            CASE WHEN h.KomutHedefleri_Cikti IS NULL AND h.KomutHedefleri_Hata IS NULL THEN 0 ELSE 1 END AS CiktiVar,
                            u.kullanici_ad + ' ' + u.kullanici_soyad AS Gonderen
                     FROM Sayfa s
                     INNER JOIN dbo.KomutHedefleri h ON h.KomutHedefleri_id = s.KomutHedefleri_id
                     INNER JOIN dbo.Komutlar k ON k.Komutlar_id = h.KomutHedefleri_Komutlar_id
                     INNER JOIN dbo.Tanim_KomutDurumlari d ON d.Tanim_KomutDurumlari_id = h.KomutHedefleri_Tanim_KomutDurumlari_id
                     LEFT JOIN dbo.kullanicilar u ON u.kullanici_id = k.OlusturanKullanici
                     ORDER BY h.KomutHedefleri_id $siraYon",
                    [$cihazId]
                );
                jsonCevap(['draw' => $draw, 'recordsTotal' => $recordsTotal, 'recordsFiltered' => $recordsTotal, 'data' => $veri]);

            case 'komut_cikti':
                $k = $udb->tek(
                    "SELECT k.Komutlar_Baslik, k.Komutlar_Icerik, h.KomutHedefleri_Cikti, h.KomutHedefleri_Hata, h.KomutHedefleri_CikisKodu,
                            d.Tanim_KomutDurumlari_Ad AS DurumAd,
                            CONVERT(VARCHAR(19), h.KomutHedefleri_AlinmaTarihi, 120) AS Alinma,
                            CONVERT(VARCHAR(19), h.KomutHedefleri_BaslamaTarihi, 120) AS Baslama,
                            CONVERT(VARCHAR(19), h.KomutHedefleri_BitisTarihi, 120) AS Bitis
                     FROM dbo.KomutHedefleri h
                     INNER JOIN dbo.Komutlar k ON k.Komutlar_id = h.KomutHedefleri_Komutlar_id
                     INNER JOIN dbo.Tanim_KomutDurumlari d ON d.Tanim_KomutDurumlari_id = h.KomutHedefleri_Tanim_KomutDurumlari_id
                     WHERE h.KomutHedefleri_id = ? AND h.KomutHedefleri_Cihazlar_id = ?",
                    [(int) ($_POST['hedefId'] ?? 0), $cihazId]
                );
                if (!$k) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Komut bulunamadı.'], 404);
                }
                jsonCevap(['basarili' => true, 'veri' => $k]);

            case 'komut_iptal':
                if (empty($yetki['can_edit'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Düzenleme yetkiniz yok.'], 403);
                }
                $hedefId = (int) ($_POST['hedefId'] ?? 0);
                // Yalnız ajan henüz almamışsa iptal edilebilir
                $adet = $udb->calistir(
                    'UPDATE dbo.KomutHedefleri SET KomutHedefleri_Tanim_KomutDurumlari_id = ?, KomutHedefleri_BitisTarihi = GETDATE(),
                            GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                     WHERE KomutHedefleri_id = ? AND KomutHedefleri_Cihazlar_id = ? AND KomutHedefleri_Tanim_KomutDurumlari_id = ?',
                    [komutDurumId('iptal'), $user['kullanici_id'], $hedefId, $cihazId, komutDurumId('bekliyor')]
                );
                if ($adet === 0) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Komut ajana ulaşmış ya da zaten sonuçlanmış; iptal edilemez.'], 409);
                }
                logYaz('komut_iptal', ['hedefId' => $hedefId], null, $cihazId);
                jsonCevap(['basarili' => true, 'mesaj' => 'Komut iptal edildi.']);

            case 'hizli_komut':
                // Tek seferlik komut: Scriptler'e kaydedilmez (Komutlar_Scriptler_id = NULL), bir kez çalışır
                if (empty($yetki['can_add'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Komut gönderme yetkiniz yok.'], 403);
                }
                $icerik = str_replace("\r\n", "\n", (string) ($_POST['icerik'] ?? ''));
                if (trim($icerik) === '') {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Komut boş olamaz.'], 422);
                }
                if (strlen($icerik) > 100000) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Komut çok uzun.'], 422);
                }
                $sure = (int) ($_POST['zamanAsimi'] ?? 300);
                $sure = ($sure < 10 || $sure > 7200) ? 300 : $sure;
                $baslik = kirp($_POST['baslik'] ?? null, 200) ?? 'Hızlı komut';
                $komutId = komutOlustur(null, $baslik, $icerik, [], [], $sure, [$cihazId], (int) $user['kullanici_id']);
                logYaz('hizli_komut', ['komutId' => $komutId, 'sha256' => hash('sha256', $icerik)], null, $cihazId);
                jsonCevap([
                    'basarili' => true,
                    'uyari'    => !$ajanKomutAlir,
                    'mesaj'    => $ajanKomutAlir ? 'Komut kuyruklandı.' : "Komut kuyruklandı; ajan 0.2.0'dan eski, güncellenene kadar çalışmaz.",
                ]);

            case 'script_gonder':
                if (empty($yetki['can_add'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Komut gönderme yetkiniz yok.'], 403);
                }
                try {
                    $s = scriptGonder((int) ($_POST['scriptId'] ?? 0), [$cihazId], (array) ($_POST['degerler'] ?? []), (int) $user['kullanici_id']);
                } catch (InvalidArgumentException $h) {
                    jsonCevap(['basarili' => false, 'mesaj' => $h->getMessage()], 422);
                }
                jsonCevap([
                    'basarili' => true,
                    'uyari'    => $s['eskiAjan'] > 0,
                    'mesaj'    => $s['eskiAjan'] > 0 ? "Komut kuyruklandı; ancak ajan 0.2.0'dan eski, güncellenene kadar çalışmaz." : 'Komut kuyruklandı.',
                ]);

            case 'guncelle':
                if (empty($yetki['can_edit'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Düzenleme yetkiniz yok.'], 403);
                }
                $grupId = (int) ($_POST['grup'] ?? 0);
                if (!$udb->deger('SELECT 1 FROM dbo.CihazGruplari WHERE CihazGruplari_id = ? AND Durum = 1', [$grupId])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Geçerli bir grup seçin.'], 422);
                }
                $aciklama = kirp($_POST['aciklama'] ?? null, 500);
                $pilot    = ($_POST['pilot'] ?? '') === '1' ? 1 : 0;

                $udb->calistir(
                    'UPDATE dbo.Cihazlar
                     SET Cihazlar_CihazGruplari_id = ?, Cihazlar_Aciklama = ?, Cihazlar_PilotMu = ?,
                         GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                     WHERE Cihazlar_id = ?',
                    [$grupId, $aciklama, $pilot, $user['kullanici_id'], $cihazId]
                );
                logYaz('cihaz_guncelle', [
                    'once'  => ['grup' => $cihaz['Cihazlar_CihazGruplari_id'], 'aciklama' => $cihaz['Cihazlar_Aciklama'], 'pilot' => (int) $cihaz['Cihazlar_PilotMu']],
                    'sonra' => ['grup' => $grupId, 'aciklama' => $aciklama, 'pilot' => $pilot],
                ], null, $cihazId);
                jsonCevap(['basarili' => true, 'mesaj' => 'Cihaz bilgileri güncellendi.']);

            case 'envanter_yenile':
                if (empty($yetki['can_edit'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Düzenleme yetkiniz yok.'], 403);
                }
                // Özet NULL olunca ajan bir sonraki nabızda tam listeyi gönderir
                $udb->calistir(
                    'UPDATE dbo.Cihazlar SET Cihazlar_YazilimHash = NULL, Cihazlar_DonanimHash = NULL,
                            GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                     WHERE Cihazlar_id = ?',
                    [$user['kullanici_id'], $cihazId]
                );
                logYaz('envanter_yenile_iste', null, null, $cihazId);
                jsonCevap(['basarili' => true, 'mesaj' => 'Envanter bir sonraki nabızda (en geç ' . max(15, (int) ayar('ajan_sorgu_araligi_sn', 60)) . ' sn) yeniden alınacak.']);

            default:
                jsonCevap(['basarili' => false, 'mesaj' => 'Geçersiz işlem.'], 400);
        }
    } catch (Throwable $h) {
        error_log('uzak-cihaz-detay.php: ' . $h->getMessage());
        jsonCevap(['basarili' => false, 'mesaj' => 'İşlem sırasında bir hata oluştu.'], 500);
    }
}

if (!$cihaz) {
    PageAuth::accessDenied('Cihaz bulunamadı.');
}

$donanim = $udb->tek(
    "SELECT CihazDonanim_Uretici, CihazDonanim_Model, CihazDonanim_SeriNo, CihazDonanim_Islemci, CihazDonanim_CekirdekSayisi,
            CihazDonanim_RamGb, CihazDonanim_RamDetay, CihazDonanim_Anakart, CihazDonanim_BiosSurum, CihazDonanim_EkranKarti,
            CONVERT(VARCHAR(19), ISNULL(GuncellemeTarihi, OlusturmaTarihi), 120) AS Guncelleme
     FROM dbo.CihazDonanim WHERE CihazDonanim_Cihazlar_id = ?",
    [$cihazId]
);
$diskler = $udb->hepsi(
    'SELECT CihazDiskleri_Surucu, CihazDiskleri_Model, CihazDiskleri_Tip, CihazDiskleri_ToplamGb, CihazDiskleri_BosGb
     FROM dbo.CihazDiskleri WHERE CihazDiskleri_Cihazlar_id = ? ORDER BY CihazDiskleri_Surucu',
    [$cihazId]
);
$yazilimSayisi = (int) $udb->deger('SELECT COUNT(*) FROM dbo.CihazYazilimlari WHERE CihazYazilimlari_Cihazlar_id = ?', [$cihazId]);
$mimariler     = $udb->hepsi(
    'SELECT DISTINCT CihazYazilimlari_Mimari AS Mimari FROM dbo.CihazYazilimlari
     WHERE CihazYazilimlari_Cihazlar_id = ? AND CihazYazilimlari_Mimari IS NOT NULL ORDER BY 1',
    [$cihazId]
);
$gruplar  = $udb->hepsi('SELECT CihazGruplari_id, CihazGruplari_Ad FROM dbo.CihazGruplari WHERE Durum = 1 ORDER BY CihazGruplari_Ad');
$scriptler = $udb->hepsi('SELECT Scriptler_id, Scriptler_Ad, Scriptler_Parametreler FROM dbo.Scriptler WHERE Durum = 1 ORDER BY Scriptler_Ad');
$loglar   = $udb->hepsi(
    "SELECT TOP 100 l.UzakYonetimLoglari_Islem, l.UzakYonetimLoglari_Detay, l.UzakYonetimLoglari_Ip,
            CONVERT(VARCHAR(19), l.OlusturmaTarihi, 120) AS Tarih,
            u.kullanici_ad + ' ' + u.kullanici_soyad AS Kullanici
     FROM dbo.UzakYonetimLoglari l
     LEFT JOIN dbo.kullanicilar u ON u.kullanici_id = l.UzakYonetimLoglari_kullanici_id
     WHERE l.UzakYonetimLoglari_Cihazlar_id = ?
     ORDER BY l.UzakYonetimLoglari_id DESC",
    [$cihazId]
);

$diskToplam = array_sum(array_map(fn($d) => (float) $d['CihazDiskleri_ToplamGb'], $diskler));
$diskBos    = array_sum(array_map(fn($d) => (float) $d['CihazDiskleri_BosGb'], $diskler));

$uyE = fn($m) => htmlspecialchars((string) ($m ?? ''), ENT_QUOTES, 'UTF-8');
$uyBos = '<span class="text-muted">-</span>';
$uyDeger = fn($m) => ($m === null || $m === '') ? $uyBos : $uyE($m);
$uyGb = fn($m) => $m === null ? $uyBos : rtrim(rtrim(number_format((float) $m, 1, ',', '.'), '0'), ',') . ' GB';

$pageTitle = $cihaz['Cihazlar_BilgisayarAdi'];
$pageInfo  = $db->fetchOne(
    "SELECT m.menuler_menu_adi AS menu_adi FROM Menu_Sayfalar s LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
     WHERE s.sayfalar_sayfa_url = 'uzak-cihazlar.php' AND s.sayfalar_durum = 1"
);
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $uyE(csrfToken()) ?>">
    <title><?= $uyE($pageTitle) ?> - <?= $uyE($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <link rel="stylesheet" href="/admin/assets/css/uzak-yonetim.css?v=<?= @filemtime(__DIR__ . '/../assets/css/uzak-yonetim.css') ?>">
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">

    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-6">
                        <h3 class="mb-0">
                            <span class="durum-nokta <?= $cihaz['CevrimiciMi'] ? 'cevrimici' : 'cevrimdisi' ?>" title="<?= $cihaz['CevrimiciMi'] ? 'Çevrimiçi' : 'Çevrimdışı' ?>"></span>
                            <?= $uyE($pageTitle) ?>
                            <?php if ($cihaz['Cihazlar_PilotMu']): ?><span class="badge text-bg-warning fs-6 align-middle">Pilot</span><?php endif; ?>
                        </h3>
                        <?php if ($cihaz['Cihazlar_Aciklama']): ?><div class="text-muted"><?= $uyE($cihaz['Cihazlar_Aciklama']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-sm-6 d-flex flex-column align-items-end gap-1">
                        <a href="/admin/uzak-cihazlar" class="btn btn-secondary btn-sm">
                            <i class="bi bi-arrow-left me-1"></i> Listeye Dön
                        </a>
                        <ol class="breadcrumb mb-0">
                            <?php if ($menuAdi): ?><li class="breadcrumb-item"><?= $uyE($menuAdi) ?></li><?php endif; ?>
                            <li class="breadcrumb-item"><a href="/admin/uzak-cihazlar">Cihazlar</a></li>
                            <li class="breadcrumb-item active"><?= $uyE($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid" id="cihazDetay" data-id="<?= (int) $cihazId ?>">

                <!-- InfoBox -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-primary">
                            <div class="inner">
                                <h3 class="fs-5 text-truncate" title="<?= $uyE($donanim['CihazDonanim_Islemci'] ?? '') ?>"><?= $uyE($donanim['CihazDonanim_Islemci'] ?? '-') ?></h3>
                                <p>İşlemci<?= !empty($donanim['CihazDonanim_CekirdekSayisi']) ? ' · ' . (int) $donanim['CihazDonanim_CekirdekSayisi'] . ' çekirdek' : '' ?></p>
                            </div>
                            <i class="small-box-icon bi bi-cpu"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-success">
                            <div class="inner">
                                <h3><?= $donanim ? $uyGb($donanim['CihazDonanim_RamGb']) : '-' ?></h3>
                                <p>RAM<?= !empty($donanim['CihazDonanim_RamDetay']) ? ' · ' . $uyE($donanim['CihazDonanim_RamDetay']) : '' ?></p>
                            </div>
                            <i class="small-box-icon bi bi-memory"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-warning">
                            <div class="inner">
                                <h3><?= $diskler ? $uyGb($diskBos) : '-' ?></h3>
                                <p>Boş Disk<?= $diskler ? ' · toplam ' . $uyGb($diskToplam) : '' ?></p>
                            </div>
                            <i class="small-box-icon bi bi-device-hdd"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-info">
                            <div class="inner">
                                <h3><?= $yazilimSayisi ?></h3>
                                <p>Kurulu Yazılım</p>
                            </div>
                            <i class="small-box-icon bi bi-grid-3x3-gap"></i>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex align-items-center flex-wrap gap-2">
                        <ul class="nav nav-tabs card-header-tabs" role="tablist">
                            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#sekmeOzet" type="button"><i class="bi bi-info-circle me-1"></i>Özet</button></li>
                            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#sekmeDonanim" type="button"><i class="bi bi-motherboard me-1"></i>Donanım</button></li>
                            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#sekmeYazilim" type="button" id="yazilimSekmeBtn"><i class="bi bi-grid-3x3-gap me-1"></i>Yazılımlar <span class="badge text-bg-secondary"><?= $yazilimSayisi ?></span></button></li>
                            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#sekmeKomut" type="button" id="komutSekmeBtn"><i class="bi bi-terminal me-1"></i>Komutlar</button></li>
                            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#sekmeLog" type="button"><i class="bi bi-clock-history me-1"></i>İşlem Geçmişi</button></li>
                        </ul>
                        <?php if (!empty($yetki['can_edit'])): ?>
                        <div class="ms-auto d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="envanterYenile" title="Ajan bir sonraki nabızda tüm envanteri yeniden gönderir">
                                <i class="bi bi-arrow-repeat me-1"></i>Envanteri Yenile
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#duzenleModal">
                                <i class="bi bi-pencil me-1"></i>Düzenle
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body tab-content">

                        <!-- Özet -->
                        <div class="tab-pane fade show active" id="sekmeOzet">
                            <div class="row g-4">
                                <div class="col-lg-6">
                                    <table class="table table-sm detay-tablo mb-0">
                                        <tr><th>Durum</th><td><?= $cihaz['CevrimiciMi'] ? '<span class="durum-nokta cevrimici"></span>Çevrimiçi' : '<span class="durum-nokta cevrimdisi"></span>Çevrimdışı' ?></td></tr>
                                        <tr><th>Son görülme</th><td><?= $uyDeger($cihaz['SonGorulme']) ?></td></tr>
                                        <tr><th>Son açılış</th><td><?= $uyDeger($cihaz['SonAcilis']) ?></td></tr>
                                        <tr><th>Grup</th><td><?= $uyDeger($cihaz['CihazGruplari_Ad']) ?></td></tr>
                                        <tr><th>Aktif kullanıcı</th><td><?= $uyDeger($cihaz['Cihazlar_AktifKullanici']) ?></td></tr>
                                        <tr><th>İşletim sistemi</th><td><?= $uyDeger($cihaz['Cihazlar_IsletimSistemi']) ?>
                                            <?php if ($cihaz['Cihazlar_OsSurum'] || $cihaz['Cihazlar_OsDerleme']): ?>
                                            <span class="text-muted"><?= $uyE(trim($cihaz['Cihazlar_OsSurum'] . ' · ' . $cihaz['Cihazlar_OsDerleme'], ' ·')) ?></span>
                                            <?php endif; ?></td></tr>
                                    </table>
                                </div>
                                <div class="col-lg-6">
                                    <table class="table table-sm detay-tablo mb-0">
                                        <tr><th>İç IP</th><td><?= $uyDeger($cihaz['Cihazlar_IcIp']) ?></td></tr>
                                        <tr><th>Dış IP</th><td><?= $uyDeger($cihaz['Cihazlar_DisIp']) ?></td></tr>
                                        <tr><th>MAC</th><td class="font-monospace"><?= $uyDeger($cihaz['Cihazlar_MacAdres']) ?></td></tr>
                                        <tr><th>AnyDesk ID</th><td>
                                            <?php if ($cihaz['Cihazlar_AnyDeskId']): ?>
                                                <span class="font-monospace"><?= $uyE($cihaz['Cihazlar_AnyDeskId']) ?></span>
                                                <a href="anydesk:<?= $uyE($cihaz['Cihazlar_AnyDeskId']) ?>" class="btn btn-outline-primary btn-sm ms-2" title="AnyDesk ile bağlan"><i class="bi bi-box-arrow-up-right"></i> Bağlan</a>
                                            <?php else: ?><?= $uyBos ?><?php endif; ?>
                                        </td></tr>
                                        <tr><th>RustDesk ID</th><td><?= $uyDeger($cihaz['Cihazlar_RustDeskId']) ?></td></tr>
                                        <tr><th>Ajan</th><td><?= $uyDeger($cihaz['Cihazlar_AjanSurum']) ?>
                                            <span class="text-muted small ms-2">kayıt <?= $uyE($cihaz['KayitTarihi']) ?></span></td></tr>
                                        <tr><th>Cihaz kimliği</th><td class="small"><span class="font-monospace"><?= $uyE($cihaz['Guid']) ?></span>
                                            <?= $cihaz['DonanimKimlikVar'] ? '<span class="badge text-bg-success ms-1" title="Anakart UUID + BIOS seri">donanım</span>' : '<span class="badge text-bg-secondary ms-1" title="Anakart UUID geçersiz; yeniden kurulumda yeni kayıt açılır">donanım kimliği yok</span>' ?></td></tr>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Donanım + Diskler -->
                        <div class="tab-pane fade" id="sekmeDonanim">
                            <?php if (!$donanim): ?>
                            <div class="alert alert-info mb-0"><i class="bi bi-hourglass-split me-1"></i>Donanım envanteri henüz alınmadı.</div>
                            <?php else: ?>
                            <div class="row g-4">
                                <div class="col-lg-6">
                                    <table class="table table-sm detay-tablo mb-0">
                                        <tr><th>Üretici</th><td><?= $uyDeger($donanim['CihazDonanim_Uretici']) ?></td></tr>
                                        <tr><th>Model</th><td><?= $uyDeger($donanim['CihazDonanim_Model']) ?></td></tr>
                                        <tr><th>Seri no</th><td class="font-monospace"><?= $uyDeger($donanim['CihazDonanim_SeriNo']) ?></td></tr>
                                        <tr><th>Anakart</th><td><?= $uyDeger($donanim['CihazDonanim_Anakart']) ?></td></tr>
                                        <tr><th>BIOS</th><td><?= $uyDeger($donanim['CihazDonanim_BiosSurum']) ?></td></tr>
                                    </table>
                                </div>
                                <div class="col-lg-6">
                                    <table class="table table-sm detay-tablo mb-0">
                                        <tr><th>İşlemci</th><td><?= $uyDeger($donanim['CihazDonanim_Islemci']) ?></td></tr>
                                        <tr><th>Çekirdek</th><td><?= $uyDeger($donanim['CihazDonanim_CekirdekSayisi']) ?></td></tr>
                                        <tr><th>RAM</th><td><?= $uyGb($donanim['CihazDonanim_RamGb']) ?> <span class="text-muted"><?= $uyE($donanim['CihazDonanim_RamDetay']) ?></span></td></tr>
                                        <tr><th>Ekran kartı</th><td><?= $uyDeger($donanim['CihazDonanim_EkranKarti']) ?></td></tr>
                                        <tr><th>Envanter tarihi</th><td><?= $uyDeger($donanim['Guncelleme']) ?></td></tr>
                                    </table>
                                </div>
                            </div>
                            <?php endif; ?>

                            <h6 class="mt-4 mb-2"><i class="bi bi-device-hdd me-1"></i>Diskler</h6>
                            <?php if (!$diskler): ?>
                            <div class="text-muted">Disk bilgisi yok.</div>
                            <?php else: ?>
                            <div class="row g-3">
                                <?php foreach ($diskler as $d):
                                    $toplam = (float) $d['CihazDiskleri_ToplamGb'];
                                    $dolu   = $toplam > 0 ? round(100 * ($toplam - (float) $d['CihazDiskleri_BosGb']) / $toplam) : 0;
                                    $renk   = $dolu >= 90 ? 'bg-danger' : ($dolu >= 75 ? 'bg-warning' : 'bg-success');
                                ?>
                                <div class="col-md-6 col-xl-4">
                                    <div class="border rounded p-3 h-100">
                                        <div class="d-flex justify-content-between">
                                            <strong><?= $uyE($d['CihazDiskleri_Surucu']) ?></strong>
                                            <?php if ($d['CihazDiskleri_Tip']): ?><span class="badge text-bg-light border"><?= $uyE($d['CihazDiskleri_Tip']) ?></span><?php endif; ?>
                                        </div>
                                        <div class="small text-muted text-truncate" title="<?= $uyE($d['CihazDiskleri_Model']) ?>"><?= $uyE($d['CihazDiskleri_Model'] ?: '-') ?></div>
                                        <div class="progress my-2" role="progressbar" aria-valuenow="<?= $dolu ?>" aria-valuemin="0" aria-valuemax="100" style="height: 10px;">
                                            <div class="progress-bar <?= $renk ?>" style="width: <?= $dolu ?>%"></div>
                                        </div>
                                        <div class="small">%<?= $dolu ?> dolu · <?= $uyGb($d['CihazDiskleri_BosGb']) ?> boş / <?= $uyGb($d['CihazDiskleri_ToplamGb']) ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Yazılımlar -->
                        <div class="tab-pane fade" id="sekmeYazilim">
                            <div class="card filter-box mb-3">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <button class="btn btn-link p-0 text-decoration-none" data-bs-toggle="collapse" data-bs-target="#filterPanel">
                                            <i class="bi bi-funnel me-1"></i> Filtreler
                                        </button>
                                    </h5>
                                </div>
                                <div id="filterPanel" class="collapse show">
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-12 col-md-6">
                                                <label class="form-label" for="filtreAra">Ara</label>
                                                <input type="text" class="form-control" id="filtreAra" placeholder="Yazılım adı, yayıncı, sürüm">
                                            </div>
                                            <div class="col-12 col-md-4">
                                                <label class="form-label" for="filtreMimari">Kaynak</label>
                                                <select class="form-select select2-basic" id="filtreMimari">
                                                    <option value="">Tümü</option>
                                                    <?php foreach ($mimariler as $m): ?>
                                                    <option value="<?= $uyE($m['Mimari']) ?>"><?= $uyE($m['Mimari']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12 col-md-2 d-flex align-items-end gap-2">
                                                <button type="button" class="btn btn-outline-secondary w-50" id="filtreTemizle" title="Temizle"><i class="bi bi-x-lg"></i></button>
                                                <button type="button" class="btn btn-primary w-50" id="filtreUygula" title="Filtrele"><i class="bi bi-search"></i></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <table id="yazilimTablo" class="table table-striped table-hover align-middle w-100">
                                <thead>
                                    <tr>
                                        <th>Yazılım</th>
                                        <th>Sürüm</th>
                                        <th>Yayıncı</th>
                                        <th>Kurulum</th>
                                        <th>Kaynak</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>

                        <!-- Komutlar -->
                        <div class="tab-pane fade" id="sekmeKomut">
                            <?php if (!$ajanKomutAlir): ?>
                            <div class="alert alert-warning py-2">
                                <i class="bi bi-exclamation-triangle me-1"></i>Bu cihazdaki ajan (<?= $uyE($cihaz['Cihazlar_AjanSurum'] ?: '?') ?>) komut çalıştıramaz.
                                Komutlar kuyrukta bekler; ajanı güncellemek için kurulum dosyasını cihazda yeniden çalıştırın.
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($yetki['can_add'])): ?>
                            <div class="text-end mb-2">
                                <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#hizliKomutModal">
                                    <i class="bi bi-lightning-charge me-1"></i>Hızlı Komut
                                </button>
                                <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#scriptGonderModal"<?= $scriptler ? '' : ' disabled title="Önce Scriptler sayfasından script ekleyin"' ?>>
                                    <i class="bi bi-send me-1"></i>Script Gönder
                                </button>
                            </div>
                            <?php endif; ?>
                            <table id="komutTablo" class="table table-striped table-hover align-middle w-100" data-iptal="<?= !empty($yetki['can_edit']) ? 1 : 0 ?>">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Komut</th>
                                        <th>Durum</th>
                                        <th>Çıkış</th>
                                        <th>Süre</th>
                                        <th>Gönderen</th>
                                        <th>Gönderim</th>
                                        <th class="text-end">İşlem</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>

                        <!-- İşlem geçmişi -->
                        <div class="tab-pane fade" id="sekmeLog">
                            <?php if (!$loglar): ?>
                            <div class="text-muted">Kayıt yok.</div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped align-middle mb-0">
                                    <thead><tr><th>Tarih</th><th>İşlem</th><th>Kullanıcı</th><th>IP</th><th>Detay</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($loglar as $l): ?>
                                        <tr>
                                            <td class="text-nowrap"><?= $uyE($l['Tarih']) ?></td>
                                            <td><code><?= $uyE($l['UzakYonetimLoglari_Islem']) ?></code></td>
                                            <td><?= $l['Kullanici'] ? $uyE($l['Kullanici']) : '<span class="text-muted">ajan</span>' ?></td>
                                            <td class="text-nowrap"><?= $uyE($l['UzakYonetimLoglari_Ip']) ?></td>
                                            <td class="small text-break"><?= $uyE($l['UzakYonetimLoglari_Detay']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Komut çıktısı -->
<div class="modal fade" id="ciktiModal" tabindex="-1" aria-labelledby="ciktiModalBaslik" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ciktiModalBaslik"><i class="bi bi-terminal me-1"></i><span></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <div class="small text-muted mb-2" id="ciktiZaman"></div>
                <div class="d-flex align-items-center mb-1">
                    <h6 class="mb-0">Çıktı</h6>
                    <button type="button" class="btn btn-outline-secondary btn-sm ms-auto cikti-kopya" data-hedef="#ciktiMetin" title="Çıktıyı kopyala"><i class="bi bi-clipboard"></i></button>
                </div>
                <pre class="cikti-kutu mb-3" id="ciktiMetin"></pre>
                <div id="ciktiHataAlan">
                    <div class="d-flex align-items-center mb-1">
                        <h6 class="mb-0">Hata</h6>
                        <button type="button" class="btn btn-outline-secondary btn-sm ms-auto cikti-kopya" data-hedef="#ciktiHata" title="Hatayı kopyala"><i class="bi bi-clipboard"></i></button>
                    </div>
                    <pre class="cikti-kutu hata mb-3" id="ciktiHata"></pre>
                </div>
                <details>
                    <summary class="small">Gönderilen script</summary>
                    <div class="text-end mt-1">
                        <button type="button" class="btn btn-outline-secondary btn-sm cikti-kopya" data-hedef="#ciktiScript" title="Script'i kopyala"><i class="bi bi-clipboard"></i></button>
                    </div>
                    <pre class="cikti-kutu mt-1 mb-0" id="ciktiScript"></pre>
                </details>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($yetki['can_add'])): ?>
<!-- Hızlı komut (tek seferlik, kaydedilmez) -->
<div class="modal fade" id="hizliKomutModal" tabindex="-1" aria-labelledby="hizliKomutBaslik" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="hizliKomutBaslik"><i class="bi bi-lightning-charge me-1"></i>Hızlı Komut: <?= $uyE($cihaz['Cihazlar_BilgisayarAdi']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 d-flex flex-wrap gap-1">
                    <button type="button" class="btn btn-outline-secondary btn-sm hk-hazir" data-komut='msg * /time:60 "Merhaba"' data-baslik="Ekran mesajı">Mesaj Göster</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm hk-hazir" data-komut='shutdown /r /t 60 /c "Uzak Yonetim: yeniden baslatiliyor."' data-baslik="Yeniden başlat">Yeniden Başlat (60 sn)</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm hk-hazir" data-komut='shutdown /s /t 60 /c "Uzak Yonetim: kapatiliyor."' data-baslik="Kapat">Kapat (60 sn)</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm hk-hazir" data-komut='shutdown /a' data-baslik="Kapatmayı iptal et">Kapatmayı İptal</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="hkAdDegistir" data-mevcut="<?= $uyE($cihaz['Cihazlar_BilgisayarAdi']) ?>">Bilgisayar Adı Değiştir</button>
                </div>
                <label class="form-label" for="hkIcerik">Komut (PowerShell / cmd — SYSTEM olarak çalışır)</label>
                <textarea class="form-control font-monospace kod-alani" id="hkIcerik" rows="6" spellcheck="false" placeholder='msg * "Merhaba"'></textarea>
                <div class="row g-2 mt-1">
                    <div class="col-8">
                        <label class="form-label small mb-0" for="hkBaslik">Başlık (listede görünür)</label>
                        <input type="text" class="form-control form-control-sm" id="hkBaslik" maxlength="200" value="Hızlı komut">
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-0" for="hkSure">Zaman aşımı (sn)</label>
                        <input type="number" class="form-control form-control-sm" id="hkSure" min="10" max="7200" value="300">
                    </div>
                </div>
                <div class="form-text">Tek seferlik; Scriptler'e kaydedilmez. Kapatma/yeniden başlatmada gecikme bırakın ki sonuç raporlanabilsin. Sonuç Komutlar listesinde görünür.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                <button type="button" class="btn btn-warning" id="hkGonder"><i class="bi bi-lightning-charge me-1"></i>Gönder</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($yetki['can_add']) && $scriptler): ?>
<!-- Script gönder (bu cihaz) -->
<div class="modal fade" id="scriptGonderModal" tabindex="-1" aria-labelledby="scriptGonderBaslik" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="scriptGonderBaslik"><i class="bi bi-send me-1"></i>Script Gönder: <?= $uyE($cihaz['Cihazlar_BilgisayarAdi']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <form id="scriptGonderForm">
                    <div class="mb-3">
                        <label class="form-label" for="sgScript">Script</label>
                        <select class="form-select select2-modal" id="sgScript" name="scriptId" required>
                            <option value="">Seçin</option>
                            <?php foreach ($scriptler as $s): ?>
                            <option value="<?= (int) $s['Scriptler_id'] ?>" data-parametreler="<?= $uyE($s['Scriptler_Parametreler']) ?>"><?= $uyE($s['Scriptler_Ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="sgParametreler"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                <button type="button" class="btn btn-warning" id="sgGonder"><i class="bi bi-send me-1"></i>Gönder</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($yetki['can_edit'])): ?>
<!-- Düzenle -->
<div class="modal fade" id="duzenleModal" tabindex="-1" aria-labelledby="duzenleModalBaslik" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="duzenleModalBaslik"><i class="bi bi-pencil me-1"></i>Cihazı Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <form id="duzenleForm">
                    <div class="mb-3">
                        <label class="form-label" for="duzenleGrup">Grup</label>
                        <select class="form-select select2-modal" id="duzenleGrup" name="grup" required>
                            <?php foreach ($gruplar as $g): ?>
                            <option value="<?= (int) $g['CihazGruplari_id'] ?>"<?= (int) $g['CihazGruplari_id'] === (int) $cihaz['Cihazlar_CihazGruplari_id'] ? ' selected' : '' ?>><?= $uyE($g['CihazGruplari_Ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="duzenleAciklama">Açıklama</label>
                        <input type="text" class="form-control" id="duzenleAciklama" name="aciklama" maxlength="500" value="<?= $uyE($cihaz['Cihazlar_Aciklama']) ?>" placeholder="Ör. Muhasebe - Ayşe Hanım">
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="duzenlePilot" name="pilot" value="1"<?= $cihaz['Cihazlar_PilotMu'] ? ' checked' : '' ?>>
                        <label class="form-check-label" for="duzenlePilot">Pilot cihaz (ajan güncellemelerini önce alır)</label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                <button type="button" class="btn btn-primary" id="duzenleKaydet"><i class="bi bi-check-lg me-1"></i>Kaydet</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>
<script src="/admin/assets/js/uzak-yonetim.js?v=<?= @filemtime(__DIR__ . '/../assets/js/uzak-yonetim.js') ?>"></script>
<script src="/admin/assets/js/uzak-cihaz-detay.js?v=<?= @filemtime(__DIR__ . '/../assets/js/uzak-cihaz-detay.js') ?>"></script>
</body>
</html>
