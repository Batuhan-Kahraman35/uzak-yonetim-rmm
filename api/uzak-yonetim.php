<?php
/**
 * Uzak Yönetim dış API'si (sunucudan sunucuya; ör. portal pdks-api)
 *
 * - Kimlik: X-API-KEY başlığı. DB'de (UzakApiIstemcileri) yalnız SHA256 özeti durur.
 * - İstemci bazında yetki kapsamı (UzakApiIstemcileri_Yetkiler) ve IP listesi uygulanır.
 * - Cihaz, ajanla aynı kuralla üretilen donanım kimliğiyle bulunur (anakart UUID + BIOS seri).
 * - Hatalı anahtar denemeleri loglanır; IP bazlı kilit ajan API'siyle aynı ayarları kullanır.
 *
 * İstek: POST, JSON { action, anakartUuid, biosSeri, ... }
 *   cihaz_getir        (cihaz_oku)       → cihaz özeti
 *   aciklama_guncelle  (cihaz_aciklama)  + { aciklama, personel? } → açıklamayı yazar (değişmediyse yazmaz)
 */

declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/UzakYonetim.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const API_HATA_ISLEMLERI = ['api_yetki_basarisiz'];
const API_GOVDE_SINIRI   = 64 * 1024;

function apiHata(string $mesaj, int $kod, ?string $hataKodu = null): never
{
    jsonCevap(['basarili' => false, 'hata' => $hataKodu, 'mesaj' => $mesaj], $kod);
}

/** Başlıktaki anahtarı doğrular, istemci satırını döner. */
function apiIstemciDogrula(UzakDb $udb): array
{
    if (ipHataSayisi(API_HATA_ISLEMLERI) >= (int) ayar('ajan_hata_limiti', 20)) {
        header('Retry-After: ' . ((int) ayar('ajan_kilit_dk', 15) * 60));
        apiHata('Çok fazla hatalı istek.', 429, 'kilitli');
    }

    $anahtar = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if (!preg_match('/^[0-9a-f]{64}$/', $anahtar)) {
        logYaz('api_yetki_basarisiz', ['neden' => 'biçim'], null);
        apiHata('Kimlik doğrulanamadı.', 401, 'yetkisiz');
    }

    $ozet     = hash('sha256', $anahtar);
    $istemci  = $udb->tek(
        'SELECT UzakApiIstemcileri_id, UzakApiIstemcileri_Ad, UzakApiIstemcileri_AnahtarHash,
                UzakApiIstemcileri_Yetkiler, UzakApiIstemcileri_IzinliIpler
         FROM dbo.UzakApiIstemcileri WHERE UzakApiIstemcileri_AnahtarHash = ? AND Durum = 1',
        [$ozet]
    );
    if (!$istemci || !hash_equals($istemci['UzakApiIstemcileri_AnahtarHash'], $ozet)) {
        logYaz('api_yetki_basarisiz', ['neden' => 'anahtar'], null);
        apiHata('Kimlik doğrulanamadı.', 401, 'yetkisiz');
    }

    $izinli = array_filter(array_map('trim', explode(',', (string) $istemci['UzakApiIstemcileri_IzinliIpler'])));
    if ($izinli && !in_array(istemciIp(), $izinli, true)) {
        logYaz('api_yetki_basarisiz', ['neden' => 'ip', 'istemci' => (int) $istemci['UzakApiIstemcileri_id']], null);
        apiHata('Bu adresten erişim izni yok.', 403, 'ip_izni_yok');
    }

    $udb->calistir(
        'UPDATE dbo.UzakApiIstemcileri SET UzakApiIstemcileri_SonKullanim = GETDATE(), UzakApiIstemcileri_SonIp = ?
         WHERE UzakApiIstemcileri_id = ?',
        [istemciIp(), $istemci['UzakApiIstemcileri_id']]
    );

    $istemci['yetkiler'] = array_filter(array_map('trim', explode(',', (string) $istemci['UzakApiIstemcileri_Yetkiler'])));
    return $istemci;
}

function apiYetkiGerekli(array $istemci, string $yetki): void
{
    if (!in_array($yetki, $istemci['yetkiler'], true)) {
        apiHata('Bu işlem için yetki yok.', 403, 'yetki_yok');
    }
}

