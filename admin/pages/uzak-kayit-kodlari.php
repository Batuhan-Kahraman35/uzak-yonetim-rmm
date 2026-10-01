<?php
/**
 * Uzak Yönetim - Kayıt Kodları
 * Ajan kurulumunda kullanılan, süreli ve kullanım limitli kodlar.
 * Doğrulama SHA256 özetiyle yapılır; kurulum dosyasını tablodan indirebilmek için kod ayrıca
 * şifreli saklanır (KayitKodlari_KodSifreli, Sifreleme / config/sifreleme.key).
 * Tanım tablosu niteliğinde (az satır) → client-side DataTables.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/UzakYonetim.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();
$udb  = UzakDb::al();
uzakSayfaYetkisi($user);

const KOD_ALFABE = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // karışan karakterler (0/O, 1/I) yok

function kodUret(): string
{
    $kod = '';
    for ($i = 0; $i < 16; $i++) {
        $kod .= KOD_ALFABE[random_int(0, strlen(KOD_ALFABE) - 1)];
    }
    return implode('-', str_split($kod, 4));
}

function kurulumAdresi(): string
{
    return 'https://' . strtolower((string) ($_SERVER['SERVER_NAME'] ?? '')) . '/api/ajan/kur.php';
}

/**
 * Tek PC kurulum komutu (yönetici PowerShell'de çalıştırılır).
 * Betik önce diske indirilip -File ile çalıştırılır. Eski "irm | scriptblock" (bellekte çalıştırma)
 * kalıbı Microsoft Defender ML'inde Trojan:Win32/Commando.A!ml olarak yanlış işaretleniyordu.
 */
function kurulumKomutu(string $kod): string
{
    $adres = kurulumAdresi();
    return "[Net.ServicePointManager]::SecurityProtocol = 'Tls12'\n"
         . "\$k = Join-Path \$env:TEMP 'uy-kur.ps1'\n"
         . "(New-Object Net.WebClient).DownloadFile('$adres', \$k)\n"
         . "powershell -NoProfile -ExecutionPolicy Bypass -File \$k -KayitKodu '$kod'\n"
         . "Remove-Item \$k -Force";
}

/**
 * Çift tıklamayla çalışan kurulum dosyası (.cmd, kod gömülü).
 * .ps1 çift tıklamada Not Defteri'nde açılır / execution policy engeller; .cmd kendini UAC ile yükseltir.
 * Betik önce diske indirilir, sonra -File ile çalıştırılır: "irm | scriptblock" (bellekte çalıştırma)
 * kalıbı Microsoft Defender ML'inde Trojan:Win32/Commando.A!ml olarak yanlış işaretleniyordu.
 * ASCII + CRLF: cmd.exe başka kodlamada Türkçe karakterleri bozar.
 */
function kurulumDosyasi(string $kod): string
{
    $adres = kurulumAdresi();
    $satirlar = [
        '@echo off',
        'setlocal',
        'title Uzak Yonetim - Kurulum',
        'set "SONUC=5"',
        ':: Yonetici degilse UAC ile kendini yeniden baslatir',
        'net session >nul 2>&1',
        'if errorlevel 1 (',
        '    powershell -NoProfile -Command "Start-Process -FilePath \'%~f0\' -Verb RunAs"',
        '    exit /b',
        ')',
        'echo.',
        'echo  Uzak Yonetim - Kurulum',
        'echo  ----------------------',
        'echo  Bilgisayar : %COMPUTERNAME%',
        'echo  Sunucu     : ' . parse_url($adres, PHP_URL_HOST),
        'echo.',
        'echo   [1] Yalniz ajani kur                   ^(varsayilan^)',
        'echo   [2] Ajan + ilk kurulum islemleri',
        'echo       ^(BGInfo, AnyDesk, bilgisayar adi; adi "ornek" olanlarda tam^)',
        'echo   [0] Iptal',
        'echo.',
        'set "IKFLAG="',
        'choice /c 120 /t 30 /d 1 /m " Seciminiz (30 sn icinde 1)"',
        'if errorlevel 3 goto iptal',
        'if errorlevel 2 set "IKFLAG=-IlkKurulum"',
        'echo.',
        ':: Kurulum betigi once diske indirilir, sonra -File ile calistirilir',
        'set "UYPS=%TEMP%\uy-kur-%RANDOM%%RANDOM%.ps1"',
        'powershell -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference=\'Stop\'; '
            . '[Net.ServicePointManager]::SecurityProtocol=\'Tls12\'; '
            . '(New-Object Net.WebClient).DownloadFile(\'' . $adres . '\',\'%UYPS%\')"',
        'if not exist "%UYPS%" (',
        '    echo  KURULUM BASARISIZ - kurulum betigi indirilemedi ^(ag / sunucu^)',
        '    goto son',
        ')',
        'powershell -NoProfile -ExecutionPolicy Bypass -File "%UYPS%" -KayitKodu "' . $kod . '" %IKFLAG%',
        'set "SONUC=%errorlevel%"',
        'del /f /q "%UYPS%" >nul 2>&1',
        'echo.',
        'if "%SONUC%"=="0" goto tamam',
        'echo  KURULUM BASARISIZ - cikis kodu %SONUC%',
        'echo  Log: C:\ProgramData\UzakYonetim\log\kur.log',
        'goto son',
        ':tamam',
        'echo  Kurulum tamamlandi.',
        ':son',
        'echo  Pencere 60 sn sonra kapanacak...',
        'timeout /t 60 >nul',
        'exit /b %SONUC%',
        ':iptal',
        'echo  Iptal edildi.',
        'timeout /t 5 >nul',
        'exit /b 1',
    ];
    return implode("\r\n", $satirlar) . "\r\n";
}

