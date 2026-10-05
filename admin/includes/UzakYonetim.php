<?php
/**
 * Uzak Yönetim ortak yardımcıları (panel sayfaları + ajan API'si)
 * Destek'in DB bağlantısını kullanır; tablolar: UzakYonetimAyarlari, UzakYonetimLoglari, Cihazlar...
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/Sifreleme.php';

/**
 * Destek bağlantısı üzerinde istisna fırlatan ince katman.
 * (Database sınıfı hata olunca false döner; ajan API'si transaction ve kesin hata ister.)
 */
class UzakDb
{
    private static ?UzakDb $ornek = null;
    private $baglanti;

    private function __construct()
    {
        $this->baglanti = Database::getInstance()->getConnection();
    }

    public static function al(): UzakDb
    {
        return self::$ornek ??= new self();
    }

    /** Sorgu çalıştırır; hata olursa loglar ve istisna fırlatır (ayrıntı istemciye gitmez). */
    public function sorgu(string $sql, array $parametreler = [])
    {
        $stmt = sqlsrv_query($this->baglanti, $sql, $parametreler);
        if ($stmt === false) {
            error_log('SQL hatası: ' . print_r(sqlsrv_errors(), true) . "\nSQL: " . $sql);
            throw new RuntimeException('Veritabanı işlemi başarısız.');
        }
        return $stmt;
    }

    public function tek(string $sql, array $parametreler = []): ?array
    {
        $stmt  = $this->sorgu($sql, $parametreler);
        $satir = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $satir ?: null;
    }

    public function hepsi(string $sql, array $parametreler = []): array
    {
        $stmt     = $this->sorgu($sql, $parametreler);
        $satirlar = [];
        while ($satir = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $satirlar[] = $satir;
        }
        sqlsrv_free_stmt($stmt);
        return $satirlar;
    }

    public function deger(string $sql, array $parametreler = [])
    {
        $satir = $this->tek($sql, $parametreler);
        return $satir ? reset($satir) : null;
    }

    /** Fonksiyonu tek transaction içinde çalıştırır; hata olursa geri alır ve istisnayı yeniden fırlatır. */
    public function islem(callable $is)
    {
        if (!sqlsrv_begin_transaction($this->baglanti)) {
            throw new RuntimeException('Transaction başlatılamadı.');
        }
        try {
            $sonuc = $is($this);
            sqlsrv_commit($this->baglanti);
            return $sonuc;
        } catch (Throwable $h) {
            sqlsrv_rollback($this->baglanti);
            throw $h;
        }
    }

    /** INSERT / UPDATE / DELETE — etkilenen satır sayısını döner. */
    public function calistir(string $sql, array $parametreler = []): int
    {
        $stmt = $this->sorgu($sql, $parametreler);
        $sayi = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
        return (int) $sayi;
    }
}

/** UzakYonetimAyarlari'ndan değer okur; şifreli alanları çözer. Kayıt yoksa $varsayilan döner. */
function ayar(string $anahtar, $varsayilan = null)
{
    static $onbellek = null;
    if ($onbellek === null) {
        $onbellek = [];
        $satirlar = UzakDb::al()->hepsi(
            'SELECT UzakYonetimAyarlari_Anahtar, UzakYonetimAyarlari_Deger, UzakYonetimAyarlari_SifreliMi
             FROM dbo.UzakYonetimAyarlari WHERE Durum = 1'
        );
        foreach ($satirlar as $s) {
            $onbellek[$s['UzakYonetimAyarlari_Anahtar']] = $s['UzakYonetimAyarlari_SifreliMi']
                ? Sifreleme::coz($s['UzakYonetimAyarlari_Deger'])
                : $s['UzakYonetimAyarlari_Deger'];
        }
    }
    $deger = $onbellek[$anahtar] ?? null;
    return ($deger === null || $deger === '') ? $varsayilan : $deger;
}

function istemciIp(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
}

