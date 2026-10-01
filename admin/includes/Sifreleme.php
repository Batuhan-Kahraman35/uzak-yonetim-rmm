<?php
/**
 * Hassas alan şifreleme (libsodium secretbox)
 * Anahtar: config/sifreleme.key (32 bayt, base64). Kaybolursa şifreli alanlar açılamaz.
 */

class Sifreleme
{
    private const ANAHTAR_DOSYA = __DIR__ . '/../../config/sifreleme.key';

    private static function anahtar(): string
    {
        static $anahtar = null;
        if ($anahtar === null) {
            $icerik = @file_get_contents(self::ANAHTAR_DOSYA);
            $anahtar = $icerik !== false ? base64_decode(trim($icerik), true) : false;
            if ($anahtar === false || strlen($anahtar) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new RuntimeException('Şifreleme anahtarı okunamadı (config/sifreleme.key).');
            }
        }
        return $anahtar;
    }

    public static function sifrele(string $metin): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($metin, $nonce, self::anahtar()));
    }

    public static function coz(?string $sifreli): ?string
    {
        if ($sifreli === null || $sifreli === '') {
            return null;
        }
        $ham = base64_decode($sifreli, true);
        if ($ham === false || strlen($ham) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($ham, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $metin = sodium_crypto_secretbox_open(substr($ham, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::anahtar());
        return $metin === false ? null : $metin;
    }
}
