<?php
/**
 * Uzak Yönetim - Ayarlar
 * UzakYonetimAyarlari değerlerini düzenler. Anahtarlar koddan gelir; burada yalnız değer değişir.
 * Şifreli alanların mevcut değeri EKRANA BASILMAZ; yalnız "tanımlı / tanımsız" gösterilir ve yeni değer girilebilir.
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
        if (($_POST['action'] ?? '') === 'kaydet') {
            if (empty($yetki['can_edit'])) {
                jsonCevap(['basarili' => false, 'mesaj' => 'Düzenleme yetkiniz yok.'], 403);
            }
            $anahtar = (string) ($_POST['anahtar'] ?? '');
            $satir = $udb->tek(
                'SELECT UzakYonetimAyarlari_id, UzakYonetimAyarlari_SifreliMi FROM dbo.UzakYonetimAyarlari WHERE UzakYonetimAyarlari_Anahtar = ? AND Durum = 1',
                [$anahtar]
            );
            if (!$satir) {
                jsonCevap(['basarili' => false, 'mesaj' => 'Ayar bulunamadı.'], 404);
            }
            $sifreli = (bool) $satir['UzakYonetimAyarlari_SifreliMi'];
            $girilen = (string) ($_POST['deger'] ?? '');

            // Şifreli alanda boş gönderim "değiştirme" demektir (mevcut korunur); temizlemek için ayrı kutu
            if ($sifreli && $girilen === '' && ($_POST['temizle'] ?? '') !== '1') {
                jsonCevap(['basarili' => false, 'mesaj' => 'Değişiklik yapılmadı.']);
            }

            if ($sifreli && ($_POST['temizle'] ?? '') === '1') {
                $yeniDeger = null;
            } elseif ($sifreli) {
                $yeniDeger = Sifreleme::sifrele($girilen);
            } else {
                $yeniDeger = ($girilen === '') ? null : mb_substr($girilen, 0, 4000);
            }

            $udb->calistir(
                'UPDATE dbo.UzakYonetimAyarlari SET UzakYonetimAyarlari_Deger = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                 WHERE UzakYonetimAyarlari_id = ?',
                [$yeniDeger, $user['kullanici_id'], $satir['UzakYonetimAyarlari_id']]
            );
            // Değer loglanmaz (şifreli olabilir); yalnız anahtar ve dolu/boş bilgisi
            logYaz('ayar_guncelle', ['anahtar' => $anahtar, 'sifreli' => $sifreli, 'dolu' => $yeniDeger !== null]);
            jsonCevap(['basarili' => true, 'mesaj' => 'Ayar kaydedildi.']);
        }
        jsonCevap(['basarili' => false, 'mesaj' => 'Geçersiz işlem.'], 400);
    } catch (Throwable $h) {
        error_log('uzak-ayarlar.php: ' . $h->getMessage());
        jsonCevap(['basarili' => false, 'mesaj' => 'İşlem sırasında bir hata oluştu.'], 500);
    }
}

$ayarlar = $udb->hepsi(
    'SELECT UzakYonetimAyarlari_Anahtar, UzakYonetimAyarlari_Deger, UzakYonetimAyarlari_SifreliMi, UzakYonetimAyarlari_Aciklama,
            CONVERT(VARCHAR(16), GuncellemeTarihi, 120) AS Guncelleme
     FROM dbo.UzakYonetimAyarlari WHERE Durum = 1 ORDER BY UzakYonetimAyarlari_Anahtar'
);

$pageInfo = $db->fetchOne(
    "SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi AS menu_adi
     FROM Menu_Sayfalar s LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
     WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1",
    ['%' . basename($_SERVER['PHP_SELF'])]
);
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Ayarlar';
$menuAdi   = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';
$duzenle = !empty($yetki['can_edit']);
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
                <div class="card">
                    <div class="card-header"><h3 class="card-title mb-0"><i class="bi bi-sliders me-1"></i>Uzak Yönetim Ayarları</h3></div>
                    <div class="card-body table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead><tr><th>Anahtar</th><th>Değer</th><th>Açıklama</th><th>Güncelleme</th><?php if ($duzenle): ?><th class="text-end">İşlem</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($ayarlar as $a):
                                $sifreli = (bool) $a['UzakYonetimAyarlari_SifreliMi'];
                                $dolu = $a['UzakYonetimAyarlari_Deger'] !== null && $a['UzakYonetimAyarlari_Deger'] !== '';
                            ?>
                                <tr>
                                    <td class="font-monospace small"><?= $uyE($a['UzakYonetimAyarlari_Anahtar']) ?>
                                        <?php if ($sifreli): ?><i class="bi bi-lock-fill text-warning ms-1" title="Şifreli saklanır"></i><?php endif; ?></td>
                                    <td>
                                        <?php if ($sifreli): ?>
                                            <?= $dolu ? '<span class="badge text-bg-success">Tanımlı</span>' : '<span class="badge text-bg-secondary">Tanımsız</span>' ?>
                                        <?php else: ?>
                                            <?= $dolu ? '<span class="font-monospace">' . $uyE($a['UzakYonetimAyarlari_Deger']) . '</span>' : '<span class="text-muted">-</span>' ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?= $uyE($a['UzakYonetimAyarlari_Aciklama']) ?></td>
                                    <td class="small text-nowrap"><?= $uyE($a['Guncelleme']) ?: '<span class="text-muted">-</span>' ?></td>
                                    <?php if ($duzenle): ?>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-outline-primary btn-sm ayar-duzenle"
                                                data-anahtar="<?= $uyE($a['UzakYonetimAyarlari_Anahtar']) ?>"
                                                data-sifreli="<?= $sifreli ? 1 : 0 ?>"
                                                data-dolu="<?= $dolu ? 1 : 0 ?>"
                                                data-deger="<?= $sifreli ? '' : $uyE($a['UzakYonetimAyarlari_Deger']) ?>"
                                                data-aciklama="<?= $uyE($a['UzakYonetimAyarlari_Aciklama']) ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<?php if ($duzenle): ?>
<div class="modal fade" id="ayarModal" tabindex="-1" aria-labelledby="ayarModalBaslik" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ayarModalBaslik"><i class="bi bi-pencil me-1"></i>Ayar</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <form id="ayarForm">
                    <input type="hidden" name="anahtar" id="ayarAnahtar">
                    <input type="hidden" name="temizle" id="ayarTemizle" value="">
                    <p class="small text-muted mb-2" id="ayarAciklama"></p>
                    <div class="mb-2" id="ayarSifreliBilgi" style="display:none">
                        <span class="small">Mevcut: </span><span id="ayarDurum"></span>
                    </div>
                    <label class="form-label" for="ayarDeger" id="ayarDegerLabel">Değer</label>
                    <input type="text" class="form-control" id="ayarDeger" name="deger" autocomplete="off">
                    <div class="form-text" id="ayarIpucu"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-danger me-auto d-none" id="ayarTemizleBtn"><i class="bi bi-eraser me-1"></i>Değeri Temizle</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                <button type="button" class="btn btn-primary" id="ayarKaydet"><i class="bi bi-check-lg me-1"></i>Kaydet</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>
<script src="/admin/assets/js/uzak-yonetim.js?v=<?= @filemtime(__DIR__ . '/../assets/js/uzak-yonetim.js') ?>"></script>
<script src="/admin/assets/js/uzak-ayarlar.js?v=<?= @filemtime(__DIR__ . '/../assets/js/uzak-ayarlar.js') ?>"></script>
</body>
</html>