/** Denetim kaydı. Kullanıcı verilmezse Destek oturumundaki kullanıcı yazılır (ajan isteklerinde null). */
function logYaz(string $islem, $detay = null, ?int $kullaniciId = null, ?int $cihazId = null): void
{
    $kullaniciId ??= isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    if (is_array($detay)) {
        $detay = json_encode($detay, JSON_UNESCAPED_UNICODE);
    }
    try {
        UzakDb::al()->calistir(
            'INSERT INTO dbo.UzakYonetimLoglari
                (UzakYonetimLoglari_kullanici_id, UzakYonetimLoglari_Cihazlar_id, UzakYonetimLoglari_Islem,
                 UzakYonetimLoglari_Detay, UzakYonetimLoglari_Ip, OlusturanKullanici)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$kullaniciId, $cihazId, $islem, $detay, istemciIp(), $kullaniciId]
        );
    } catch (Throwable $h) {
        error_log('Uzak yönetim logu yazılamadı: ' . $h->getMessage());
    }
}

/** Son X dakikada bu IP'den yazılmış, verilen türlerdeki log sayısı (kaba kuvvet koruması). */
function ipHataSayisi(array $islemler): int
{
    $yer = implode(',', array_fill(0, count($islemler), '?'));
    return (int) UzakDb::al()->deger(
        "SELECT COUNT(*) FROM dbo.UzakYonetimLoglari
         WHERE UzakYonetimLoglari_Islem IN ($yer)
           AND UzakYonetimLoglari_Ip = ?
           AND OlusturmaTarihi >= DATEADD(MINUTE, -?, GETDATE())",
        array_merge($islemler, [istemciIp(), (int) ayar('ajan_kilit_dk', 15)])
    );
}

function jsonCevap(array $veri, int $durumKodu = 200): never
{
    http_response_code($durumKodu);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($veri, JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------------
// Komut kuyruğu (panel + ajan API'si ortak)
// ------------------------------------------------------------------

/** Tanim_KomutDurumlari kodundan ID (bekliyor, alindi, calisiyor, basarili, hatali, zamanasimi, suresidoldu, iptal). */
function komutDurumId(string $kod): int
{
    static $harita = null;
    if ($harita === null) {
        $harita = [];
        foreach (UzakDb::al()->hepsi('SELECT Tanim_KomutDurumlari_id, Tanim_KomutDurumlari_Kod FROM dbo.Tanim_KomutDurumlari') as $s) {
            $harita[$s['Tanim_KomutDurumlari_Kod']] = (int) $s['Tanim_KomutDurumlari_id'];
        }
    }
    if (!isset($harita[$kod])) {
        throw new RuntimeException("Komut durumu tanımlı değil: $kod");
    }
    return $harita[$kod];
}

/**
 * Komut oluşturur ve hedef cihazlara 'bekliyor' olarak ekler.
 * $tanim: script parametre tanımı (Scriptler_Parametreler JSON'u) — gönderim anındaki kopyası saklanır.
 * $degerler: kullanıcının girdiği değerler. İkisi birlikte şifreli JSON olarak Komutlar_Parametreler'e yazılır;
 * 'ayar' ve 'yeniBilgisayarAdi' tipleri teslim anında çözülür (komutParametreleriCoz).
 */
function komutOlustur(?int $scriptId, string $baslik, string $icerik, array $tanim, array $degerler,
                      int $zamanAsimiSn, array $cihazIdler, ?int $kullaniciId): int
{
    $cihazIdler = array_values(array_unique(array_filter(array_map('intval', $cihazIdler))));
    if (!$cihazIdler) {
        throw new InvalidArgumentException('Hedef cihaz yok.');
    }
    $parametre = ($tanim || $degerler)
        ? Sifreleme::sifrele(json_encode(['tanim' => $tanim, 'degerler' => $degerler], JSON_UNESCAPED_UNICODE))
        : null;
    $saat = max(1, (int) ayar('komut_varsayilan_gecerlilik_saat', 24));
    $bekliyor = komutDurumId('bekliyor');

    return UzakDb::al()->islem(function (UzakDb $db) use ($scriptId, $baslik, $icerik, $parametre, $zamanAsimiSn, $cihazIdler, $kullaniciId, $saat, $bekliyor) {
        $komutId = (int) $db->tek(
            'INSERT INTO dbo.Komutlar
                (Komutlar_Scriptler_id, Komutlar_Baslik, Komutlar_Icerik, Komutlar_Parametreler, Komutlar_ZamanAsimiSn,
                 Komutlar_SonGecerlilik, OlusturanKullanici)
             OUTPUT inserted.Komutlar_id
             VALUES (?, ?, ?, ?, ?, DATEADD(HOUR, ?, GETDATE()), ?)',
            [$scriptId, mb_substr($baslik, 0, 200), $icerik, $parametre, max(10, $zamanAsimiSn), $saat, $kullaniciId]
        )['Komutlar_id'];

        foreach (array_chunk($cihazIdler, 500) as $parca) {
            $satirlar = [];
            $param = [];
            foreach ($parca as $cihazId) {
                $satirlar[] = '(?, ?, ?, ?)';
                array_push($param, $komutId, $cihazId, $bekliyor, $kullaniciId);
            }
            $db->calistir(
                'INSERT INTO dbo.KomutHedefleri
                    (KomutHedefleri_Komutlar_id, KomutHedefleri_Cihazlar_id, KomutHedefleri_Tanim_KomutDurumlari_id, OlusturanKullanici)
                 VALUES ' . implode(',', $satirlar),
                $param
            );
        }
        return $komutId;
    });
}

/**
 * Teslim anında script parametrelerini çözer.
 * Tanım öğesi: {ad, tip: metin|sayi|ayar|yeniBilgisayarAdi, ayarAnahtari?, varsayilan?, onek?, adet?}
 */
function komutParametreleriCoz(?string $sifreli, int $cihazId): array
{
    if ($sifreli === null || $sifreli === '') {
        return [];
    }
    $veri = json_decode((string) Sifreleme::coz($sifreli), true);
    if (!is_array($veri)) {
        throw new RuntimeException('Komut parametreleri çözülemedi.');
    }
    $degerler = is_array($veri['degerler'] ?? null) ? $veri['degerler'] : [];
    $sonuc = [];
    foreach ((array) ($veri['tanim'] ?? []) as $t) {
        $ad = (string) ($t['ad'] ?? '');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,49}$/', $ad)) {
            continue;
        }
        switch ($t['tip'] ?? 'metin') {
            case 'ayar':
                $sonuc[$ad] = ayar((string) ($t['ayarAnahtari'] ?? ''), '');
                break;
            case 'yeniBilgisayarAdi':
                $sonuc[$ad] = bilgisayarAdiAdaylari((string) ($t['onek'] ?? 'ornek-'), (int) ($t['adet'] ?? 5));
                break;
            case 'sayi':
                $sonuc[$ad] = (int) ($degerler[$ad] ?? $t['varsayilan'] ?? 0);
                break;
            default:
                $sonuc[$ad] = (string) ($degerler[$ad] ?? $t['varsayilan'] ?? '');
        }
    }
    return $sonuc;
}

