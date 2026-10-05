# Uzak Yönetim (Self-Hosted RMM)

Domain controller **olmadan**, farklı ağlardaki (şube, ev, 4G) Windows bilgisayarları tek panelden yöneten, **kendi sunucunuzda barındırılan** hafif bir uzak yönetim (RMM) sistemi. EMCO Remote Installer / Tactical RMM benzeri; ajanlı **pull** mimarisiyle çalışır — yönetilen PC'lerde açık port, VPN veya statik IP gerekmez.

> ⚠️ Bu depo bir **modül + referans** olarak paylaşılmıştır. Kod, bir PHP yönetim paneline (oturum, yetki, DB) entegre çalışır; tek başına çalışan bir uygulama değildir. Tüm alan adı, IP ve örnek değerler **maskelidir**.

---

## Neden?

Workgroup'taki (domain'siz) makineleri WMI/SMB ile yönetmek sorunludur. Bu sistem bunun yerine her PC'ye küçük bir **PowerShell ajanı** kurar; ajan **HTTPS (443)** ile panele bağlanır. Böylece:
- Şube / ev / mobil ağlardaki cihazlar da tek panelden görünür
- PC tarafında hiçbir gelen bağlantı / port açma gerekmez
- Tüm iletişim TLS + token ile kimliklendirilir

## Yetenekler

| Alan | Açıklama |
|---|---|
| 🖥️ **Donanım envanteri** | CPU, RAM (modül detayı), disk (SSD/HDD, doluluk), anakart, BIOS, ekran kartı, MAC/IP — CIM ile |
| 📦 **Yazılım envanteri** | Kurulu uygulamalar + sürüm (Registry Uninstall: HKLM 64/32 + oturumu açık kullanıcıların HKU) |
| ⚡ **Uzaktan komut** | Panelden PowerShell script'i → cihaz/gruba gönder → **SYSTEM** olarak çalışır; çıktı + çıkış kodu panelde |
| 🚀 **İlk kurulum otomasyonu** | Yeni PC'de tek `.cmd`: ajan + (isteğe bağlı) BGInfo, AnyDesk ID sıfırlama, bilgisayar adı standardı |
| 🔄 **Uzaktan ajan güncelleme** | Yeni ajan dosyasını SHA256 doğrulamalı indirip yeniden başlatma bayrağıyla — makineye gidilmeden |
| 🛡️ **Shadow Defender** | `CmdTool.exe` ile shadow modu aç/kapat/durum; shadow-modda güncelleme `/commit` ile kalıcı |
| 🔗 **Uzak masaüstü** | AnyDesk ID toplama + "Bağlan"; RustDesk self-host entegrasyonu |
| 📁 **Dosya deposu** | Script'lerin SHA256 doğrulamalı indirdiği paketler (ör. BGInfo ikilileri) |

## Mimari

**Pull modeli.** Ajan, panele bağlanan taraftır:

```
   [Windows PC]                         [Panel + API (PHP/MSSQL)]
   baslatici.ps1  ──çalıştırır──> ajan.ps1
        │                            │  her 60 sn:
        │ (SYSTEM görev)             ├── POST /api/ajan/nabiz   → envanter özeti, komutlar
        │                            ├── POST /api/ajan/envanter → tam liste (değişince)
        └── çöktüğünde yeniden       └── POST /api/ajan/komut-sonuc → çıktı + çıkış kodu
            başlatır (mutex)
```

- **İki katmanlı ajan:** `baslatici.ps1` küçük ve neredeyse değişmez (mutex + yeniden başlatma + güncelleme); `ajan.ps1` asıl koddur ve uzaktan güncellenir.
- **Cihaz kimliği:** `SHA256(anakart UUID | BIOS seri)`. Windows MachineGuid **kullanılmaz** (aynı imajdan sysprep'siz klonlanan PC'lerde çakışır). Fabrika varsayılanı UUID/seri değerleri kimlik sayılmaz.
- **Kayıt:** süreli + çok kullanımlık kayıt kodu (DB'de yalnız SHA256) → cihaza özel token (DB'de yalnız SHA256; cihazda DPAPI ile şifreli).
- **Envanter yalnız değişince gönderilir:** ajan hash yollar, sunucu farklıysa tam listeyi ister.
- **Komut kuyruğu:** nabız yanıtında sırayla en çok 5 komut; her komut ayrı `powershell.exe` sürecinde, zaman aşımıyla; sonuç ayrı uç noktayla bildirilir.

