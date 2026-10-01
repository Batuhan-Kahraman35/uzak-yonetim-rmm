<?php
/**
 * Uzak Yönetim - Dosya Deposu
 * Script'lerin indirdiği paketler (BGInfo exe/bgi/xml, logo vb.). Dosya diskte ajan/dosyalar/<sha256>,
 * DB'de yalnız bilgisi. Ajan Get-UzakPaket ile paketi SHA256 doğrulamalı indirir.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/UzakYonetim.php';
requireAuth();

$user  = Auth::user();
$db    = Database::getInstance();
$udb   = UzakDb::al();
$yetki = uzakSayfaYetkisi($user);

const DEPO_KLASOR = __DIR__ . '/../../ajan/dosyalar/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfDogrula();

    try {
        switch ($_POST['action'] ?? '') {
            case 'istatistik':
                $s = $udb->tek(
                    'SELECT COUNT(*) AS Dosya, COUNT(DISTINCT UzakDosyalar_Paket) AS Paket, ISNULL(SUM(UzakDosyalar_Boyut), 0) AS Boyut
                     FROM dbo.UzakDosyalar WHERE Durum = 1'
                );
                jsonCevap(['basarili' => true, 'veri' => [
                    'dosya'  => (int) $s['Dosya'],
                    'paket'  => (int) $s['Paket'],
                    'boyut'  => (float) $s['Boyut'],
                ]]);

            case 'liste':
                $kosul = ['Durum = 1'];
                $param = [];
                $paket = trim((string) ($_POST['paket'] ?? ''));
                if ($paket !== '') {
                    $kosul[] = 'UzakDosyalar_Paket = ?';
                    $param[] = $paket;
                }
                $ara = trim((string) ($_POST['ara'] ?? ''));
                if ($ara !== '') {
                    $kosul[] = '(UzakDosyalar_Ad LIKE ? OR UzakDosyalar_Paket LIKE ? OR UzakDosyalar_Aciklama LIKE ?)';
                    array_push($param, ...array_fill(0, 3, '%' . $ara . '%'));
                }
                $veri = $udb->hepsi(
                    "SELECT d.UzakDosyalar_id, d.UzakDosyalar_Paket, d.UzakDosyalar_Ad, d.UzakDosyalar_Sha256, d.UzakDosyalar_Boyut,
                            d.UzakDosyalar_Aciklama, CONVERT(VARCHAR(16), ISNULL(d.GuncellemeTarihi, d.OlusturmaTarihi), 120) AS Guncelleme,
                            u.kullanici_ad + ' ' + u.kullanici_soyad AS Yukleyen
                     FROM dbo.UzakDosyalar d
                     LEFT JOIN dbo.kullanicilar u ON u.kullanici_id = ISNULL(d.GuncelleyenKullanici, d.OlusturanKullanici)
                     WHERE " . implode(' AND ', $kosul) . "
                     ORDER BY d.UzakDosyalar_Paket, d.UzakDosyalar_Ad",
                    $param
                );
                jsonCevap(['data' => $veri]);

            case 'yukle':
                if (empty($yetki['can_add'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Yükleme yetkiniz yok.'], 403);
                }
                $paket = kirp($_POST['paket'] ?? null, 100);
                if ($paket === null || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]{0,99}$/', $paket)) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Geçerli bir paket adı girin (harf/rakam ile başlamalı).'], 422);
                }
                $aciklama = kirp($_POST['aciklama'] ?? null, 500);

                // post_max_size aşılırsa $_FILES boş gelir
                if (empty($_FILES['dosyalar']) || !is_array($_FILES['dosyalar']['name'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Dosya alınamadı (toplam boyut sunucu sınırını aşmış olabilir).'], 422);
                }
                $azamiBayt = max(1, (int) ayar('dosya_azami_mb', 50)) * 1024 * 1024;
                $phpUst    = min(
                    uzakBoyutaCevir(ini_get('upload_max_filesize')),
                    uzakBoyutaCevir(ini_get('post_max_size')) ?: PHP_INT_MAX
                );

                if (!is_dir(DEPO_KLASOR) && !@mkdir(DEPO_KLASOR, 0700, true)) {
                    throw new RuntimeException('Depo klasörü oluşturulamadı.');
                }

                $yuklenen = [];
                $hatalar  = [];
                $sayi = count($_FILES['dosyalar']['name']);
                for ($i = 0; $i < $sayi; $i++) {
                    $ad   = $_FILES['dosyalar']['name'][$i];
                    $hata = (int) $_FILES['dosyalar']['error'][$i];
                    $tmp  = $_FILES['dosyalar']['tmp_name'][$i];
                    $boyut = (int) $_FILES['dosyalar']['size'][$i];

                    if ($hata === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    if ($hata === UPLOAD_ERR_INI_SIZE || $hata === UPLOAD_ERR_FORM_SIZE) {
                        $hatalar[] = "$ad: PHP yükleme sınırını aştı (en çok " . round($phpUst / 1048576) . ' MB).';
                        continue;
                    }
                    if ($hata !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                        $hatalar[] = "$ad: yüklenemedi (hata $hata).";
                        continue;
                    }
                    // Cihaza bu adla yazılacağı için yol/kontrol karakteri olmamalı
                    $ad = kirp($ad, 200);
                    if ($ad === null || !preg_match('/^[^\\\\\/:*?"<>|\x00-\x1f]+$/', $ad)) {
                        $hatalar[] = "$ad: geçersiz dosya adı.";
                        continue;
                    }
                    if ($boyut > $azamiBayt) {
                        $hatalar[] = "$ad: boyut sınırı aşıldı (en çok " . round($azamiBayt / 1048576) . ' MB).';
                        continue;
                    }

                    $sha = hash_file('sha256', $tmp);
                    $hedef = DEPO_KLASOR . $sha;
                    // Aynı içerik zaten varsa tekrar yazılmaz (sha = dosya adı)
                    if (!is_file($hedef) && !move_uploaded_file($tmp, $hedef)) {
                        $hatalar[] = "$ad: diske yazılamadı.";
                        continue;
                    }

                    // (Paket, Ad) tekil: varsa güncelle, yoksa ekle
                    $mevcut = $udb->tek(
                        'SELECT UzakDosyalar_id FROM dbo.UzakDosyalar WHERE UzakDosyalar_Paket = ? AND UzakDosyalar_Ad = ? AND Durum = 1',
                        [$paket, $ad]
                    );
                    if ($mevcut) {
                        $udb->calistir(
                            'UPDATE dbo.UzakDosyalar SET UzakDosyalar_Sha256 = ?, UzakDosyalar_Boyut = ?, UzakDosyalar_Aciklama = ?,
                                    GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                             WHERE UzakDosyalar_id = ?',
                            [$sha, $boyut, $aciklama, $user['kullanici_id'], $mevcut['UzakDosyalar_id']]
                        );
                    } else {
                        $udb->calistir(
                            'INSERT INTO dbo.UzakDosyalar (UzakDosyalar_Paket, UzakDosyalar_Ad, UzakDosyalar_Sha256, UzakDosyalar_Boyut,
                                    UzakDosyalar_Aciklama, OlusturanKullanici)
                             VALUES (?, ?, ?, ?, ?, ?)',
                            [$paket, $ad, $sha, $boyut, $aciklama, $user['kullanici_id']]
                        );
                    }
                    $yuklenen[] = $ad;
                }

                depoTemizle($udb);
                logYaz('dosya_yukle', ['paket' => $paket, 'yuklenen' => $yuklenen, 'hata' => count($hatalar)]);

                if (!$yuklenen && $hatalar) {
                    jsonCevap(['basarili' => false, 'mesaj' => implode(' ', $hatalar)], 422);
                }
                $mesaj = count($yuklenen) . " dosya '$paket' paketine yüklendi.";
                if ($hatalar) {
                    $mesaj .= ' Atlanan: ' . implode(' ', $hatalar);
                }
                jsonCevap(['basarili' => true, 'mesaj' => $mesaj, 'uyari' => (bool) $hatalar]);

            case 'sil':
                if (empty($yetki['can_delete'])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Silme yetkiniz yok.'], 403);
                }
                $id = (int) ($_POST['id'] ?? 0);
                $adet = $udb->calistir(
                    'UPDATE dbo.UzakDosyalar SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE UzakDosyalar_id = ? AND Durum = 1',
                    [$user['kullanici_id'], $id]
                );
                if ($adet === 0) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Dosya bulunamadı.'], 404);
                }
                depoTemizle($udb);
                logYaz('dosya_sil', ['id' => $id]);
                jsonCevap(['basarili' => true, 'mesaj' => 'Dosya silindi.']);

            default:
                jsonCevap(['basarili' => false, 'mesaj' => 'Geçersiz işlem.'], 400);
        }
    } catch (Throwable $h) {
        error_log('uzak-dosyalar.php: ' . $h->getMessage());
        jsonCevap(['basarili' => false, 'mesaj' => 'İşlem sırasında bir hata oluştu.'], 500);
    }
}

/** "25M" / "512K" / "1G" → bayt. */
function uzakBoyutaCevir($deger): int
{
    $deger = trim((string) $deger);
    if ($deger === '') {
        return 0;
    }
    $sayi = (int) $deger;
    switch (strtolower(substr($deger, -1))) {
        case 'g': $sayi *= 1024;
        case 'm': $sayi *= 1024;
        case 'k': $sayi *= 1024;
    }
    return $sayi;
}

