<?php
/**
 * Uzak Yönetim - Cihazlar (server-side DataTables)
 * Hareket tablosu gibi büyüyebileceği için server-side; InfoBox sayımları ayrı action (istatistik).
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/UzakYonetim.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();
$udb  = UzakDb::al();
uzakSayfaYetkisi($user);

$cevrimdisiDk = max(1, (int) ayar('cihaz_cevrimdisi_dk', 5));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfDogrula();

    try {
        switch ($_POST['action'] ?? '') {
            case 'istatistik':
                $s = $udb->tek(
                    'SELECT COUNT(*) AS Toplam,
                            SUM(CASE WHEN Cihazlar_SonGorulme >= DATEADD(MINUTE, -?, GETDATE()) THEN 1 ELSE 0 END) AS Cevrimici,
                            SUM(CASE WHEN Cihazlar_PilotMu = 1 THEN 1 ELSE 0 END) AS Pilot
                     FROM dbo.Cihazlar WHERE Durum = 1',
                    [$cevrimdisiDk]
                );
                jsonCevap(['basarili' => true, 'veri' => [
                    'toplam'     => (int) $s['Toplam'],
                    'cevrimici'  => (int) $s['Cevrimici'],
                    'cevrimdisi' => (int) $s['Toplam'] - (int) $s['Cevrimici'],
                    'pilot'      => (int) $s['Pilot'],
                ]]);

            case 'liste':
                $draw   = (int) ($_POST['draw'] ?? 1);
                $start  = max(0, (int) ($_POST['start'] ?? 0));
                $length = (int) ($_POST['length'] ?? 25);
                $length = ($length < 1 || $length > 500) ? 25 : $length;

                // Sıralama: kolon index → kolon adı (whitelist)
                $siralanabilir = [
                    0 => 'c.Cihazlar_BilgisayarAdi',
                    1 => 'g.CihazGruplari_Ad',
                    2 => 'c.Cihazlar_SonGorulme',
                    3 => 'c.Cihazlar_IsletimSistemi',
                    4 => 'c.Cihazlar_AktifKullanici',
                    5 => 'c.Cihazlar_IcIp',
                    6 => 'c.Cihazlar_DisIp',
                    7 => 'c.Cihazlar_AjanSurum',
                    8 => 'c.Cihazlar_SonGorulme',
                ];
                $siraKolon = $siralanabilir[(int) ($_POST['order'][0]['column'] ?? 8)] ?? 'c.Cihazlar_SonGorulme';
                $siraYon   = strtolower((string) ($_POST['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

                // Filtreler (grup filtresi ID ile — sayımlar JOIN'siz kalır)
                $kosul = ['c.Durum = 1'];
                $param = [];

                $grupId = (int) ($_POST['grup'] ?? 0);
                if ($grupId > 0) {
                    $kosul[] = 'c.Cihazlar_CihazGruplari_id = ?';
                    $param[] = $grupId;
                }

                $baglanti = $_POST['baglanti'] ?? '';
                if ($baglanti === 'cevrimici') {
                    $kosul[] = 'c.Cihazlar_SonGorulme >= DATEADD(MINUTE, -?, GETDATE())';
                    $param[] = $cevrimdisiDk;
                } elseif ($baglanti === 'cevrimdisi') {
                    $kosul[] = '(c.Cihazlar_SonGorulme IS NULL OR c.Cihazlar_SonGorulme < DATEADD(MINUTE, -?, GETDATE()))';
                    $param[] = $cevrimdisiDk;
                }

                if (($_POST['pilot'] ?? '') === '1') {
                    $kosul[] = 'c.Cihazlar_PilotMu = 1';
                }

                $ara = trim((string) ($_POST['ara'] ?? ''));
                if ($ara !== '') {
                    $kosul[] = '(c.Cihazlar_BilgisayarAdi LIKE ? OR c.Cihazlar_AktifKullanici LIKE ? OR c.Cihazlar_IcIp LIKE ?
                                 OR c.Cihazlar_DisIp LIKE ? OR c.Cihazlar_Aciklama LIKE ? OR c.Cihazlar_RustDeskId LIKE ?)';
                    array_push($param, ...array_fill(0, 6, '%' . $ara . '%'));
                }

                $where = implode(' AND ', $kosul);

                $recordsTotal    = (int) $udb->deger('SELECT COUNT(*) FROM dbo.Cihazlar WHERE Durum = 1');
                $recordsFiltered = (int) $udb->deger("SELECT COUNT(*) FROM dbo.Cihazlar c WHERE $where", $param);

                $veri = [];
                if ($recordsFiltered > 0) {
                    // Grup adına göre sıralanıyorsa CTE'de JOIN gerekir; aksi halde CTE tek tablo kalır
                    $cteGrupJoin = str_starts_with($siraKolon, 'g.')
                        ? 'LEFT JOIN dbo.CihazGruplari g ON g.CihazGruplari_id = c.Cihazlar_CihazGruplari_id'
                        : '';

                    $veri = $udb->hepsi("
                        WITH Sayfa AS (
                            SELECT c.Cihazlar_id,
                                   ROW_NUMBER() OVER (ORDER BY $siraKolon $siraYon, c.Cihazlar_id DESC) AS Sira
                            FROM dbo.Cihazlar c
                            $cteGrupJoin
                            WHERE $where
                            ORDER BY Sira
                            OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
                        )
                        SELECT
                            c.Cihazlar_id, c.Cihazlar_BilgisayarAdi, c.Cihazlar_Aciklama,
                            g.CihazGruplari_Ad,
                            c.Cihazlar_IsletimSistemi, c.Cihazlar_OsSurum,
                            c.Cihazlar_AktifKullanici, c.Cihazlar_IcIp, c.Cihazlar_DisIp,
                            c.Cihazlar_AjanSurum, c.Cihazlar_PilotMu, c.Cihazlar_RustDeskId,
                            CONVERT(VARCHAR(19), c.Cihazlar_SonGorulme, 120) AS Cihazlar_SonGorulme,
                            CASE WHEN c.Cihazlar_SonGorulme >= DATEADD(MINUTE, -?, GETDATE()) THEN 1 ELSE 0 END AS CevrimiciMi
                        FROM Sayfa s
                        INNER JOIN dbo.Cihazlar c ON c.Cihazlar_id = s.Cihazlar_id
                        LEFT JOIN dbo.CihazGruplari g ON g.CihazGruplari_id = c.Cihazlar_CihazGruplari_id
                        ORDER BY s.Sira
                    ", array_merge($param, [$cevrimdisiDk]));
                }

                // DataTables kendi formatını bekler (basarili/mesaj yerine draw/data)
                jsonCevap([
                    'draw'            => $draw,
                    'recordsTotal'    => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered,
                    'data'            => $veri,
                ]);

            default:
                jsonCevap(['basarili' => false, 'mesaj' => 'Geçersiz işlem.'], 400);
        }
    } catch (Throwable $h) {
        error_log('uzak-cihazlar.php: ' . $h->getMessage());
        jsonCevap(['basarili' => false, 'mesaj' => 'İşlem sırasında bir hata oluştu.'], 500);
    }
}

$gruplar = $udb->hepsi('SELECT CihazGruplari_id, CihazGruplari_Ad FROM dbo.CihazGruplari WHERE Durum = 1 ORDER BY CihazGruplari_Ad');

$pageInfo = $db->fetchOne(
    "SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi AS menu_adi
     FROM Menu_Sayfalar s
     LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
     WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1",
    ['%' . basename($_SERVER['PHP_SELF'])]
);
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Cihazlar';
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
                            <div class="inner"><h3 id="istToplam">-</h3><p>Toplam Cihaz</p></div>
                            <i class="small-box-icon bi bi-pc-display"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-success">
                            <div class="inner"><h3 id="istCevrimici">-</h3><p>Çevrimiçi</p></div>
                            <i class="small-box-icon bi bi-wifi"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-secondary">
                            <div class="inner"><h3 id="istCevrimdisi">-</h3><p>Çevrimdışı</p></div>
                            <i class="small-box-icon bi bi-wifi-off"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-warning">
                            <div class="inner"><h3 id="istPilot">-</h3><p>Pilot Cihaz</p></div>
                            <i class="small-box-icon bi bi-flag"></i>
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
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="filtreAra">Ara</label>
                                    <input type="text" class="form-control" id="filtreAra" placeholder="Bilgisayar adı, kullanıcı, IP, RustDesk ID">
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label" for="filtreGrup">Grup</label>
                                    <select class="form-select select2-basic" id="filtreGrup">
                                        <option value="">Tümü</option>
                                        <?php foreach ($gruplar as $g): ?>
                                        <option value="<?= (int) $g['CihazGruplari_id'] ?>"><?= htmlspecialchars($g['CihazGruplari_Ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-2">
                                    <label class="form-label" for="filtreBaglanti">Bağlantı</label>
                                    <select class="form-select select2-basic" id="filtreBaglanti">
                                        <option value="">Tümü</option>
                                        <option value="cevrimici">Çevrimiçi</option>
                                        <option value="cevrimdisi">Çevrimdışı</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3 d-flex align-items-end">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="filtrePilot" name="filtrePilot" value="1">
                                        <label class="form-check-label" for="filtrePilot">Yalnız pilot cihazlar</label>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 text-end">
                                <button type="button" class="btn btn-outline-secondary" id="filtreTemizle"><i class="bi bi-x-lg me-1"></i>Temizle</button>
                                <button type="button" class="btn btn-primary" id="filtreUygula"><i class="bi bi-search me-1"></i>Filtrele</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Liste -->
                <div class="card">
                    <div class="card-body">
                        <table id="cihazTablo" class="table table-striped table-hover align-middle w-100">
                            <thead>
                                <tr>
                                    <th>Bilgisayar</th>
                                    <th>Grup</th>
                                    <th>Durum</th>
                                    <th>İşletim Sistemi</th>
                                    <th>Aktif Kullanıcı</th>
                                    <th>İç IP</th>
                                    <th>Dış IP</th>
                                    <th>Ajan</th>
                                    <th>Son Görülme</th>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>
<script src="/admin/assets/js/uzak-yonetim.js?v=<?= @filemtime(__DIR__ . '/../assets/js/uzak-yonetim.js') ?>"></script>
<script src="/admin/assets/js/uzak-cihazlar.js?v=<?= @filemtime(__DIR__ . '/../assets/js/uzak-cihazlar.js') ?>"></script>
</body>
</html>