/** DB'de kullanılmayan rastgele bilgisayar adı adayları (ör. ornek-4821). Ağ çakışmasını script ping ile ayrıca kontrol eder. */
function bilgisayarAdiAdaylari(string $onek, int $adet): array
{
    $onek = preg_replace('/[^A-Za-z0-9-]/', '', $onek);
    $kullanilan = array_flip(array_map('strtolower', array_column(UzakDb::al()->hepsi(
        'SELECT Cihazlar_BilgisayarAdi FROM dbo.Cihazlar WHERE Cihazlar_BilgisayarAdi LIKE ?',
        [$onek . '%']
    ), 'Cihazlar_BilgisayarAdi')));
    $adaylar = [];
    for ($i = 0; $i < 200 && count($adaylar) < max(1, min(20, $adet)); $i++) {
        $ad = $onek . random_int(1000, 9999);
        if (!isset($kullanilan[strtolower($ad)]) && !in_array($ad, $adaylar, true)) {
            $adaylar[] = $ad;
        }
    }
    return $adaylar;
}

/**
 * İlk kurulum script'lerini (Scriptler_IlkKurulumSira sırasıyla) cihaz için kuyruğa alır.
 * Cihazda bekleyen / çalışan bir ilk kurulum komutu varsa tekrar eklemez. Eklenen komut sayısını döner.
 */
