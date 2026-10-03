<?php
/**
 * Uzak Yönetim - Cihaz Grupları
 * Cihazların ve kayıt kodlarının bağlandığı gruplar (dbo.CihazGruplari) ve cihaz etiketleri (dbo.Etiketler).
 * Tanım tablosu (az satır) → client-side DataTables.
 * Kullanımda olan grup silinmez, pasife alınır; pasif grup yeni seçimlerde listelenmez.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/UzakYonetim.php';
requireAuth();

$user  = Auth::user();
$db    = Database::getInstance();
$udb   = UzakDb::al();
$yetki = uzakSayfaYetkisi($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfDogrula();

    try {
        switch ($_POST['action'] ?? '') {
            case 'istatistik':
                $s = $udb->tek(
                    // MSSQL aggregate içinde alt sorguya izin vermez; cihaz varlığı türetilmiş tabloda hesaplanır
                    'SELECT COUNT(*) AS Toplam,
                            ISNULL(SUM(CASE WHEN d.Durum = 1 THEN 1 ELSE 0 END), 0) AS Aktif,
                            ISNULL(SUM(1 - d.CihazVar), 0) AS Bos
                     FROM (SELECT g.Durum,
                                  CASE WHEN EXISTS (SELECT 1 FROM dbo.Cihazlar c
                                       WHERE c.Cihazlar_CihazGruplari_id = g.CihazGruplari_id AND c.Durum = 1) THEN 1 ELSE 0 END AS CihazVar
                           FROM dbo.CihazGruplari g) d'
                );
                jsonCevap(['basarili' => true, 'veri' => [
                    'toplam'        => (int) $s['Toplam'],
                    'aktif'         => (int) $s['Aktif'],
                    'bos'           => (int) $s['Bos'],
                    'etiket'        => (int) $udb->deger('SELECT COUNT(*) FROM dbo.Etiketler WHERE Durum = 1'),
                    'etiketliCihaz' => (int) $udb->deger(
                        'SELECT COUNT(DISTINCT ce.CihazEtiketleri_Cihazlar_id)
                         FROM dbo.CihazEtiketleri ce
                         INNER JOIN dbo.Etiketler e ON e.Etiketler_id = ce.CihazEtiketleri_Etiketler_id AND e.Durum = 1
                         INNER JOIN dbo.Cihazlar c ON c.Cihazlar_id = ce.CihazEtiketleri_Cihazlar_id AND c.Durum = 1'
                    ),
                ]]);

            case 'liste':
                $kosul = ['1 = 1'];
                $param = [];
                $durum = (string) ($_POST['durum'] ?? '');
                if ($durum === '1' || $durum === '0') {
                    $kosul[] = 'g.Durum = ?';
                    $param[] = (int) $durum;
                }
                $ara = trim((string) ($_POST['ara'] ?? ''));
                if ($ara !== '') {
                    $kosul[] = '(g.CihazGruplari_Ad LIKE ? OR g.CihazGruplari_Aciklama LIKE ?)';
                    $param[] = '%' . $ara . '%';
                    $param[] = '%' . $ara . '%';
                }

                $veri = $udb->hepsi(
                    'SELECT g.CihazGruplari_id, g.CihazGruplari_Ad, g.CihazGruplari_Aciklama, CAST(g.Durum AS INT) AS Durum,
                            (SELECT COUNT(*) FROM dbo.Cihazlar c
                             WHERE c.Cihazlar_CihazGruplari_id = g.CihazGruplari_id AND c.Durum = 1) AS CihazAdet,
                            (SELECT COUNT(*) FROM dbo.KayitKodlari k
                             WHERE k.KayitKodlari_CihazGruplari_id = g.CihazGruplari_id AND k.Durum = 1
                               AND k.KayitKodlari_SonKullanmaTarihi > GETDATE()
                               AND k.KayitKodlari_KullanimSayisi < k.KayitKodlari_KullanimLimiti) AS KodAdet,
                            CONVERT(VARCHAR(16), ISNULL(g.GuncellemeTarihi, g.OlusturmaTarihi), 120) AS SonIslem
                     FROM dbo.CihazGruplari g
                     WHERE ' . implode(' AND ', $kosul) . '
                     ORDER BY g.CihazGruplari_Ad',
                    $param
                );
                jsonCevap(['data' => $veri]);

            case 'kaydet':
                $id = (int) ($_POST['id'] ?? 0);
                if (empty($yetki[$id > 0 ? 'can_edit' : 'can_add'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Bu işlem için yetkiniz yok.'], 403);
                }
                $ad       = kirp($_POST['ad'] ?? null, 100);
                $aciklama = kirp($_POST['aciklama'] ?? null, 500);
                if ($ad === null) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Grup adı zorunludur.'], 422);
                }
                if ($udb->deger('SELECT 1 FROM dbo.CihazGruplari WHERE CihazGruplari_Ad = ? AND CihazGruplari_id <> ?', [$ad, $id])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Bu adda bir grup zaten var.'], 422);
                }

                if ($id > 0) {
                    $adet = $udb->calistir(
                        'UPDATE dbo.CihazGruplari
                         SET CihazGruplari_Ad = ?, CihazGruplari_Aciklama = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                         WHERE CihazGruplari_id = ?',
                        [$ad, $aciklama, $user['kullanici_id'], $id]
                    );
                    if ($adet === 0) {
                        jsonCevap(['basarili' => false, 'mesaj' => 'Grup bulunamadı.'], 404);
                    }
                    logYaz('cihaz_grubu_duzenle', ['id' => $id, 'ad' => $ad]);
                    jsonCevap(['basarili' => true, 'mesaj' => 'Grup güncellendi.']);
                }

                $yeniId = $udb->tek(
                    'INSERT INTO dbo.CihazGruplari (CihazGruplari_Ad, CihazGruplari_Aciklama, OlusturanKullanici)
                     OUTPUT inserted.CihazGruplari_id
                     VALUES (?, ?, ?)',
                    [$ad, $aciklama, $user['kullanici_id']]
                )['CihazGruplari_id'];
                logYaz('cihaz_grubu_ekle', ['id' => (int) $yeniId, 'ad' => $ad]);
                jsonCevap(['basarili' => true, 'mesaj' => 'Grup eklendi.']);

            case 'durum':
                if (empty($yetki['can_edit'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Bu işlem için yetkiniz yok.'], 403);
                }
                $id    = (int) ($_POST['id'] ?? 0);
                $durum = (int) ($_POST['durum'] ?? 0) === 1 ? 1 : 0;
                // Son aktif grup pasife alınamaz: cihaz düzenleme ve kayıt kodu en az bir aktif grup ister
                if ($durum === 0 && (int) $udb->deger('SELECT COUNT(*) FROM dbo.CihazGruplari WHERE Durum = 1 AND CihazGruplari_id <> ?', [$id]) === 0) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'En az bir aktif grup kalmalı.'], 422);
                }
                $adet = $udb->calistir(
                    'UPDATE dbo.CihazGruplari SET Durum = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                     WHERE CihazGruplari_id = ?',
                    [$durum, $user['kullanici_id'], $id]
                );
                if ($adet === 0) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Grup bulunamadı.'], 404);
                }
                logYaz('cihaz_grubu_durum', ['id' => $id, 'durum' => $durum]);
                jsonCevap(['basarili' => true, 'mesaj' => $durum ? 'Grup aktifleştirildi.' : 'Grup pasife alındı.']);

            case 'sil':
                if (empty($yetki['can_delete'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Bu işlem için yetkiniz yok.'], 403);
                }
                $id = (int) ($_POST['id'] ?? 0);
                // Cihaz ya da kayıt kodu (pasifler dahil, FK) bağlıysa silinmez
                $bagli = $udb->deger(
                    'SELECT (SELECT COUNT(*) FROM dbo.Cihazlar WHERE Cihazlar_CihazGruplari_id = ?)
                          + (SELECT COUNT(*) FROM dbo.KayitKodlari WHERE KayitKodlari_CihazGruplari_id = ?)',
                    [$id, $id]
                );
                if ((int) $bagli > 0) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Bu gruba bağlı cihaz veya kayıt kodu var; silmek yerine pasife alın.'], 422);
                }
                if ((int) $udb->deger('SELECT COUNT(*) FROM dbo.CihazGruplari WHERE Durum = 1 AND CihazGruplari_id <> ?', [$id]) === 0) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'En az bir aktif grup kalmalı.'], 422);
                }
                $adet = $udb->calistir('DELETE FROM dbo.CihazGruplari WHERE CihazGruplari_id = ?', [$id]);
                if ($adet === 0) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Grup bulunamadı.'], 404);
                }
                logYaz('cihaz_grubu_sil', ['id' => $id]);
                jsonCevap(['basarili' => true, 'mesaj' => 'Grup silindi.']);

            /* ---------- Etiketler ---------- */

            case 'etiket_liste':
                $kosul = ['1 = 1'];
                $param = [];
                $durum = (string) ($_POST['durum'] ?? '');
                if ($durum === '1' || $durum === '0') {
                    $kosul[] = 'e.Durum = ?';
                    $param[] = (int) $durum;
                }
                $ara = trim((string) ($_POST['ara'] ?? ''));
                if ($ara !== '') {
                    $kosul[] = '(e.Etiketler_Ad LIKE ? OR e.Etiketler_Aciklama LIKE ?)';
                    $param[] = '%' . $ara . '%';
                    $param[] = '%' . $ara . '%';
                }

                $veri = $udb->hepsi(
                    'SELECT e.Etiketler_id, e.Etiketler_Ad, e.Etiketler_Renk, e.Etiketler_Aciklama, CAST(e.Durum AS INT) AS Durum,
                            (SELECT COUNT(*) FROM dbo.CihazEtiketleri ce
                             INNER JOIN dbo.Cihazlar c ON c.Cihazlar_id = ce.CihazEtiketleri_Cihazlar_id AND c.Durum = 1
                             WHERE ce.CihazEtiketleri_Etiketler_id = e.Etiketler_id) AS CihazAdet,
                            CONVERT(VARCHAR(16), ISNULL(e.GuncellemeTarihi, e.OlusturmaTarihi), 120) AS SonIslem
                     FROM dbo.Etiketler e
                     WHERE ' . implode(' AND ', $kosul) . '
                     ORDER BY e.Etiketler_Ad',
                    $param
                );
                jsonCevap(['data' => $veri]);

            case 'etiket_kaydet':
                $id = (int) ($_POST['id'] ?? 0);
                if (empty($yetki[$id > 0 ? 'can_edit' : 'can_add'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Bu işlem için yetkiniz yok.'], 403);
                }
                $ad       = kirp($_POST['ad'] ?? null, 50);
                $aciklama = kirp($_POST['aciklama'] ?? null, 250);
                $renk     = strtolower((string) ($_POST['renk'] ?? ''));
                if ($ad === null) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Etiket adı zorunludur.'], 422);
                }
                if (!preg_match('/^#[0-9a-f]{6}$/', $renk)) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Geçerli bir renk seçin.'], 422);
                }
                if ($udb->deger('SELECT 1 FROM dbo.Etiketler WHERE Etiketler_Ad = ? AND Etiketler_id <> ?', [$ad, $id])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Bu adda bir etiket zaten var.'], 422);
                }

                if ($id > 0) {
                    $adet = $udb->calistir(
                        'UPDATE dbo.Etiketler
                         SET Etiketler_Ad = ?, Etiketler_Renk = ?, Etiketler_Aciklama = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                         WHERE Etiketler_id = ?',
                        [$ad, $renk, $aciklama, $user['kullanici_id'], $id]
                    );
                    if ($adet === 0) {
                        jsonCevap(['basarili' => false, 'mesaj' => 'Etiket bulunamadı.'], 404);
                    }
                    logYaz('etiket_duzenle', ['id' => $id, 'ad' => $ad, 'renk' => $renk]);
                    jsonCevap(['basarili' => true, 'mesaj' => 'Etiket güncellendi.']);
                }

                $yeniId = $udb->tek(
                    'INSERT INTO dbo.Etiketler (Etiketler_Ad, Etiketler_Renk, Etiketler_Aciklama, OlusturanKullanici)
                     OUTPUT inserted.Etiketler_id
                     VALUES (?, ?, ?, ?)',
                    [$ad, $renk, $aciklama, $user['kullanici_id']]
                )['Etiketler_id'];
                logYaz('etiket_ekle', ['id' => (int) $yeniId, 'ad' => $ad, 'renk' => $renk]);
                jsonCevap(['basarili' => true, 'mesaj' => 'Etiket eklendi.']);

            case 'etiket_durum':
                if (empty($yetki['can_edit'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Bu işlem için yetkiniz yok.'], 403);
                }
                $id    = (int) ($_POST['id'] ?? 0);
                $durum = (int) ($_POST['durum'] ?? 0) === 1 ? 1 : 0;
                $adet  = $udb->calistir(
                    'UPDATE dbo.Etiketler SET Durum = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                     WHERE Etiketler_id = ?',
                    [$durum, $user['kullanici_id'], $id]
                );
                if ($adet === 0) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Etiket bulunamadı.'], 404);
                }
                logYaz('etiket_durum', ['id' => $id, 'durum' => $durum]);
                jsonCevap(['basarili' => true, 'mesaj' => $durum ? 'Etiket aktifleştirildi.' : 'Etiket pasife alındı; cihazlarda görünmez.']);

            case 'etiket_sil':
                if (empty($yetki['can_delete'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Bu işlem için yetkiniz yok.'], 403);
                }
                $id = (int) ($_POST['id'] ?? 0);
                // Etiket cihazlardan da kaldırılır (atama satırları + tanım tek transaction)
                $adet = $udb->islem(function (UzakDb $t) use ($id) {
                    $kaldirilan = $t->calistir('DELETE FROM dbo.CihazEtiketleri WHERE CihazEtiketleri_Etiketler_id = ?', [$id]);
                    return [$kaldirilan, $t->calistir('DELETE FROM dbo.Etiketler WHERE Etiketler_id = ?', [$id])];
                });
                if ($adet[1] === 0) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Etiket bulunamadı.'], 404);
                }
                logYaz('etiket_sil', ['id' => $id, 'kaldirilanAtama' => $adet[0]]);
                jsonCevap(['basarili' => true, 'mesaj' => 'Etiket silindi' . ($adet[0] ? " ({$adet[0]} cihazdan kaldırıldı)." : '.')]);

            default:
                jsonCevap(['basarili' => false, 'mesaj' => 'Geçersiz işlem.'], 400);
        }
    } catch (Throwable $h) {
        error_log('uzak-cihaz-gruplari.php: ' . $h->getMessage());
        jsonCevap(['basarili' => false, 'mesaj' => 'İşlem sırasında bir hata oluştu.'], 500);
    }
}

