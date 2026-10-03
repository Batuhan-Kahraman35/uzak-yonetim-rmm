<?php
/**
 * Uzak Yönetim - Cihazlar (server-side DataTables)
 * Hareket tablosu gibi büyüyebileceği için server-side; InfoBox sayımları ayrı action (istatistik).
 * Etiketler sayfadaki cihazlar için ayrı sorguyla eklenir; toplu etiket ekle/kaldır (etiket_toplu).
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/UzakYonetim.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();
$udb   = UzakDb::al();
$yetki = uzakSayfaYetkisi($user);

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

                // Sıralama: kolon index → kolon adı (whitelist); 0 = seçim, 3 = etiketler (sıralanmaz)
                $siralanabilir = [
                    1  => 'c.Cihazlar_BilgisayarAdi',
                    2  => 'g.CihazGruplari_Ad',
                    4  => 'c.Cihazlar_SonGorulme',
                    5  => 'c.Cihazlar_IsletimSistemi',
                    6  => 'c.Cihazlar_AktifKullanici',
                    7  => 'c.Cihazlar_IcIp',
                    8  => 'c.Cihazlar_DisIp',
                    9  => 'c.Cihazlar_AjanSurum',
                    10 => 'c.Cihazlar_SonGorulme',
                ];
                $siraKolon = $siralanabilir[(int) ($_POST['order'][0]['column'] ?? 10)] ?? 'c.Cihazlar_SonGorulme';
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

                // Etiket filtresi: "herhangi biri" EXISTS, "hepsi" eşleşen etiket sayısı = seçilen sayı
                $etiketIdler = idListesi($_POST['etiketler'] ?? [], 50);
                if ($etiketIdler) {
                    $etiketAlt = 'FROM dbo.CihazEtiketleri ce WHERE ce.CihazEtiketleri_Cihazlar_id = c.Cihazlar_id
                                  AND ce.CihazEtiketleri_Etiketler_id IN (' . yerTutucu($etiketIdler) . ')';
                    if (($_POST['etiketMod'] ?? '') === 'hepsi') {
                        $kosul[] = "(SELECT COUNT(*) $etiketAlt) = " . count($etiketIdler);
                    } else {
                        $kosul[] = "EXISTS (SELECT 1 $etiketAlt)";
                    }
                    array_push($param, ...$etiketIdler);
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

                    // Etiketler yalnız bu sayfadaki cihazlar için ayrı sorguyla eklenir
                    $etiketler = cihazEtiketleri($udb, array_column($veri, 'Cihazlar_id'));
                    foreach ($veri as &$satir) {
                        $satir['Etiketler'] = $etiketler[(int) $satir['Cihazlar_id']] ?? [];
                    }
                    unset($satir);
                }

                // DataTables kendi formatını bekler (basarili/mesaj yerine draw/data)
                jsonCevap([
                    'draw'            => $draw,
                    'recordsTotal'    => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered,
                    'data'            => $veri,
                ]);

            case 'etiket_toplu':
                if (empty($yetki['can_edit'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Düzenleme yetkiniz yok.'], 403);
                }
                $islem      = ($_POST['islem'] ?? '') === 'kaldir' ? 'kaldir' : 'ekle';
                $cihazIdler = idListesi($_POST['cihazlar'] ?? [], 500);
                $etiketler  = $islem === 'ekle'
                    ? aktifEtiketIdleri($udb, $_POST['etiketler'] ?? [])
                    : idListesi($_POST['etiketler'] ?? [], 100);
                if (!$cihazIdler) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'En az bir cihaz seçin.'], 422);
                }
                if (!$etiketler) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'En az bir etiket seçin.'], 422);
                }

                $adet = $islem === 'ekle'
                    ? cihazEtiketEkle($udb, $cihazIdler, $etiketler, (int) $user['kullanici_id'])
                    : cihazEtiketKaldir($udb, $cihazIdler, $etiketler);
                logYaz('cihaz_etiket_toplu', ['islem' => $islem, 'cihazlar' => $cihazIdler, 'etiketler' => $etiketler, 'adet' => $adet]);
                jsonCevap([
                    'basarili' => true,
                    'mesaj'    => $islem === 'ekle'
                        ? "{$adet} etiket ataması eklendi (" . count($cihazIdler) . ' cihaz).'
                        : "{$adet} etiket ataması kaldırıldı (" . count($cihazIdler) . ' cihaz).',
                ]);

            default:
                jsonCevap(['basarili' => false, 'mesaj' => 'Geçersiz işlem.'], 400);
        }
    } catch (Throwable $h) {
        error_log('uzak-cihazlar.php: ' . $h->getMessage());
        jsonCevap(['basarili' => false, 'mesaj' => 'İşlem sırasında bir hata oluştu.'], 500);
    }
}

$gruplar       = $udb->hepsi('SELECT CihazGruplari_id, CihazGruplari_Ad FROM dbo.CihazGruplari WHERE Durum = 1 ORDER BY CihazGruplari_Ad');
$etiketListesi = $udb->hepsi('SELECT Etiketler_id, Etiketler_Ad, Etiketler_Renk FROM dbo.Etiketler WHERE Durum = 1 ORDER BY Etiketler_Ad');
$etiketOption  = function (array $e): string {
    return '<option value="' . (int) $e['Etiketler_id'] . '" data-renk="' . htmlspecialchars($e['Etiketler_Renk']) . '">'
         . htmlspecialchars($e['Etiketler_Ad']) . '</option>';
};

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
                                <div class="col-12 col-md-7">
                                    <label class="form-label" for="filtreEtiket">Etiket</label>
                                    <select class="form-select" id="filtreEtiket" multiple data-placeholder="Etiket seçin">
                                        <?php foreach ($etiketListesi as $e) echo $etiketOption($e); ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label" for="filtreEtiketMod">Etiket eşleşmesi</label>
                                    <select class="form-select select2-basic" id="filtreEtiketMod">
                                        <option value="herhangi">Herhangi biri</option>
                                        <option value="hepsi">Hepsi</option>
                                    </select>
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
                    <?php if (!empty($yetki['can_edit'])): ?>
                    <div class="card-header d-flex align-items-center flex-wrap gap-2">
                        <span class="text-muted small"><strong id="seciliAdet">0</strong> cihaz seçili</span>
                        <button type="button" class="btn btn-link btn-sm p-0 d-none" id="secimTemizle">Seçimi temizle</button>
                        <button type="button" class="btn btn-primary btn-sm ms-auto" id="topluEtiket" disabled>
                            <i class="bi bi-tags me-1"></i>Etiket İşlemleri
                        </button>
                    </div>
                    <?php endif; ?>
                    <div class="card-body">
                        <table id="cihazTablo" class="table table-striped table-hover align-middle w-100" data-duzenle="<?= !empty($yetki['can_edit']) ? 1 : 0 ?>">
                            <thead>
                                <tr>
                                    <th class="text-center">
                                        <div class="form-check form-switch d-flex justify-content-center">
                                            <input class="form-check-input" type="checkbox" role="switch" id="tumunuSec" title="Sayfadakilerin tümünü seç">
                                            <label class="form-check-label" for="tumunuSec"></label>
                                        </div>
                                    </th>
                                    <th>Bilgisayar</th>
                                    <th>Grup</th>
                                    <th>Etiketler</th>
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

<?php if (!empty($yetki['can_edit'])): ?>
<!-- Toplu etiket -->
<div class="modal fade" id="topluEtiketModal" tabindex="-1" aria-labelledby="topluEtiketBaslik" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="topluEtiketBaslik"><i class="bi bi-tags me-1"></i>Etiket İşlemleri</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3"><strong id="topluAdet">0</strong> seçili cihaz için:</p>
                <div class="btn-group w-100 mb-3" role="group" aria-label="İşlem">
                    <input type="radio" class="btn-check" name="topluIslem" id="topluEkle" value="ekle" checked>
                    <label class="btn btn-outline-success" for="topluEkle"><i class="bi bi-plus-lg me-1"></i>Etiket ekle</label>
                    <input type="radio" class="btn-check" name="topluIslem" id="topluKaldir" value="kaldir">
                    <label class="btn btn-outline-danger" for="topluKaldir"><i class="bi bi-dash-lg me-1"></i>Etiket kaldır</label>
                </div>
                <label class="form-label" for="topluEtiketler">Etiketler</label>
                <select class="form-select" id="topluEtiketler" multiple data-placeholder="Etiket seçin">
                    <?php foreach ($etiketListesi as $e) echo $etiketOption($e); ?>
                </select>
                <?php if (!$etiketListesi): ?>
                <div class="form-text">Henüz etiket yok. <a href="/admin/uzak-cihaz-gruplari">Gruplar ve Etiketler → Etiketler</a> sekmesinden ekleyin.</div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                <button type="button" class="btn btn-primary" id="topluUygula"><i class="bi bi-check-lg me-1"></i>Uygula</button>
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
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>
<script src="/admin/assets/js/uzak-yonetim.js?v=<?= @filemtime(__DIR__ . '/../assets/js/uzak-yonetim.js') ?>"></script>
<script src="/admin/assets/js/uzak-cihazlar.js?v=<?= @filemtime(__DIR__ . '/../assets/js/uzak-cihazlar.js') ?>"></script>
</body>
</html>