function ilkKurulumKuyrugaAl(int $cihazId): int
{
    $db = UzakDb::al();
    $acik = $db->deger(
        "SELECT COUNT(*) FROM dbo.KomutHedefleri h
         INNER JOIN dbo.Komutlar k ON k.Komutlar_id = h.KomutHedefleri_Komutlar_id
         INNER JOIN dbo.Scriptler s ON s.Scriptler_id = k.Komutlar_Scriptler_id
         INNER JOIN dbo.Tanim_KomutDurumlari d ON d.Tanim_KomutDurumlari_id = h.KomutHedefleri_Tanim_KomutDurumlari_id
         WHERE h.KomutHedefleri_Cihazlar_id = ? AND s.Scriptler_IlkKurulumSira IS NOT NULL AND d.Tanim_KomutDurumlari_BittiMi = 0",
        [$cihazId]
    );
    if ((int) $acik > 0) {
        return 0;
    }
    $scriptler = $db->hepsi(
        'SELECT Scriptler_id, Scriptler_Ad, Scriptler_Icerik, Scriptler_Parametreler, Scriptler_ZamanAsimiSn
         FROM dbo.Scriptler WHERE Durum = 1 AND Scriptler_IlkKurulumSira IS NOT NULL
         ORDER BY Scriptler_IlkKurulumSira, Scriptler_id'
    );
    foreach ($scriptler as $s) {
        komutOlustur(
            (int) $s['Scriptler_id'],
            'İlk kurulum: ' . $s['Scriptler_Ad'],
            $s['Scriptler_Icerik'],
            json_decode((string) $s['Scriptler_Parametreler'], true) ?: [],
            [],
            (int) $s['Scriptler_ZamanAsimiSn'],
            [$cihazId],
            null
        );
    }
    return count($scriptler);
}

/** Parametre tipleri: kodda karşılığı olan davranışlar (komutParametreleriCoz ile birlikte değişir). */
const UZAK_PARAMETRE_TIPLERI = ['metin', 'sayi', 'ayar', 'yeniBilgisayarAdi'];

/**
 * Script parametre tanımını (JSON) doğrular ve normalize eder. Hata varsa InvalidArgumentException.
 * Öğe: {ad, tip, etiket?, varsayilan?, ayarAnahtari? (tip=ayar), onek?, adet? (tip=yeniBilgisayarAdi)}
 */
function parametreTanimiDogrula(?string $json): array
{
    $json = trim((string) $json);
    if ($json === '') {
        return [];
    }
    $liste = json_decode($json, true);
    if (!is_array($liste) || !array_is_list($liste)) {
        throw new InvalidArgumentException('Parametre tanımı bir JSON dizisi olmalı: [{"ad":"...","tip":"metin"}]');
    }
    $adlar = [];
    $sonuc = [];
    foreach ($liste as $i => $p) {
        $sira = $i + 1;
        $ad = (string) ($p['ad'] ?? '');
        $tip = (string) ($p['tip'] ?? 'metin');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,49}$/', $ad)) {
            throw new InvalidArgumentException("$sira. parametre: 'ad' harfle başlamalı, yalnız harf/rakam/_ içermeli.");
        }
        if (isset($adlar[strtolower($ad)])) {
            throw new InvalidArgumentException("$sira. parametre: '$ad' adı tekrar ediyor.");
        }
        if (!in_array($tip, UZAK_PARAMETRE_TIPLERI, true)) {
            throw new InvalidArgumentException("$sira. parametre: tip " . implode(' / ', UZAK_PARAMETRE_TIPLERI) . ' olmalı.');
        }
        $adlar[strtolower($ad)] = true;
        $oge = ['ad' => $ad, 'tip' => $tip];
        if (isset($p['etiket']) && $p['etiket'] !== '') {
            $oge['etiket'] = mb_substr((string) $p['etiket'], 0, 100);
        }
        if ($tip === 'ayar') {
            $anahtar = (string) ($p['ayarAnahtari'] ?? '');
            if (!UzakDb::al()->deger('SELECT 1 FROM dbo.UzakYonetimAyarlari WHERE UzakYonetimAyarlari_Anahtar = ? AND Durum = 1', [$anahtar])) {
                throw new InvalidArgumentException("$sira. parametre: '$anahtar' ayarı tanımlı değil.");
            }
            $oge['ayarAnahtari'] = $anahtar;
        } elseif ($tip === 'yeniBilgisayarAdi') {
            $oge['onek'] = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($p['onek'] ?? 'ornek-')) ?: 'ornek-';
            $oge['adet'] = max(1, min(20, (int) ($p['adet'] ?? 5)));
        } elseif (array_key_exists('varsayilan', $p)) {
            $oge['varsayilan'] = $tip === 'sayi' ? (int) $p['varsayilan'] : mb_substr((string) $p['varsayilan'], 0, 1000);
        }
        $sonuc[] = $oge;
    }
    return $sonuc;
}