$pageInfo = $db->fetchOne(
    "SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi AS menu_adi
     FROM Menu_Sayfalar s
     LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
     WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1",
    ['%' . basename($_SERVER['PHP_SELF'])]
);
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Gruplar ve Etiketler';
$menuAdi   = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>

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
                        <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <?php if ($menuAdi): ?>
                            <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                            <?php endif; ?>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
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
                            <div class="inner"><h3 id="istAktif">-</h3><p>Aktif Grup <small class="opacity-75">/ <span id="istToplam">-</span> toplam</small></p></div>
                            <i class="small-box-icon bi bi-collection"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-secondary">
                            <div class="inner"><h3 id="istBos">-</h3><p>Cihazsız Grup</p></div>
                            <i class="small-box-icon bi bi-inbox"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-success">
                            <div class="inner"><h3 id="istEtiket">-</h3><p>Aktif Etiket</p></div>
                            <i class="small-box-icon bi bi-tags"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-info">
                            <div class="inner"><h3 id="istEtiketliCihaz">-</h3><p>Etiketli Cihaz</p></div>
                            <i class="small-box-icon bi bi-pc-display"></i>
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
                                    <input type="text" class="form-control" id="filtreAra" placeholder="Ad / açıklama">
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="filtreDurum">Durum</label>
                                    <select class="form-select select2-basic" id="filtreDurum">
                                        <option value="">Tümü</option>
                                        <option value="1">Aktif</option>
                                        <option value="0">Pasif</option>
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

                <!-- Liste: Gruplar / Etiketler -->
                <div class="card">
                    <div class="card-header d-flex align-items-center flex-wrap gap-2">
                        <ul class="nav nav-tabs card-header-tabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="sekmeGrup" data-bs-toggle="tab" data-bs-target="#panelGrup" type="button" role="tab" aria-controls="panelGrup" aria-selected="true">
                                    <i class="bi bi-collection me-1"></i>Gruplar
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="sekmeEtiket" data-bs-toggle="tab" data-bs-target="#panelEtiket" type="button" role="tab" aria-controls="panelEtiket" aria-selected="false">
                                    <i class="bi bi-tags me-1"></i>Etiketler
                                </button>
                            </li>
                        </ul>
                        <?php if (!empty($yetki['can_add'])): ?>
                        <button type="button" class="btn btn-success btn-sm ms-auto" id="yeniKayit">
                            <i class="bi bi-plus-lg me-1"></i><span>Yeni Grup</span>
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body tab-content">
                        <div class="tab-pane fade show active" id="panelGrup" role="tabpanel" aria-labelledby="sekmeGrup">
                            <table id="grupTablo" class="table table-striped table-hover align-middle w-100"
                                   data-duzenle="<?= !empty($yetki['can_edit']) ? 1 : 0 ?>" data-sil="<?= !empty($yetki['can_delete']) ? 1 : 0 ?>">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Grup Adı</th>
                                        <th>Açıklama</th>
                                        <th class="text-center">Cihaz</th>
                                        <th class="text-center">Aktif Kod</th>
                                        <th class="text-center">Aktif</th>
                                        <th>Son İşlem</th>
                                        <th class="text-end">İşlem</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                        <div class="tab-pane fade" id="panelEtiket" role="tabpanel" aria-labelledby="sekmeEtiket">
                            <table id="etiketTablo" class="table table-striped table-hover align-middle w-100">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Etiket</th>
                                        <th>Açıklama</th>
                                        <th class="text-center">Cihaz</th>
                                        <th class="text-center">Aktif</th>
                                        <th>Son İşlem</th>
                                        <th class="text-end">İşlem</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Ekle / düzenle -->