/** Hiçbir aktif kaydın referans almadığı fiziksel dosyaları siler. */
function depoTemizle(UzakDb $udb): void
{
    if (!is_dir(DEPO_KLASOR)) {
        return;
    }
    $kullanilan = array_flip(array_column(
        $udb->hepsi('SELECT DISTINCT UzakDosyalar_Sha256 FROM dbo.UzakDosyalar WHERE Durum = 1'),
        'UzakDosyalar_Sha256'
    ));
    foreach (glob(DEPO_KLASOR . '*') as $yol) {
        $ad = basename($yol);
        if (preg_match('/^[0-9a-f]{64}$/', $ad) && !isset($kullanilan[$ad])) {
            @unlink($yol);
        }
    }
}

$paketler = $udb->hepsi('SELECT DISTINCT UzakDosyalar_Paket FROM dbo.UzakDosyalar WHERE Durum = 1 ORDER BY 1');

$pageInfo = $db->fetchOne(
    "SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi AS menu_adi
     FROM Menu_Sayfalar s LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
     WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1",
    ['%' . basename($_SERVER['PHP_SELF'])]
);
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Dosya Deposu';
$menuAdi   = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';
$azamiMb      = max(1, (int) ayar('dosya_azami_mb', 50));
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

                <div class="row mb-3">
                    <div class="col-12 col-sm-4">
                        <div class="small-box text-bg-primary">
                            <div class="inner"><h3 id="istDosya">-</h3><p>Dosya</p></div>
                            <i class="small-box-icon bi bi-file-earmark-binary"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-4">
                        <div class="small-box text-bg-success">
                            <div class="inner"><h3 id="istPaket">-</h3><p>Paket</p></div>
                            <i class="small-box-icon bi bi-box-seam"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-4">
                        <div class="small-box text-bg-info">
                            <div class="inner"><h3 id="istBoyut">-</h3><p>Toplam Boyut</p></div>
                            <i class="small-box-icon bi bi-hdd-stack"></i>
                        </div>
                    </div>
                </div>

                <div class="card filter-box mb-3">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="filtreAra">Ara</label>
                                <input type="text" class="form-control" id="filtreAra" placeholder="Dosya adı, paket, açıklama">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="filtrePaket">Paket</label>
                                <select class="form-select select2-basic" id="filtrePaket">
                                    <option value="">Tümü</option>
                                    <?php foreach ($paketler as $p): ?>
                                    <option value="<?= $uyE($p['UzakDosyalar_Paket']) ?>"><?= $uyE($p['UzakDosyalar_Paket']) ?></option>
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

                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <h3 class="card-title mb-0">Dosyalar</h3>
                        <?php if (!empty($yetki['can_add'])): ?>
                        <button type="button" class="btn btn-success btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#yukleModal"><i class="bi bi-upload me-1"></i>Dosya Yükle</button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <table id="dosyaTablo" class="table table-striped table-hover align-middle w-100" data-sil="<?= !empty($yetki['can_delete']) ? 1 : 0 ?>">
                            <thead>
                                <tr>
                                    <th>Paket</th>
                                    <th>Dosya</th>
                                    <th>Boyut</th>
                                    <th>SHA256</th>
                                    <th>Yükleme</th>
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