/** Donanım kimliğiyle cihazı bulur; bulunamazsa 404. */
function apiCihazBul(UzakDb $udb, array $girdi, int $cevrimdisiDk): array
{
    $donanimKimlik = donanimKimlikHesapla($girdi['anakartUuid'] ?? '', $girdi['biosSeri'] ?? '');
    if ($donanimKimlik === null) {
        apiHata('Geçerli bir anakart UUID gerekli.', 400, 'donanim_kimligi_gecersiz');
    }

    $cihaz = $udb->tek(
        'SELECT TOP 1 c.Cihazlar_id, c.Cihazlar_BilgisayarAdi, c.Cihazlar_Aciklama, g.CihazGruplari_Ad,
                c.Cihazlar_AnyDeskId, c.Cihazlar_RustDeskId, c.Cihazlar_AktifKullanici, c.Cihazlar_AjanSurum,
                CONVERT(VARCHAR(19), c.Cihazlar_SonGorulme, 120) AS SonGorulme,
                CASE WHEN c.Cihazlar_SonGorulme >= DATEADD(MINUTE, -?, GETDATE()) THEN 1 ELSE 0 END AS Cevrimici
         FROM dbo.Cihazlar c
         LEFT JOIN dbo.CihazGruplari g ON g.CihazGruplari_id = c.Cihazlar_CihazGruplari_id
         WHERE c.Cihazlar_DonanimKimlik = ? AND c.Durum = 1
         ORDER BY c.Cihazlar_SonGorulme DESC',
        [$cevrimdisiDk, $donanimKimlik]
    );
    if (!$cihaz) {
        apiHata('Bu bilgisayarda uzak yönetim ajanı kurulu değil.', 404, 'cihaz_bulunamadi');
    }
    return $cihaz;
}

function apiCihazCevap(array $c): array
{
    return [
        'cihazId'        => (int) $c['Cihazlar_id'],
        'bilgisayarAdi'  => $c['Cihazlar_BilgisayarAdi'],
        'aciklama'       => $c['Cihazlar_Aciklama'],
        'grup'           => $c['CihazGruplari_Ad'],
        'anyDeskId'      => $c['Cihazlar_AnyDeskId'],
        'rustDeskId'     => $c['Cihazlar_RustDeskId'],
        'aktifKullanici' => $c['Cihazlar_AktifKullanici'],
        'ajanSurum'      => $c['Cihazlar_AjanSurum'],
        'sonGorulme'     => $c['SonGorulme'],
        'cevrimici'      => (bool) $c['Cevrimici'],
    ];
}

// --- İstek ---------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiHata('Yalnız POST.', 405);
}

try {
    $udb     = UzakDb::al();
    $istemci = apiIstemciDogrula($udb);

    $govde = file_get_contents('php://input', false, null, 0, API_GOVDE_SINIRI + 1);
    if ($govde === false || strlen($govde) > API_GOVDE_SINIRI) {
        apiHata('İstek gövdesi çok büyük.', 413);
    }
    $girdi = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $govde), true);
    if (!is_array($girdi)) {
        apiHata('Geçersiz JSON.', 400);
    }

    $cevrimdisiDk = max(1, (int) ayar('cihaz_cevrimdisi_dk', 5));
    $istemciId    = (int) $istemci['UzakApiIstemcileri_id'];

    switch ($girdi['action'] ?? '') {
        case 'cihaz_getir':
            apiYetkiGerekli($istemci, 'cihaz_oku');
            $cihaz = apiCihazBul($udb, $girdi, $cevrimdisiDk);
            jsonCevap(['basarili' => true, 'veri' => apiCihazCevap($cihaz)]);

        case 'aciklama_guncelle':
            apiYetkiGerekli($istemci, 'cihaz_aciklama');
            $cihaz    = apiCihazBul($udb, $girdi, $cevrimdisiDk);
            $aciklama = kirp($girdi['aciklama'] ?? null, 500);
            $once     = $cihaz['Cihazlar_Aciklama'];

            // Her girişte çağrılır; değer aynıysa yazma ve log yok
            if ($aciklama === $once) {
                jsonCevap(['basarili' => true, 'degisti' => false, 'veri' => apiCihazCevap($cihaz)]);
            }

            $udb->calistir(
                'UPDATE dbo.Cihazlar SET Cihazlar_Aciklama = ?, GuncellemeTarihi = GETDATE() WHERE Cihazlar_id = ?',
                [$aciklama, $cihaz['Cihazlar_id']]
            );
            logYaz('api_aciklama_guncelle', [
                'istemci'  => $istemciId,
                'personel' => kirp($girdi['personel'] ?? null, 150),
                'once'     => $once,
                'sonra'    => $aciklama,
            ], null, (int) $cihaz['Cihazlar_id']);

            $cihaz['Cihazlar_Aciklama'] = $aciklama;
            jsonCevap(['basarili' => true, 'degisti' => true, 'veri' => apiCihazCevap($cihaz)]);

        default:
            apiHata('Geçersiz işlem.', 400, 'gecersiz_islem');
    }
} catch (Throwable $h) {
    error_log('api/uzak-yonetim.php: ' . $h->getMessage());
    apiHata('Sunucu hatası.', 500, 'sunucu_hatasi');
}