<div class="modal fade" id="grupModal" tabindex="-1" aria-labelledby="grupModalBaslik" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="grupModalBaslik"><i class="bi bi-collection me-1"></i><span>Yeni Grup</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <form id="grupForm">
                    <input type="hidden" name="id" id="grupId" value="0">
                    <div class="mb-3">
                        <label class="form-label" for="grupAd">Grup adı</label>
                        <input type="text" class="form-control" id="grupAd" name="ad" maxlength="100" required>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="grupAciklama">Açıklama</label>
                        <textarea class="form-control" id="grupAciklama" name="aciklama" rows="3" maxlength="500"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                <button type="button" class="btn btn-success" id="grupKaydet"><i class="bi bi-check-lg me-1"></i>Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- Etiket ekle / düzenle -->
<div class="modal fade" id="etiketModal" tabindex="-1" aria-labelledby="etiketModalBaslik" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="etiketModalBaslik"><i class="bi bi-tag me-1"></i><span>Yeni Etiket</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <form id="etiketForm">
                    <input type="hidden" name="id" id="etiketId" value="0">
                    <div class="row g-2 mb-3">
                        <div class="col">
                            <label class="form-label" for="etiketAd">Etiket adı</label>
                            <input type="text" class="form-control" id="etiketAd" name="ad" maxlength="50" required>
                        </div>
                        <div class="col-auto">
                            <label class="form-label" for="etiketRenk">Renk</label>
                            <input type="color" class="form-control form-control-color" id="etiketRenk" name="renk" value="#6c757d" title="Renk seçin">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="etiketAciklama">Açıklama</label>
                        <textarea class="form-control" id="etiketAciklama" name="aciklama" rows="2" maxlength="250"></textarea>
                    </div>
                    <div class="small text-muted">Önizleme: <span id="etiketOnizleme"></span></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                <button type="button" class="btn btn-success" id="etiketKaydet"><i class="bi bi-check-lg me-1"></i>Kaydet</button>
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
<script src="/admin/assets/js/uzak-cihaz-gruplari.js?v=<?= @filemtime(__DIR__ . '/../assets/js/uzak-cihaz-gruplari.js') ?>"></script>
</body>
</html>
