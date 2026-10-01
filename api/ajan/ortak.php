<?php
/**
 * Ajan API ortak başlangıcı
 * - Oturum/çerez yok; cihaz kimliği X-Ajan-Guid + X-Ajan-Token başlıklarıyla doğrulanır.
 * - Token DB'de yalnız SHA256 özeti olarak durur.
 * - Hatalı denemeler UzakYonetimLoglari'na yazılır; IP bazlı kilit uygulanır.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../admin/includes/UzakYonetim.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const AJAN_HATA_ISLEMLERI = ['ajan_kayit_basarisiz', 'ajan_yetki_basarisiz'];
const AJAN_GOVDE_SINIRI   = 4 * 1024 * 1024;

$db = UzakDb::al();

function ajanHata(string $mesaj, int $kod): never
{
    jsonCevap(['basarili' => false, 'mesaj' => $mesaj], $kod);
}

/** IP kilitliyse 429 döner (kilitliyken log yazılmaz; bozuk bir ajan tabloyu şişirmesin). */
function ajanKilitKontrol(): void
{
    if (ipHataSayisi(AJAN_HATA_ISLEMLERI) >= (int) ayar('ajan_hata_limiti', 20)) {
        header('Retry-After: ' . ((int) ayar('ajan_kilit_dk', 15) * 60));
        ajanHata('Çok fazla hatalı istek.', 429);
    }
}

/** POST gövdesini JSON olarak okur. */
function ajanGirdi(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ajanHata('Yalnız POST.', 405);
    }
    $govde = file_get_contents('php://input', false, null, 0, AJAN_GOVDE_SINIRI + 1);
    if ($govde === false || strlen($govde) > AJAN_GOVDE_SINIRI) {
        ajanHata('İstek gövdesi çok büyük.', 413);
    }
    // PowerShell 5.1 bazen UTF-8 BOM ile gönderir
    $govde = preg_replace('/^\xEF\xBB\xBF/', '', $govde);
    $veri  = json_decode($govde, true);
    if (!is_array($veri)) {
        ajanHata('Geçersiz JSON.', 400);
    }
    return $veri;
}

function tokenOzet(string $token): string
{
    return hash('sha256', $token);
}

/** Başlıklardaki kimliği doğrular, cihaz satırını döner. */
function ajanDogrula(): array
{
    ajanKilitKontrol();

    $guid  = (string) ($_SERVER['HTTP_X_AJAN_GUID'] ?? '');
    $token = (string) ($_SERVER['HTTP_X_AJAN_TOKEN'] ?? '');

    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $guid) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        logYaz('ajan_yetki_basarisiz', ['neden' => 'biçim'], null);
        ajanHata('Kimlik doğrulanamadı.', 401);
    }

    $cihaz = UzakDb::al()->tek(
        'SELECT Cihazlar_id, Cihazlar_TokenHash, Cihazlar_YazilimHash, Cihazlar_DonanimHash
         FROM dbo.Cihazlar WHERE Cihazlar_Guid = ? AND Durum = 1',
        [$guid]
    );

    if (!$cihaz || !hash_equals($cihaz['Cihazlar_TokenHash'], tokenOzet($token))) {
        logYaz('ajan_yetki_basarisiz', ['guid' => $guid], null);
        ajanHata('Kimlik doğrulanamadı.', 401);
    }
    return $cihaz;
}

/** Ajanın gönderdiği SHA256 özeti; geçersizse null. */
function ozetAl($deger): ?string
{
    return (is_string($deger) && preg_match('/^[0-9a-fA-F]{64}$/', $deger)) ? strtolower($deger) : null;
}
