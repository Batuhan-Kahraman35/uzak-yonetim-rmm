<?php
/**
 * Uzak Yönetim - Scriptler
 * PowerShell script kütüphanesi: ekle / düzenle / sil, ilk kurulum sırası, cihaz veya gruba gönderme.
 * Tanım tablosu (az satır) → client-side DataTables. Gönderilen komutların sonuçları cihaz detayında.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/UzakYonetim.php';
requireAuth();

$user  = Auth::user();
$db    = Database::getInstance();
$udb   = UzakDb::al();
$yetki = uzakSayfaYetkisi($user);

const SCRIPT_AZAMI_BAYT = 200 * 1024;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfDogrula();

    try {
        switch ($_POST['action'] ?? '') {
            case 'istatistik':
                $s = $udb->tek(
                    "SELECT COUNT(*) AS Toplam,
                            SUM(CASE WHEN Scriptler_IlkKurulumSira IS NOT NULL THEN 1 ELSE 0 END) AS IlkKurulum
                     FROM dbo.Scriptler WHERE Durum = 1"
                );
                $k = $udb->tek(
                    "SELECT SUM(CASE WHEN h.OlusturmaTarihi >= DATEADD(HOUR, -24, GETDATE()) THEN 1 ELSE 0 END) AS Son24,
                            SUM(CASE WHEN d.Tanim_KomutDurumlari_BittiMi = 0 THEN 1 ELSE 0 END) AS Acik
                     FROM dbo.KomutHedefleri h
                     INNER JOIN dbo.Tanim_KomutDurumlari d ON d.Tanim_KomutDurumlari_id = h.KomutHedefleri_Tanim_KomutDurumlari_id
                     WHERE h.OlusturmaTarihi >= DATEADD(DAY, -30, GETDATE()) OR d.Tanim_KomutDurumlari_BittiMi = 0"
                );
                jsonCevap(['basarili' => true, 'veri' => [
                    'toplam'     => (int) $s['Toplam'],
                    'ilkKurulum' => (int) $s['IlkKurulum'],
                    'son24'      => (int) ($k['Son24'] ?? 0),
                    'acik'       => (int) ($k['Acik'] ?? 0),
                ]]);

            case 'liste':
                $kosul = ['s.Durum = 1'];
                $param = [];
                $ik = (string) ($_POST['ilkKurulum'] ?? '');
                if ($ik === '1') {
                    $kosul[] = 's.Scriptler_IlkKurulumSira IS NOT NULL';
                } elseif ($ik === '0') {
                    $kosul[] = 's.Scriptler_IlkKurulumSira IS NULL';
                }
                $ara = trim((string) ($_POST['ara'] ?? ''));
                if ($ara !== '') {
                    $kosul[] = '(s.Scriptler_Ad LIKE ? OR s.Scriptler_Aciklama LIKE ?)';
                    array_push($param, '%' . $ara . '%', '%' . $ara . '%');
                }
                $veri = $udb->hepsi(
                    "SELECT s.Scriptler_id, s.Scriptler_Ad, s.Scriptler_Aciklama, s.Scriptler_Parametreler,
                            s.Scriptler_ZamanAsimiSn, s.Scriptler_IlkKurulumSira,
                            CONVERT(VARCHAR(16), ISNULL(s.GuncellemeTarihi, s.OlusturmaTarihi), 120) AS Guncelleme,
                            u.kullanici_ad + ' ' + u.kullanici_soyad AS Guncelleyen
                     FROM dbo.Scriptler s
                     LEFT JOIN dbo.kullanicilar u ON u.kullanici_id = ISNULL(s.GuncelleyenKullanici, s.OlusturanKullanici)
                     WHERE " . implode(' AND ', $kosul) . "
                     ORDER BY CASE WHEN s.Scriptler_IlkKurulumSira IS NULL THEN 1 ELSE 0 END, s.Scriptler_IlkKurulumSira, s.Scriptler_Ad",
                    $param
                );
                jsonCevap(['data' => $veri]);

            case 'getir':
                $s = $udb->tek(
                    'SELECT Scriptler_id, Scriptler_Ad, Scriptler_Aciklama, Scriptler_Icerik, Scriptler_Parametreler,
                            Scriptler_ZamanAsimiSn, Scriptler_IlkKurulumSira
                     FROM dbo.Scriptler WHERE Scriptler_id = ? AND Durum = 1',
                    [(int) ($_POST['id'] ?? 0)]
                );
                if (!$s) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Script bulunamadı.'], 404);
                }
                jsonCevap(['basarili' => true, 'veri' => $s]);

            case 'kaydet':
                $id = (int) ($_POST['id'] ?? 0);
                if (empty($yetki[$id > 0 ? 'can_edit' : 'can_add'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Bu işlem için yetkiniz yok.'], 403);
                }
                $ad       = kirp($_POST['ad'] ?? null, 150);
                $aciklama = kirp($_POST['aciklama'] ?? null, 1000);
                $icerik   = str_replace("\r\n", "\n", (string) ($_POST['icerik'] ?? ''));
                $sure     = (int) ($_POST['zamanAsimi'] ?? 0);
                $ilk      = ($_POST['ilkKurulum'] ?? '') === '1' ? max(1, (int) ($_POST['ilkKurulumSira'] ?? 1)) : null;

                if ($ad === null || trim($icerik) === '') {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Ad ve içerik zorunlu.'], 422);
                }
                if (strlen($icerik) > SCRIPT_AZAMI_BAYT) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Script en fazla 200 KB olabilir.'], 422);
                }
                if ($sure < 10 || $sure > 7200) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Zaman aşımı 10–7200 sn arasında olmalı.'], 422);
                }
                // PowerShell sözdizimi burada doğrulanmaz (sunucuda PS yok sayılır); hata ajan çıktısında görünür
                try {
                    $tanim = parametreTanimiDogrula($_POST['parametreler'] ?? '');
                } catch (InvalidArgumentException $h) {
                    jsonCevap(['basarili' => false, 'mesaj' => $h->getMessage()], 422);
                }
                $tanimJson = $tanim ? json_encode($tanim, JSON_UNESCAPED_UNICODE) : null;

                if ($id > 0) {
                    $adet = $udb->calistir(
                        'UPDATE dbo.Scriptler SET Scriptler_Ad = ?, Scriptler_Aciklama = ?, Scriptler_Icerik = ?, Scriptler_Parametreler = ?,
                                Scriptler_ZamanAsimiSn = ?, Scriptler_IlkKurulumSira = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                         WHERE Scriptler_id = ? AND Durum = 1',
                        [$ad, $aciklama, $icerik, $tanimJson, $sure, $ilk, $user['kullanici_id'], $id]
                    );
                    if ($adet === 0) {
                        jsonCevap(['basarili' => false, 'mesaj' => 'Script bulunamadı.'], 404);
                    }
                    logYaz('script_guncelle', ['id' => $id, 'ad' => $ad, 'ilkKurulumSira' => $ilk, 'sha256' => hash('sha256', $icerik)]);
                } else {
                    $id = (int) $udb->tek(
                        'INSERT INTO dbo.Scriptler (Scriptler_Ad, Scriptler_Aciklama, Scriptler_Icerik, Scriptler_Parametreler,
                                Scriptler_ZamanAsimiSn, Scriptler_IlkKurulumSira, OlusturanKullanici)
                         OUTPUT inserted.Scriptler_id VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$ad, $aciklama, $icerik, $tanimJson, $sure, $ilk, $user['kullanici_id']]
                    )['Scriptler_id'];
                    logYaz('script_ekle', ['id' => $id, 'ad' => $ad, 'ilkKurulumSira' => $ilk, 'sha256' => hash('sha256', $icerik)]);
                }
                jsonCevap(['basarili' => true, 'mesaj' => 'Script kaydedildi.', 'id' => $id]);

            case 'sil':
                if (empty($yetki['can_delete'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Silme yetkiniz yok.'], 403);
                }
                $id = (int) ($_POST['id'] ?? 0);
                // Pasife alınır: gönderilmiş komutlar script kaydını referans almaya devam eder
                $adet = $udb->calistir(
                    'UPDATE dbo.Scriptler SET Durum = 0, Scriptler_IlkKurulumSira = NULL, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                     WHERE Scriptler_id = ? AND Durum = 1',
                    [$user['kullanici_id'], $id]
                );
                if ($adet === 0) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Script bulunamadı.'], 404);
                }
                logYaz('script_sil', ['id' => $id]);
                jsonCevap(['basarili' => true, 'mesaj' => 'Script silindi.']);

            case 'gonder':
                if (empty($yetki['can_add'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Komut gönderme yetkiniz yok.'], 403);
                }
                $cihazIdler = array_map('intval', (array) ($_POST['cihazlar'] ?? []));
                $grupIdler  = array_filter(array_map('intval', (array) ($_POST['gruplar'] ?? [])));
                if ($grupIdler) {
                    $yer = implode(',', array_fill(0, count($grupIdler), '?'));
                    $cihazIdler = array_merge($cihazIdler, array_column($udb->hepsi(
                        "SELECT Cihazlar_id FROM dbo.Cihazlar WHERE Durum = 1 AND Cihazlar_CihazGruplari_id IN ($yer)",
                        array_values($grupIdler)
                    ), 'Cihazlar_id'));
                }
                try {
                    $s = scriptGonder((int) ($_POST['id'] ?? 0), $cihazIdler, (array) ($_POST['degerler'] ?? []), (int) $user['kullanici_id']);
                } catch (InvalidArgumentException $h) {
                    jsonCevap(['basarili' => false, 'mesaj' => $h->getMessage()], 422);
                }
                $mesaj = "Komut {$s['hedef']} cihaza kuyruklandı.";
                if ($s['eskiAjan'] > 0) {
                    $mesaj .= " {$s['eskiAjan']} cihazda ajan 0.2.0'dan eski; ajan güncellenene kadar komutu almaz.";
                }
                jsonCevap(['basarili' => true, 'mesaj' => $mesaj, 'uyari' => $s['eskiAjan'] > 0]);

            default:
                jsonCevap(['basarili' => false, 'mesaj' => 'Geçersiz işlem.'], 400);
        }
    } catch (Throwable $h) {
        error_log('uzak-scriptler.php: ' . $h->getMessage());
        jsonCevap(['basarili' => false, 'mesaj' => 'İşlem sırasında bir hata oluştu.'], 500);
    }
}

$cihazlar = $udb->hepsi(
    "SELECT c.Cihazlar_id, c.Cihazlar_BilgisayarAdi, c.Cihazlar_Aciklama, g.CihazGruplari_Ad
     FROM dbo.Cihazlar c LEFT JOIN dbo.CihazGruplari g ON g.CihazGruplari_id = c.Cihazlar_CihazGruplari_id
     WHERE c.Durum = 1 ORDER BY c.Cihazlar_BilgisayarAdi"
);
$gruplar  = $udb->hepsi(
    'SELECT g.CihazGruplari_id, g.CihazGruplari_Ad, COUNT(c.Cihazlar_id) AS Adet
     FROM dbo.CihazGruplari g LEFT JOIN dbo.Cihazlar c ON c.Cihazlar_CihazGruplari_id = g.CihazGruplari_id AND c.Durum = 1
     WHERE g.Durum = 1 GROUP BY g.CihazGruplari_id, g.CihazGruplari_Ad ORDER BY g.CihazGruplari_Ad'
);
$ayarlar  = $udb->hepsi('SELECT UzakYonetimAyarlari_Anahtar FROM dbo.UzakYonetimAyarlari WHERE Durum = 1 ORDER BY 1');

$pageInfo = $db->fetchOne(
    "SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi AS menu_adi
     FROM Menu_Sayfalar s LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
     WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1",
    ['%' . basename($_SERVER['PHP_SELF'])]
);
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Scriptler';
$menuAdi   = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';
$uyE = fn($m) => htmlspecialchars((string) ($m ?? ''), ENT_QUOTES, 'UTF-8');
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
                    <div class="col-sm-6"><h3 class="mb-0"><?= $uyE($pageTitle) ?></h3></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <?php if ($menuAdi): ?><li class="breadcrumb-item"><?= $uyE($menuAdi) ?></li><?php endif; ?>
                            <li class="breadcrumb-item active"><?= $uyE($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">

                <!-- InfoBox -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-primary">
                            <div class="inner"><h3 id="istToplam">-</h3><p>Script</p></div>
                            <i class="small-box-icon bi bi-file-earmark-code"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-success">
                            <div class="inner"><h3 id="istIlkKurulum">-</h3><p>İlk Kurulumda</p></div>
                            <i class="small-box-icon bi bi-list-ol"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-info">
                            <div class="inner"><h3 id="istSon24">-</h3><p>Son 24 Saatte Gönderilen</p></div>
                            <i class="small-box-icon bi bi-send"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-warning">
                            <div class="inner"><h3 id="istAcik">-</h3><p>Bekleyen / Çalışan</p></div>
                            <i class="small-box-icon bi bi-hourglass-split"></i>
                        </div>
                    </div>
                </div>

                <!-- Filtre -->
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
                                    <input type="text" class="form-control" id="filtreAra" placeholder="Ad, açıklama">
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="filtreIlk">İlk kurulum</label>
                                    <select class="form-select select2-basic" id="filtreIlk">
                                        <option value="">Tümü</option>
                                        <option value="1">İlk kurulumda çalışanlar</option>
                                        <option value="0">İlk kurulumda çalışmayanlar</option>
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

                <!-- Liste -->
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <h3 class="card-title mb-0">Script Kütüphanesi</h3>
                        <?php if (!empty($yetki['can_add'])): ?>
                        <button type="button" class="btn btn-success btn-sm ms-auto" id="yeniScript"><i class="bi bi-plus-lg me-1"></i>Yeni Script</button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <table id="scriptTablo" class="table table-striped table-hover align-middle w-100"
                               data-duzenle="<?= !empty($yetki['can_edit']) ? 1 : 0 ?>" data-sil="<?= !empty($yetki['can_delete']) ? 1 : 0 ?>" data-gonder="<?= !empty($yetki['can_add']) ? 1 : 0 ?>">
                            <thead>
                                <tr>
                                    <th>İlk Kurulum</th>
                                    <th>Ad</th>
                                    <th>Parametreler</th>
                                    <th>Zaman Aşımı</th>
                                    <th>Güncelleme</th>
                                    <th class="text-end">İşlem</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Ekle / Düzenle -->
<div class="modal fade" id="scriptModal" tabindex="-1" aria-labelledby="scriptModalBaslik" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="scriptModalBaslik"><i class="bi bi-file-earmark-code me-1"></i><span>Script</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <form id="scriptForm">
                    <input type="hidden" name="id" id="scriptId">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="scriptAd">Ad</label>
                            <input type="text" class="form-control" id="scriptAd" name="ad" maxlength="150" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="scriptSure">Zaman aşımı (sn)</label>
                            <input type="number" class="form-control" id="scriptSure" name="zamanAsimi" min="10" max="7200" value="300" required>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="scriptIlk" name="ilkKurulum" value="1">
                                <label class="form-check-label" for="scriptIlk">İlk kurulumda</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="scriptIlkSira">Sıra</label>
                            <input type="number" class="form-control" id="scriptIlkSira" name="ilkKurulumSira" min="1" max="999" value="1" disabled>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="scriptAciklama">Açıklama</label>
                            <input type="text" class="form-control" id="scriptAciklama" name="aciklama" maxlength="1000">
                        </div>
                        <div class="col-lg-8">
                            <label class="form-label" for="scriptIcerik">PowerShell (SYSTEM olarak çalışır)</label>
                            <textarea class="form-control font-monospace kod-alani" id="scriptIcerik" name="icerik" rows="18" spellcheck="false" required></textarea>
                        </div>
                        <div class="col-lg-4">
                            <label class="form-label" for="scriptParametreler">Parametre tanımı (JSON)</label>
                            <textarea class="form-control font-monospace kod-alani" id="scriptParametreler" name="parametreler" rows="8" spellcheck="false" placeholder='[{"ad":"Sifre","tip":"ayar","ayarAnahtari":"anydesk_sifre"}]'></textarea>
                            <div class="form-text small">
                                Script'te aynı adla <code>param()</code> bloğu olmalı. Tipler:
                                <ul class="ps-3 mb-1">
                                    <li><code>metin</code> / <code>sayi</code>: gönderirken sorulur (<code>etiket</code>, <code>varsayilan</code>)</li>
                                    <li><code>ayar</code>: <code>ayarAnahtari</code> değeri teslim anında eklenir (şifreliler dahil)</li>
                                    <li><code>yeniBilgisayarAdi</code>: DB'de olmayan aday listesi (<code>onek</code>, <code>adet</code>)</li>
                                </ul>
                                Ayarlar: <?= implode(', ', array_map(fn($a) => '<code>' . $uyE($a['UzakYonetimAyarlari_Anahtar']) . '</code>', $ayarlar)) ?><br>
                                Yardımcı: <code>Get-UzakPaket -Paket 'Ad' -Hedef 'C:\Klasor'</code> (depodan SHA256 doğrulamalı indirir).
                                Çıkış kodu 0 dışı ya da hata = <em>Hatalı</em>.
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                <button type="button" class="btn btn-primary" id="scriptKaydet"><i class="bi bi-check-lg me-1"></i>Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- Gönder -->
<div class="modal fade" id="gonderModal" tabindex="-1" aria-labelledby="gonderModalBaslik" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="gonderModalBaslik"><i class="bi bi-send me-1"></i>Gönder: <span></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <form id="gonderForm">
                    <input type="hidden" name="id" id="gonderId">
                    <div class="mb-3">
                        <label class="form-label" for="gonderCihazlar">Cihazlar</label>
                        <select class="form-select select2-modal" id="gonderCihazlar" name="cihazlar[]" multiple data-placeholder="Cihaz seçin">
                            <?php foreach ($cihazlar as $c): ?>
                            <option value="<?= (int) $c['Cihazlar_id'] ?>"><?= $uyE($c['Cihazlar_BilgisayarAdi'] . ($c['Cihazlar_Aciklama'] ? ' — ' . $c['Cihazlar_Aciklama'] : '') . ' (' . ($c['CihazGruplari_Ad'] ?? '-') . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="gonderGruplar">ve/veya Gruplar</label>
                        <select class="form-select select2-modal" id="gonderGruplar" name="gruplar[]" multiple data-placeholder="Grup seçin">
                            <?php foreach ($gruplar as $g): ?>
                            <option value="<?= (int) $g['CihazGruplari_id'] ?>"><?= $uyE($g['CihazGruplari_Ad']) ?> (<?= (int) $g['Adet'] ?> cihaz)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="gonderParametreler"></div>
                    <div class="alert alert-light border small mb-0">
                        <i class="bi bi-info-circle me-1"></i>Komut <?= (int) ayar('komut_varsayilan_gecerlilik_saat', 24) ?> saat içinde çevrimiçi olan cihazlarda çalışır;
                        sonuç cihaz detayındaki <strong>Komutlar</strong> sekmesinde görünür.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                <button type="button" class="btn btn-warning" id="gonderOnay"><i class="bi bi-send me-1"></i>Gönder</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>
<script src="/admin/assets/js/uzak-yonetim.js?v=<?= @filemtime(__DIR__ . '/../assets/js/uzak-yonetim.js') ?>"></script>
<script src="/admin/assets/js/uzak-scriptler.js?v=<?= @filemtime(__DIR__ . '/../assets/js/uzak-scriptler.js') ?>"></script>
</body>
</html>
