<?php
/**
 * Envanter: nabız yanıtında istenen bölümlerin tam listesi.
 *
 * Girdi (bölümlerden biri ya da ikisi):
 * {
 *   "yazilim": { "hash": "...", "liste": [ {ad, surum, yayinci, kurulumTarihi, mimari, kaldirmaKomutu}, ... ] },
 *   "donanim": { "hash": "...",
 *                "bilgi": {uretici, model, seriNo, islemci, cekirdekSayisi, ramGb, ramDetay, anakart, biosSurum, ekranKarti},
 *                "diskler": [ {surucu, model, tip, toplamGb, bosGb}, ... ] }
 * }
 * Her bölüm kendi transaction'ında: eski kayıtlar silinir, yenileri yazılır, özet en son güncellenir.
 */

require __DIR__ . '/ortak.php';

const YAZILIM_UST_SINIR = 5000;
const TOPLU_SATIR       = 250;   // 250 satır × 7 parametre < sqlsrv 2100 parametre sınırı

$cihaz   = ajanDogrula();
$cihazId = (int) $cihaz['Cihazlar_id'];
$g       = ajanGirdi();
$yazilan = [];

function tarihAl($deger): ?string
{
    if (!is_string($deger)) {
        return null;
    }
    foreach (['Y-m-d', 'Ymd'] as $bicim) {
        $t = DateTime::createFromFormat('!' . $bicim, trim($deger));
        if ($t && $t->format($bicim) === trim($deger) && (int) $t->format('Y') >= 1990) {
            return $t->format('Y-m-d');
        }
    }
    return null;
}

function sayiAl($deger, int $ondalik = 1): ?float
{
    return is_numeric($deger) ? round((float) $deger, $ondalik) : null;
}