/**
 * Script'i cihazlara gönderir. $degerler: yalnız metin/sayi tipleri için kullanıcı girişi.
 * Dönen: [komutId, hedef (cihaz sayısı), eskiAjan (0.2.0 altı; ajan güncellenene kadar komutu almaz)]
 */
function scriptGonder(int $scriptId, array $cihazIdler, array $degerler, int $kullaniciId): array
{
    $db = UzakDb::al();
    $s = $db->tek(
        'SELECT Scriptler_Ad, Scriptler_Icerik, Scriptler_Parametreler, Scriptler_ZamanAsimiSn
         FROM dbo.Scriptler WHERE Scriptler_id = ? AND Durum = 1',
        [$scriptId]
    );
    if (!$s) {
        throw new InvalidArgumentException('Script bulunamadı.');
    }
    $cihazIdler = array_values(array_unique(array_filter(array_map('intval', $cihazIdler))));
    if (!$cihazIdler) {
        throw new InvalidArgumentException('En az bir hedef cihaz seçin.');
    }
    $yer = implode(',', array_fill(0, count($cihazIdler), '?'));
    $cihazlar = $db->hepsi("SELECT Cihazlar_id, Cihazlar_AjanSurum FROM dbo.Cihazlar WHERE Durum = 1 AND Cihazlar_id IN ($yer)", $cihazIdler);
    if (!$cihazlar) {
        throw new InvalidArgumentException('Seçilen cihazlar bulunamadı.');
    }

    $tanim = json_decode((string) $s['Scriptler_Parametreler'], true) ?: [];
    $temiz = [];
    foreach ($tanim as $t) {
        $ad = $t['ad'] ?? '';
        if (!in_array($t['tip'] ?? 'metin', ['metin', 'sayi'], true) || !array_key_exists($ad, $degerler)) {
            continue;
        }
        $temiz[$ad] = ($t['tip'] === 'sayi') ? (int) $degerler[$ad] : mb_substr((string) $degerler[$ad], 0, 1000);
    }

    $komutId = komutOlustur(
        $scriptId, $s['Scriptler_Ad'], $s['Scriptler_Icerik'], $tanim, $temiz,
        (int) $s['Scriptler_ZamanAsimiSn'], array_column($cihazlar, 'Cihazlar_id'), $kullaniciId
    );
    $eski = count(array_filter($cihazlar, fn($c) => !preg_match('/^\d+(\.\d+)*$/', (string) $c['Cihazlar_AjanSurum'])
        || version_compare((string) $c['Cihazlar_AjanSurum'], '0.2.0', '<')));

    // Parametre değerleri loga yazılmaz (gizli olabilir); yalnız adları
    logYaz('komut_gonder', ['komutId' => $komutId, 'scriptId' => $scriptId, 'hedef' => count($cihazlar), 'parametreler' => array_keys($temiz)],
        $kullaniciId, count($cihazlar) === 1 ? (int) $cihazlar[0]['Cihazlar_id'] : null);

    return ['komutId' => $komutId, 'hedef' => count($cihazlar), 'eskiAjan' => $eski];
}

// ------------------------------------------------------------------
// Panel sayfaları (Destek oturumu gerekir; ajan API'si bunları kullanmaz)
// ------------------------------------------------------------------

/**
 * Destek sayfa yetkisi (Menu_Sayfalar / menu_sayfa_yetkiler). Yetkisizse AJAX'a JSON, sayfaya uyarı döner.
 * Menüde olmayan alt sayfalar (ör. cihaz detay) yetkiyi bağlı olduğu liste sayfasından okur ($sayfa).
 */
