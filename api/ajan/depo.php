<?php
/**
 * Dosya deposu (kimlikli, GET)
 *   ?paket=BGInfo  → { basarili, dosyalar: [{id, ad, sha256, boyut}] }
 *   ?id=N          → dosyanın kendisi, X-Sha256 başlığıyla (ajan indirdiğini bununla doğrular)
 * Dosyalar diskte ajan/dosyalar/<sha256> yolunda durur (web'den 403).
 */

require __DIR__ . '/ortak.php';

ajanDogrula();

const DEPO_KLASOR = __DIR__ . '/../../ajan/dosyalar/';

if (isset($_GET['paket'])) {
    $paket = trim((string) $_GET['paket']);
    if ($paket === '' || mb_strlen($paket) > 100) {
        ajanHata('Geçersiz paket.', 400);
    }
    $dosyalar = $db->hepsi(
        'SELECT UzakDosyalar_id AS id, UzakDosyalar_Ad AS ad, UzakDosyalar_Sha256 AS sha256, UzakDosyalar_Boyut AS boyut
         FROM dbo.UzakDosyalar WHERE UzakDosyalar_Paket = ? AND Durum = 1 ORDER BY UzakDosyalar_Ad',
        [$paket]
    );
    if (!$dosyalar) {
        ajanHata('Paket bulunamadı ya da boş.', 404);
    }
    foreach ($dosyalar as &$d) {
        $d['id'] = (int) $d['id'];
        $d['boyut'] = (int) $d['boyut'];
    }
    jsonCevap(['basarili' => true, 'paket' => $paket, 'dosyalar' => $dosyalar]);
}

$id = (int) ($_GET['id'] ?? 0);
$kayit = $id > 0 ? $db->tek(
    'SELECT UzakDosyalar_Ad, UzakDosyalar_Sha256 FROM dbo.UzakDosyalar WHERE UzakDosyalar_id = ? AND Durum = 1',
    [$id]
) : null;
if (!$kayit || !preg_match('/^[0-9a-f]{64}$/', $kayit['UzakDosyalar_Sha256'])) {
    ajanHata('Dosya bulunamadı.', 404);
}
$yol = DEPO_KLASOR . $kayit['UzakDosyalar_Sha256'];
if (!is_file($yol)) {
    error_log("ajan depo: kayıt var, dosya yok (id $id)");
    ajanHata('Dosya bulunamadı.', 404);
}

header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($yol));
header('X-Sha256: ' . $kayit['UzakDosyalar_Sha256']);
readfile($yol);