try {
    // ---------------------------------------------------------------- Yazılım
    if (isset($g['yazilim']) && is_array($g['yazilim'])) {
        $hash = ozetAl($g['yazilim']['hash'] ?? null);
        $liste = is_array($g['yazilim']['liste'] ?? null) ? $g['yazilim']['liste'] : null;
        if ($hash === null || $liste === null) {
            ajanHata('Yazılım bölümü eksik.', 400);
        }

        // Temizle + tekilleştir (aynı ad/sürüm/mimari bir kez)
        $satirlar = [];
        foreach (array_slice($liste, 0, YAZILIM_UST_SINIR) as $y) {
            if (!is_array($y) || ($ad = kirp($y['ad'] ?? null, 300)) === null) {
                continue;
            }
            $surum  = kirp($y['surum'] ?? null, 100);
            $mimari = kirp($y['mimari'] ?? null, 10);
            $satirlar[mb_strtolower($ad . '|' . $surum . '|' . $mimari)] = [
                $cihazId, $ad, $surum,
                kirp($y['yayinci'] ?? null, 200),
                tarihAl($y['kurulumTarihi'] ?? null),
                $mimari,
                kirp($y['kaldirmaKomutu'] ?? null, 1000),
            ];
        }

        $db->islem(function (UzakDb $db) use ($cihazId, $satirlar, $hash) {
            $db->calistir('DELETE FROM dbo.CihazYazilimlari WHERE CihazYazilimlari_Cihazlar_id = ?', [$cihazId]);
            foreach (array_chunk(array_values($satirlar), TOPLU_SATIR) as $parca) {
                $db->calistir(
                    'INSERT INTO dbo.CihazYazilimlari
                        (CihazYazilimlari_Cihazlar_id, CihazYazilimlari_Ad, CihazYazilimlari_Surum, CihazYazilimlari_Yayinci,
                         CihazYazilimlari_KurulumTarihi, CihazYazilimlari_Mimari, CihazYazilimlari_KaldirmaKomutu)
                     VALUES ' . implode(',', array_fill(0, count($parca), '(?, ?, ?, ?, ?, ?, ?)')),
                    array_merge(...$parca)
                );
            }
            $db->calistir('UPDATE dbo.Cihazlar SET Cihazlar_YazilimHash = ? WHERE Cihazlar_id = ?', [$hash, $cihazId]);
        });
        $yazilan['yazilim'] = count($satirlar);
    }

    // ---------------------------------------------------------------- Donanım
    if (isset($g['donanim']) && is_array($g['donanim'])) {
        $hash    = ozetAl($g['donanim']['hash'] ?? null);
        $b       = is_array($g['donanim']['bilgi'] ?? null) ? $g['donanim']['bilgi'] : null;
        $diskler = is_array($g['donanim']['diskler'] ?? null) ? $g['donanim']['diskler'] : [];
        if ($hash === null || $b === null) {
            ajanHata('Donanım bölümü eksik.', 400);
        }

        $bilgi = [
            kirp($b['uretici'] ?? null, 100),
            kirp($b['model'] ?? null, 150),
            kirp($b['seriNo'] ?? null, 100),
            kirp($b['islemci'] ?? null, 200),
            is_numeric($b['cekirdekSayisi'] ?? null) ? (int) $b['cekirdekSayisi'] : null,
            sayiAl($b['ramGb'] ?? null),
            kirp($b['ramDetay'] ?? null, 500),
            kirp($b['anakart'] ?? null, 200),
            kirp($b['biosSurum'] ?? null, 100),
            kirp($b['ekranKarti'] ?? null, 300),
        ];

        $diskSatirlari = [];
        foreach (array_slice($diskler, 0, 50) as $d) {
            if (!is_array($d)) {
                continue;
            }
            $diskSatirlari[] = [
                $cihazId,
                kirp($d['surucu'] ?? null, 5),
                kirp($d['model'] ?? null, 200),
                kirp($d['tip'] ?? null, 20),
                sayiAl($d['toplamGb'] ?? null),
                sayiAl($d['bosGb'] ?? null),
            ];
        }

        $db->islem(function (UzakDb $db) use ($cihazId, $bilgi, $diskSatirlari, $hash) {
            $guncellenen = $db->calistir(
                'UPDATE dbo.CihazDonanim SET
                    CihazDonanim_Uretici = ?, CihazDonanim_Model = ?, CihazDonanim_SeriNo = ?, CihazDonanim_Islemci = ?,
                    CihazDonanim_CekirdekSayisi = ?, CihazDonanim_RamGb = ?, CihazDonanim_RamDetay = ?, CihazDonanim_Anakart = ?,
                    CihazDonanim_BiosSurum = ?, CihazDonanim_EkranKarti = ?, GuncellemeTarihi = GETDATE()
                 WHERE CihazDonanim_Cihazlar_id = ?',
                array_merge($bilgi, [$cihazId])
            );
            if ($guncellenen === 0) {
                $db->calistir(
                    'INSERT INTO dbo.CihazDonanim
                        (CihazDonanim_Uretici, CihazDonanim_Model, CihazDonanim_SeriNo, CihazDonanim_Islemci,
                         CihazDonanim_CekirdekSayisi, CihazDonanim_RamGb, CihazDonanim_RamDetay, CihazDonanim_Anakart,
                         CihazDonanim_BiosSurum, CihazDonanim_EkranKarti, CihazDonanim_Cihazlar_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    array_merge($bilgi, [$cihazId])
                );
            }

            $db->calistir('DELETE FROM dbo.CihazDiskleri WHERE CihazDiskleri_Cihazlar_id = ?', [$cihazId]);
            if ($diskSatirlari) {
                $db->calistir(
                    'INSERT INTO dbo.CihazDiskleri
                        (CihazDiskleri_Cihazlar_id, CihazDiskleri_Surucu, CihazDiskleri_Model, CihazDiskleri_Tip,
                         CihazDiskleri_ToplamGb, CihazDiskleri_BosGb)
                     VALUES ' . implode(',', array_fill(0, count($diskSatirlari), '(?, ?, ?, ?, ?, ?)')),
                    array_merge(...$diskSatirlari)
                );
            }
            $db->calistir('UPDATE dbo.Cihazlar SET Cihazlar_DonanimHash = ? WHERE Cihazlar_id = ?', [$hash, $cihazId]);
        });
        $yazilan['donanim'] = count($diskSatirlari) . ' disk';
    }
} catch (Throwable $h) {
    error_log("ajan envanter (cihaz $cihazId): " . $h->getMessage());
    ajanHata('Envanter kaydedilemedi.', 500);
}

jsonCevap(['basarili' => true, 'yazilan' => $yazilan]);