function uzakSayfaYetkisi(array $user, ?string $sayfa = null): array
{
    $yetki = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $sayfa ?? basename($_SERVER['PHP_SELF']));
    if (empty($yetki['has_access'])) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            jsonCevap(['basarili' => false, 'mesaj' => 'Bu sayfaya erişim yetkiniz bulunmamaktadır.'], 403);
        }
        PageAuth::accessDenied($yetki['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
    }
    return $yetki;
}

function csrfToken(): string
{
    return $_SESSION['uzak_csrf'] ??= bin2hex(random_bytes(32));
}

function csrfDogrula(): void
{
    $gelen = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '';
    if (!is_string($gelen) || !hash_equals(csrfToken(), $gelen)) {
        jsonCevap(['basarili' => false, 'mesaj' => 'Oturum doğrulaması başarısız. Sayfayı yenileyin.'], 419);
    }
}

/** Metni kolon uzunluğuna kırpar; boşsa null. */
function kirp($deger, int $uzunluk): ?string
{
    if ($deger === null || is_array($deger)) {
        return null;
    }
    $deger = trim((string) $deger);
    return $deger === '' ? null : mb_substr($deger, 0, $uzunluk);
}

/**
 * Anakart UUID + BIOS seriden cihaz donanım kimliği (SHA256). Ajan kaydı ve dış API aynı kuralı kullanır.
 * Anlamsız / fabrika varsayılanı değerler kimlik sayılmaz (Ayarlar: donanim_kimlik_gecersiz, | ile ayrılmış).
 */
function donanimKimlikHesapla($uuid, $seri): ?string
{
    $gecersiz = array_map(
        fn($d) => strtoupper(trim($d)),
        explode('|', (string) ayar('donanim_kimlik_gecersiz',
            'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF|00000000-0000-0000-0000-000000000000|03000200-0400-0500-0006-000700080009'
            . '|TO BE FILLED BY O.E.M.|DEFAULT STRING|SYSTEM SERIAL NUMBER|NONE|0|123456789|NOT APPLICABLE'))
    );
    $uuid = strtoupper(trim(is_scalar($uuid) ? (string) $uuid : ''));
    $seri = strtoupper(trim(is_scalar($seri) ? (string) $seri : ''));
    if (in_array($seri, $gecersiz, true)) {
        $seri = '';
    }
    return ($uuid !== '' && !in_array($uuid, $gecersiz, true))
        ? hash('sha256', $uuid . '|' . $seri)
        : null;
}

/* ---------- Cihaz etiketleri (Etiketler + CihazEtiketleri) ---------- */

/** POST'tan gelen ID listesini pozitif, tekil tamsayılara indirger (en fazla $azami adet). */
function idListesi($deger, int $azami = 500): array
{
    $idler = array_values(array_unique(array_filter(array_map('intval', (array) $deger), fn($i) => $i > 0)));
    return array_slice($idler, 0, $azami);
}

/** IN (...) için soru işareti listesi. */
function yerTutucu(array $degerler): string
{
    return implode(',', array_fill(0, count($degerler), '?'));
}

/** Etiket rengi için okunur yazı rengi (#fff / #000). */
function etiketYaziRengi(string $renk): string
{
    if (!preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $renk, $m)) {
        return '#fff';
    }
    $parlaklik = (hexdec($m[1]) * 299 + hexdec($m[2]) * 587 + hexdec($m[3]) * 114) / 1000;
    return $parlaklik > 150 ? '#000' : '#fff';
}

/**
 * Cihazların aktif etiketleri: [Cihazlar_id => [['id','ad','renk'], ...]]
 * Server-side listede yalnız o sayfadaki cihazlar için çağrılır.
 */
function cihazEtiketleri(UzakDb $udb, array $cihazIdler): array
{
    $cihazIdler = idListesi($cihazIdler, 1000);
    if (!$cihazIdler) {
        return [];
    }
    $satirlar = $udb->hepsi(
        'SELECT ce.CihazEtiketleri_Cihazlar_id AS CihazId, e.Etiketler_id, e.Etiketler_Ad, e.Etiketler_Renk
         FROM dbo.CihazEtiketleri ce
         INNER JOIN dbo.Etiketler e ON e.Etiketler_id = ce.CihazEtiketleri_Etiketler_id AND e.Durum = 1
         WHERE ce.CihazEtiketleri_Cihazlar_id IN (' . yerTutucu($cihazIdler) . ')
         ORDER BY e.Etiketler_Ad',
        $cihazIdler
    );
    $sonuc = [];
    foreach ($satirlar as $s) {
        $sonuc[(int) $s['CihazId']][] = ['id' => (int) $s['Etiketler_id'], 'ad' => $s['Etiketler_Ad'], 'renk' => $s['Etiketler_Renk']];
    }
    return $sonuc;
}