## Ajan API sözleşmesi

Kimlik: `X-Ajan-Guid` + `X-Ajan-Token` (SHA256). POST JSON, UTF-8.

| Uç nokta | İşlev |
|---|---|
| `kayit.php` | Kayıt kodu → cihaz GUID + token (geçersiz kod 403) |
| `nabiz.php` | Temel bilgi + envanter hash → aralık, istenen envanter, **komutlar[]** |
| `envanter.php` | Yazılım + donanım/disk tam listesi |
| `komut-sonuc.php` | Komutun durum/çıktı/çıkış kodu |
| `depo.php` | Dosya paketi (SHA256'lı) |
| `kur.php` / `dosya.php` | Ajan betiklerini sunar (`X-Sha256`) |

## Dış uygulama API'si

`api/uzak-yonetim.php`: kendi uygulamalarınızın (ör. personel takip masaüstü uygulamasının arka ucu) cihazları okuyup güncellemesi için **sunucudan sunucuya** uç nokta. Anahtar istemci uygulamaya gömülmez; arka uçta durur.

- Kimlik: `X-API-KEY` (DB'de yalnız SHA256), istemci bazında **yetki kapsamı** ve **izinli IP** listesi, hatalı anahtarda IP kilidi.
- Cihaz, ajanla aynı kuralla üretilen **donanım kimliğiyle** (anakart UUID + BIOS seri → SHA256) bulunur; istemci yalnız kendi bilgisayarının kaydına erişir.

| İşlem | Yetki | İşlev |
|---|---|---|
| `cihaz_getir` | `cihaz_oku` | Bilgisayar adı, açıklama, grup, AnyDesk/RustDesk ID, son görülme, çevrimiçi |
| `aciklama_guncelle` | `cihaz_aciklama` | Açıklamayı yazar (aynıysa yazmaz); önce/sonra denetim loguna |

## Güvenlik modeli

- **Panel ele geçerse tüm filo risk altındadır** — bu yüzden: panelde 2FA (TOTP) **önerilir**, ajan uç noktalarında IP bazlı hata kilidi, tüm işlemler denetim loguna.
- Token/kayıt kodu DB'de yalnız **SHA256**; cihazda token **DPAPI (LocalMachine)** ile şifreli.
- Şifreli ayarlar (AnyDesk/Shadow Defender şifreleri) **libsodium** ile; anahtar web kökü dışında.
- Komut parametrelerindeki sırlar loga yazılmaz; teslim anında şifreli taşınır.
- Ajan betikleri ve hassas uç noktalar web'den **403**; betikler SHA256 ile doğrulanır.

## Teknoloji

- **Sunucu:** PHP 8.3 (framework yok) + MSSQL (`sqlsrv`)
- **Ajan:** Windows PowerShell 5.1 (SYSTEM zamanlanmış görev)
- **Panel:** AdminLTE 4 (server-side DataTables)

## Dizin yapısı

```
ajan/                 PowerShell ajanı (kur, baslatici, ajan)
api/ajan/             Ajan API uç noktaları (PHP)
api/uzak-yonetim.php  Dış uygulama API'si (X-API-KEY)
admin/pages/          Panel sayfaları (cihazlar, detay, scriptler, dosya deposu, ayarlar...)
admin/includes/       UzakYonetim.php (DB + komut kuyruğu), Sifreleme.php (sodium)
admin/assets/         Sayfa JS/CSS
veritabani/sema.sql   MSSQL şeması (16 tablo)
```

## Notlar

- Bu, üretimde kullanılan bir sistemin maskeli referans kopyasıdır; alan adı/IP/firma bilgileri örnektir.
- Kendi ortamınızda kullanacaksanız: DB şifresini ve `sifreleme.key`'i siz üretir, panelde 2FA'yı açarsınız.
- **Plesk + IIS notu:** PowerShell 5.1 POST'a `Expect: 100-continue` ekler; ModSecurity (CRS 920450) bunu 403 yapabilir → ajanda `Expect100Continue = $false` zorunludur.