// Durum hesabı SQL'de tek yerde
const KOD_DURUM_SQL = "CASE
    WHEN k.Durum = 0 THEN 'iptal'
    WHEN k.KayitKodlari_SonKullanmaTarihi <= GETDATE() THEN 'suresidoldu'
    WHEN k.KayitKodlari_KullanimSayisi >= k.KayitKodlari_KullanimLimiti THEN 'limitdoldu'
    ELSE 'aktif' END";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfDogrula();

    try {
        switch ($_POST['action'] ?? '') {
            case 'istatistik':
                $s = $udb->tek(
                    "SELECT SUM(CASE WHEN d.KodDurum = 'aktif' THEN 1 ELSE 0 END) AS Aktif,
                            SUM(CASE WHEN d.KodDurum <> 'aktif' THEN 1 ELSE 0 END) AS Pasif,
                            ISNULL(SUM(d.KayitKodlari_KullanimSayisi), 0) AS Kullanim
                     FROM (SELECT " . KOD_DURUM_SQL . " AS KodDurum, k.KayitKodlari_KullanimSayisi FROM dbo.KayitKodlari k) d"
                );
                jsonCevap(['basarili' => true, 'veri' => [
                    'aktif'    => (int) $s['Aktif'],
                    'pasif'    => (int) $s['Pasif'],
                    'kullanim' => (int) $s['Kullanim'],
                    'cihaz'    => (int) $udb->deger('SELECT COUNT(*) FROM dbo.Cihazlar WHERE Durum = 1'),
                ]]);

            case 'liste':
                $kosul = ['1 = 1'];
                $param = [];
                $durum = (string) ($_POST['durum'] ?? '');
                if (in_array($durum, ['aktif', 'suresidoldu', 'limitdoldu', 'iptal'], true)) {
                    $kosul[] = KOD_DURUM_SQL . ' = ?';
                    $param[] = $durum;
                }
                if ((int) ($_POST['grup'] ?? 0) > 0) {
                    $kosul[] = 'k.KayitKodlari_CihazGruplari_id = ?';
                    $param[] = (int) $_POST['grup'];
                }
                $ara = trim((string) ($_POST['ara'] ?? ''));
                if ($ara !== '') {
                    $kosul[] = 'k.KayitKodlari_Aciklama LIKE ?';
                    $param[] = '%' . $ara . '%';
                }

                $veri = $udb->hepsi(
                    'SELECT k.KayitKodlari_id, k.KayitKodlari_Aciklama, g.CihazGruplari_Ad,
                            k.KayitKodlari_KullanimSayisi, k.KayitKodlari_KullanimLimiti,
                            CONVERT(VARCHAR(16), k.KayitKodlari_SonKullanmaTarihi, 120) AS SonKullanma,
                            CONVERT(VARCHAR(16), k.OlusturmaTarihi, 120) AS Olusturma,
                            u.kullanici_ad + \' \' + u.kullanici_soyad AS Olusturan,
                            CASE WHEN k.KayitKodlari_KodSifreli IS NULL THEN 0 ELSE 1 END AS IndirilebilirMi,
                            ' . KOD_DURUM_SQL . ' AS KodDurum
                     FROM dbo.KayitKodlari k
                     LEFT JOIN dbo.CihazGruplari g ON g.CihazGruplari_id = k.KayitKodlari_CihazGruplari_id
                     LEFT JOIN dbo.kullanicilar u ON u.kullanici_id = k.OlusturanKullanici
                     WHERE ' . implode(' AND ', $kosul) . '
                     ORDER BY k.KayitKodlari_id DESC',
                    $param
                );
                jsonCevap(['data' => $veri]);

            case 'ekle':
                $grupId   = (int) ($_POST['grup'] ?? 0);
                $gun      = (int) ($_POST['gun'] ?? 0);
                $limit    = (int) ($_POST['limit'] ?? 0);
                $aciklama = kirp($_POST['aciklama'] ?? null, 500);

                if (!$udb->deger('SELECT 1 FROM dbo.CihazGruplari WHERE CihazGruplari_id = ? AND Durum = 1', [$grupId])) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Geçerli bir grup seçin.'], 422);
                }
                $gunUst   = (int) ayar('kayit_kodu_azami_gun', 365);
                $limitUst = (int) ayar('kayit_kodu_azami_limit', 1000);
                if ($gun < 1 || $gun > $gunUst) {
                    jsonCevap(['basarili' => false, 'mesaj' => "Geçerlilik 1–{$gunUst} gün arasında olmalı."], 422);
                }
                if ($limit < 1 || $limit > $limitUst) {
                    jsonCevap(['basarili' => false, 'mesaj' => "Kullanım limiti 1–{$limitUst} arasında olmalı."], 422);
                }

                $kod = kodUret();
                $id = $udb->tek(
                    'INSERT INTO dbo.KayitKodlari
                        (KayitKodlari_KodHash, KayitKodlari_KodSifreli, KayitKodlari_CihazGruplari_id, KayitKodlari_SonKullanmaTarihi,
                         KayitKodlari_KullanimLimiti, KayitKodlari_Aciklama, OlusturanKullanici)
                     OUTPUT inserted.KayitKodlari_id
                     VALUES (?, ?, ?, DATEADD(DAY, ?, GETDATE()), ?, ?, ?)',
                    [hash('sha256', str_replace('-', '', $kod)), Sifreleme::sifrele($kod), $grupId, $gun, $limit, $aciklama, $user['kullanici_id']]
                )['KayitKodlari_id'];

                logYaz('kayit_kodu_olustur', ['id' => (int) $id, 'grup' => $grupId, 'gun' => $gun, 'limit' => $limit]);
                jsonCevap(['basarili' => true, 'mesaj' => 'Kayıt kodu oluşturuldu.', 'kod' => $kod, 'komut' => kurulumKomutu($kod)]);

            case 'indir':
                // Yalnız aktif kodun kurulum dosyası verilir (süresi/limiti dolmuş kodla kurulum zaten başarısız olur)
                $id = (int) ($_POST['id'] ?? 0);
                $satir = $udb->tek(
                    'SELECT k.KayitKodlari_KodSifreli, ' . KOD_DURUM_SQL . ' AS KodDurum
                     FROM dbo.KayitKodlari k WHERE k.KayitKodlari_id = ?',
                    [$id]
                );
                if (!$satir || $satir['KodDurum'] !== 'aktif') {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Kod bulunamadı ya da aktif değil.'], 404);
                }
                $kod = Sifreleme::coz($satir['KayitKodlari_KodSifreli']);
                if ($kod === null) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Bu kodun kurulum dosyası üretilemiyor (şifreli kopyası yok).'], 422);
                }
                logYaz('kayit_kodu_indir', ['id' => $id]);
                jsonCevap(['basarili' => true, 'dosyaAdi' => 'UzakYonetim-Kurulum.cmd', 'dosya' => kurulumDosyasi($kod)]);

            case 'iptal':
                $id = (int) ($_POST['id'] ?? 0);
                $adet = $udb->calistir(
                    'UPDATE dbo.KayitKodlari SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                     WHERE KayitKodlari_id = ? AND Durum = 1',
                    [$user['kullanici_id'], $id]
                );
                if ($adet === 0) {
                    jsonCevap(['basarili' => false, 'mesaj' => 'Kod bulunamadı ya da zaten iptal.'], 404);
                }
                logYaz('kayit_kodu_iptal', ['id' => $id]);
                jsonCevap(['basarili' => true, 'mesaj' => 'Kod iptal edildi.']);

            default:
                jsonCevap(['basarili' => false, 'mesaj' => 'Geçersiz işlem.'], 400);
        }
    } catch (Throwable $h) {
        error_log('uzak-kayit-kodlari.php: ' . $h->getMessage());
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
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Kayıt Kodları';
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
                        <div class="small-box text-bg-success">
                            <div class="inner"><h3 id="istAktif">-</h3><p>Aktif Kod</p></div>
                            <i class="small-box-icon bi bi-key"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-secondary">
                            <div class="inner"><h3 id="istPasif">-</h3><p>Pasif Kod</p></div>
                            <i class="small-box-icon bi bi-key-fill"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-info">
                            <div class="inner"><h3 id="istKullanim">-</h3><p>Toplam Kullanım</p></div>
                            <i class="small-box-icon bi bi-arrow-repeat"></i>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="small-box text-bg-primary">
                            <div class="inner"><h3 id="istCihaz">-</h3><p>Kayıtlı Cihaz</p></div>
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
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="filtreAra">Ara</label>
                                    <input type="text" class="form-control" id="filtreAra" placeholder="Açıklama">
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label" for="filtreDurum">Durum</label>
                                    <select class="form-select select2-basic" id="filtreDurum">
                                        <option value="">Tümü</option>
                                        <option value="aktif">Aktif</option>
                                        <option value="suresidoldu">Süresi Doldu</option>
                                        <option value="limitdoldu">Limit Doldu</option>
                                        <option value="iptal">İptal</option>
                                    </select>
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
                        <h3 class="card-title mb-0">Kodlar</h3>
                        <button type="button" class="btn btn-success btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#ekleModal">
                            <i class="bi bi-plus-lg me-1"></i>Yeni Kod
                        </button>
                    </div>
                    <div class="card-body">
                        <table id="kodTablo" class="table table-striped table-hover align-middle w-100">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Açıklama</th>
                                    <th>Grup</th>
                                    <th>Kullanım</th>
                                    <th>Son Kullanma</th>
                                    <th>Durum</th>
                                    <th>Oluşturan</th>
                                    <th>Oluşturma</th>
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

<!-- Yeni kod -->
<div class="modal fade" id="ekleModal" tabindex="-1" aria-labelledby="ekleModalBaslik" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ekleModalBaslik"><i class="bi bi-key me-1"></i>Yeni Kayıt Kodu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <form id="ekleForm">
                    <div class="mb-3">
                        <label class="form-label" for="ekleGrup">Grup</label>
                        <select class="form-select select2-modal" id="ekleGrup" name="grup" required>
                            <?php foreach ($gruplar as $g): ?>
                            <option value="<?= (int) $g['CihazGruplari_id'] ?>"><?= htmlspecialchars($g['CihazGruplari_Ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Bu kodla kaydolan yeni cihazlar bu gruba eklenir.</div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col">
                            <label class="form-label" for="ekleGun">Geçerlilik (gün)</label>
                            <input type="number" class="form-control" id="ekleGun" name="gun" min="1" max="<?= (int) ayar('kayit_kodu_azami_gun', 365) ?>" value="<?= (int) ayar('kayit_kodu_varsayilan_gun', 30) ?>" required>
                        </div>
                        <div class="col">
                            <label class="form-label" for="ekleLimit">Kullanım limiti</label>
                            <input type="number" class="form-control" id="ekleLimit" name="limit" min="1" max="<?= (int) ayar('kayit_kodu_azami_limit', 1000) ?>" value="<?= (int) ayar('kayit_kodu_varsayilan_limit', 50) ?>" required>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="ekleAciklama">Açıklama</label>
                        <input type="text" class="form-control" id="ekleAciklama" name="aciklama" maxlength="500" placeholder="Ör. Ekim 2026 kurulumları">
                    </div>
                </form>

                <div id="kodSonuc" class="d-none">
                    <div class="alert alert-warning py-2 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>Kod yalnız şimdi gösterilir, sonra görüntülenemez.
                    </div>
                    <div class="input-group input-group-lg mb-3">
                        <input type="text" class="form-control text-center font-monospace" id="kodMetin" readonly aria-label="Kayıt kodu">
                        <button class="btn btn-outline-primary" type="button" data-kopyala="#kodMetin" title="Kopyala"><i class="bi bi-clipboard"></i></button>
                    </div>
                    <p class="small text-muted mb-3">
                        <i class="bi bi-download me-1"></i>Çift tıklamayla çalışan kurulum dosyası (<code>.cmd</code>) listedeki
                        <strong>İşlem</strong> kolonundan indirilir.
                    </p>
                    <label class="form-label small mb-1" for="komutMetin">Elle kurulum (yönetici PowerShell):</label>
                    <div class="input-group">
                        <textarea class="form-control font-monospace kurulum-komut" id="komutMetin" rows="3" readonly></textarea>
                        <button class="btn btn-outline-primary" type="button" data-kopyala="#komutMetin" title="Kopyala"><i class="bi bi-clipboard"></i></button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                <button type="button" class="btn btn-success" id="ekleKaydet"><i class="bi bi-check-lg me-1"></i>Oluştur</button>
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
<script src="/admin/assets/js/uzak-kayit-kodlari.js?v=<?= @filemtime(__DIR__ . '/../assets/js/uzak-kayit-kodlari.js') ?>"></script>
</body>
</html>