/** Verilen ID'lerden yalnız aktif etiketleri döner. */
function aktifEtiketIdleri(UzakDb $udb, array $etiketIdler): array
{
    $etiketIdler = idListesi($etiketIdler, 100);
    if (!$etiketIdler) {
        return [];
    }
    $satirlar = $udb->hepsi(
        'SELECT Etiketler_id FROM dbo.Etiketler WHERE Durum = 1 AND Etiketler_id IN (' . yerTutucu($etiketIdler) . ')',
        $etiketIdler
    );
    return array_map(fn($s) => (int) $s['Etiketler_id'], $satirlar);
}

/**
 * Cihazlara etiket ekler (zaten olanları atlar). Eklenen satır sayısını döner.
 * $cihazIdler ve $etiketIdler önceden doğrulanmış olmalı.
 */
function cihazEtiketEkle(UzakDb $udb, array $cihazIdler, array $etiketIdler, int $kullaniciId): int
{
    if (!$cihazIdler || !$etiketIdler) {
        return 0;
    }
    return $udb->calistir(
        'INSERT INTO dbo.CihazEtiketleri (CihazEtiketleri_Cihazlar_id, CihazEtiketleri_Etiketler_id, OlusturanKullanici)
         SELECT c.Cihazlar_id, e.Etiketler_id, ?
         FROM dbo.Cihazlar c
         CROSS JOIN dbo.Etiketler e
         WHERE c.Durum = 1 AND c.Cihazlar_id IN (' . yerTutucu($cihazIdler) . ')
           AND e.Durum = 1 AND e.Etiketler_id IN (' . yerTutucu($etiketIdler) . ')
           AND NOT EXISTS (SELECT 1 FROM dbo.CihazEtiketleri x
                           WHERE x.CihazEtiketleri_Cihazlar_id = c.Cihazlar_id AND x.CihazEtiketleri_Etiketler_id = e.Etiketler_id)',
        array_merge([$kullaniciId], $cihazIdler, $etiketIdler)
    );
}

/** Cihazlardan etiket kaldırır. Silinen satır sayısını döner. */
function cihazEtiketKaldir(UzakDb $udb, array $cihazIdler, array $etiketIdler): int
{
    if (!$cihazIdler || !$etiketIdler) {
        return 0;
    }
    return $udb->calistir(
        'DELETE FROM dbo.CihazEtiketleri
         WHERE CihazEtiketleri_Cihazlar_id IN (' . yerTutucu($cihazIdler) . ')
           AND CihazEtiketleri_Etiketler_id IN (' . yerTutucu($etiketIdler) . ')',
        array_merge($cihazIdler, $etiketIdler)
    );
}

/**
 * Tek cihazın aktif etiketlerini verilen listeyle eşitler (pasif etiket atamalarına dokunmaz).
 * Transaction içinde çağrılmalı.
 */
function cihazEtiketleriniEsitle(UzakDb $udb, int $cihazId, array $etiketIdler, int $kullaniciId): void
{
    $param = [$cihazId];
    $haric = '';
    if ($etiketIdler) {
        $haric = ' AND CihazEtiketleri_Etiketler_id NOT IN (' . yerTutucu($etiketIdler) . ')';
        $param = array_merge($param, $etiketIdler);
    }
    $udb->calistir(
        'DELETE FROM dbo.CihazEtiketleri
         WHERE CihazEtiketleri_Cihazlar_id = ?' . $haric . '
           AND CihazEtiketleri_Etiketler_id IN (SELECT Etiketler_id FROM dbo.Etiketler WHERE Durum = 1)',
        $param
    );
    cihazEtiketEkle($udb, [$cihazId], $etiketIdler, $kullaniciId);
}