<?php if (!empty($yetki['can_add'])): ?>
<div class="modal fade" id="yukleModal" tabindex="-1" aria-labelledby="yukleModalBaslik" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="yukleModalBaslik"><i class="bi bi-upload me-1"></i>Dosya Yükle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <form id="yukleForm">
                    <div class="mb-3">
                        <label class="form-label" for="yuklePaket">Paket</label>
                        <input type="text" class="form-control" id="yuklePaket" name="paket" list="paketListe" maxlength="100" placeholder="Ör. BGInfo" required>
                        <datalist id="paketListe">
                            <?php foreach ($paketler as $p): ?><option value="<?= $uyE($p['UzakDosyalar_Paket']) ?>"></option><?php endforeach; ?>
                        </datalist>
                        <div class="form-text">Script <code>Get-UzakPaket -Paket '...'</code> ile bu paketi indirir. Aynı paket+ad tekrar yüklenirse güncellenir.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="yukleDosyalar">Dosyalar</label>
                        <input type="file" class="form-control" id="yukleDosyalar" name="dosyalar[]" multiple required>
                        <div class="form-text">Dosya başına en çok <?= $azamiMb ?> MB (sunucu PHP sınırı daha düşükse o geçerli).</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="yukleAciklama">Açıklama</label>
                        <input type="text" class="form-control" id="yukleAciklama" name="aciklama" maxlength="500">
                    </div>
                </form>
                <div class="progress mt-3 d-none" id="yukleBar"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:100%">Yükleniyor...</div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                <button type="button" class="btn btn-success" id="yukleKaydet"><i class="bi bi-upload me-1"></i>Yükle</button>
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
<script src="/admin/assets/js/uzak-dosyalar.js?v=<?= @filemtime(__DIR__ . '/../assets/js/uzak-dosyalar.js') ?>"></script>
</body>
</html>
