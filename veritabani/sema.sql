-- ===================================================================
-- UZAK YÖNETİM (RMM) - VERİTABANI ŞEMASI
-- MSSQL. Bu modül bir PHP yönetim paneline entegre çalışır; kullanıcı/yetki
-- için host panelin dbo.kullanicilar tablosuna bağlanır (OlusturanKullanici=16 = sistem).
-- Tüm değerler örnektir (maskeli sürüm).
-- ===================================================================
-- ===================================================================
-- UZAK YÖNETİM - DESTEK DB'SİNE TAŞIMA ŞEMASI
-- Veritabanı: ornek_destek_DB (10.0.0.10\SQLEXPRESS)
-- Kaynak: uzakyonetim.ornekproje.com\config\proje.sql (UzakYonetim_DB)
-- Farklar:
--   - Kullanicilar / Sistem_Surum_Notlari yok → dbo.kullanicilar / Destek'in sürüm tablosu
--   - Ayarlar → UzakYonetimAyarlari, IslemLoglari → UzakYonetimLoglari
--   - Panel girişi Destek'e ait: oturum/giriş/şifre ayarları kaldırıldı, giris_kilit_dk → ajan_kilit_dk
-- Tekrar çalıştırılabilir: var olan tablo / indeks / kayıt atlanır.
-- ===================================================================

USE [ornek_destek_DB];
GO

-- ===================================================================
-- 1. AYARLAR
-- ===================================================================
IF OBJECT_ID(N'dbo.UzakYonetimAyarlari', N'U') IS NULL
CREATE TABLE dbo.UzakYonetimAyarlari (
    UzakYonetimAyarlari_id        INT IDENTITY(1,1) PRIMARY KEY,
    UzakYonetimAyarlari_Anahtar   NVARCHAR(100) NOT NULL,
    UzakYonetimAyarlari_Deger     NVARCHAR(MAX) NULL,
    UzakYonetimAyarlari_SifreliMi BIT NOT NULL DEFAULT 0,
    UzakYonetimAyarlari_Aciklama  NVARCHAR(500) NULL,
    OlusturanKullanici            INT NULL,
    OlusturmaTarihi               DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici          INT NULL,
    GuncellemeTarihi              DATETIME NULL,
    Durum                         BIT NOT NULL DEFAULT 1,
    CONSTRAINT UQ_UzakYonetimAyarlari_Anahtar UNIQUE (UzakYonetimAyarlari_Anahtar)
);
GO

-- ===================================================================
-- 2. TANIMLAR
-- ===================================================================
IF OBJECT_ID(N'dbo.CihazGruplari', N'U') IS NULL
CREATE TABLE dbo.CihazGruplari (
    CihazGruplari_id        INT IDENTITY(1,1) PRIMARY KEY,
    CihazGruplari_Ad        NVARCHAR(100) NOT NULL,
    CihazGruplari_Aciklama  NVARCHAR(500) NULL,
    OlusturanKullanici      INT NULL,
    OlusturmaTarihi         DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici    INT NULL,
    GuncellemeTarihi        DATETIME NULL,
    Durum                   BIT NOT NULL DEFAULT 1
);
GO

IF OBJECT_ID(N'dbo.Tanim_KomutDurumlari', N'U') IS NULL
CREATE TABLE dbo.Tanim_KomutDurumlari (
    Tanim_KomutDurumlari_id      INT IDENTITY(1,1) PRIMARY KEY,
    Tanim_KomutDurumlari_Kod     NVARCHAR(30) NOT NULL,
    Tanim_KomutDurumlari_Ad      NVARCHAR(50) NOT NULL,
    Tanim_KomutDurumlari_Renk    NVARCHAR(20) NULL,
    Tanim_KomutDurumlari_Sira    INT NOT NULL DEFAULT 0,
    Tanim_KomutDurumlari_BittiMi BIT NOT NULL DEFAULT 0,
    OlusturanKullanici           INT NULL,
    OlusturmaTarihi              DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici         INT NULL,
    GuncellemeTarihi             DATETIME NULL,
    Durum                        BIT NOT NULL DEFAULT 1,
    CONSTRAINT UQ_Tanim_KomutDurumlari_Kod UNIQUE (Tanim_KomutDurumlari_Kod)
);
GO

-- ===================================================================
-- 3. AJAN KAYIT KODLARI (çok kullanımlık / süreli, yalnız hash)
-- ===================================================================
IF OBJECT_ID(N'dbo.KayitKodlari', N'U') IS NULL
CREATE TABLE dbo.KayitKodlari (
    KayitKodlari_id                INT IDENTITY(1,1) PRIMARY KEY,
    KayitKodlari_KodHash           CHAR(64) NOT NULL,
    KayitKodlari_CihazGruplari_id  INT NULL REFERENCES dbo.CihazGruplari(CihazGruplari_id),
    KayitKodlari_SonKullanmaTarihi DATETIME NOT NULL,
    KayitKodlari_KullanimLimiti    INT NOT NULL DEFAULT 1,
    KayitKodlari_KullanimSayisi    INT NOT NULL DEFAULT 0,
    KayitKodlari_Aciklama          NVARCHAR(500) NULL,
    OlusturanKullanici             INT NULL,
    OlusturmaTarihi                DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici           INT NULL,
    GuncellemeTarihi               DATETIME NULL,
    Durum                          BIT NOT NULL DEFAULT 1,
    CONSTRAINT UQ_KayitKodlari_KodHash UNIQUE (KayitKodlari_KodHash)
);
GO

-- ===================================================================
-- 4. CİHAZLAR
-- ===================================================================
IF OBJECT_ID(N'dbo.Cihazlar', N'U') IS NULL
CREATE TABLE dbo.Cihazlar (
    Cihazlar_id               INT IDENTITY(1,1) PRIMARY KEY,
    Cihazlar_Guid             UNIQUEIDENTIFIER NOT NULL,
    Cihazlar_TokenHash        CHAR(64) NOT NULL,
    Cihazlar_DonanimKimlik    CHAR(64) NULL,                       -- SHA256(anakart UUID|BIOS seri)
    Cihazlar_CihazGruplari_id INT NULL REFERENCES dbo.CihazGruplari(CihazGruplari_id),
    Cihazlar_BilgisayarAdi    NVARCHAR(100) NOT NULL,
    Cihazlar_Aciklama         NVARCHAR(500) NULL,
    Cihazlar_IsletimSistemi   NVARCHAR(150) NULL,
    Cihazlar_OsSurum          NVARCHAR(20) NULL,
    Cihazlar_OsDerleme        NVARCHAR(30) NULL,
    Cihazlar_AktifKullanici   NVARCHAR(150) NULL,
    Cihazlar_IcIp             NVARCHAR(45) NULL,
    Cihazlar_DisIp            NVARCHAR(45) NULL,
    Cihazlar_MacAdres         NVARCHAR(17) NULL,
    Cihazlar_AjanSurum        NVARCHAR(20) NULL,
    Cihazlar_PilotMu          BIT NOT NULL DEFAULT 0,
    Cihazlar_SonGorulme       DATETIME NULL,
    Cihazlar_SonAcilis        DATETIME NULL,
    Cihazlar_YazilimHash      CHAR(64) NULL,
    Cihazlar_DonanimHash      CHAR(64) NULL,
    Cihazlar_RustDeskId       NVARCHAR(20) NULL,
    Cihazlar_RustDeskSifre    NVARCHAR(500) NULL,                  -- şifreli
    OlusturanKullanici        INT NULL,
    OlusturmaTarihi           DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici      INT NULL,
    GuncellemeTarihi          DATETIME NULL,
    Durum                     BIT NOT NULL DEFAULT 1,
    CONSTRAINT UQ_Cihazlar_Guid UNIQUE (Cihazlar_Guid)
);
GO

IF OBJECT_ID(N'dbo.CihazDonanim', N'U') IS NULL
CREATE TABLE dbo.CihazDonanim (
    CihazDonanim_id             INT IDENTITY(1,1) PRIMARY KEY,
    CihazDonanim_Cihazlar_id    INT NOT NULL REFERENCES dbo.Cihazlar(Cihazlar_id),
    CihazDonanim_Uretici        NVARCHAR(100) NULL,
    CihazDonanim_Model          NVARCHAR(150) NULL,
    CihazDonanim_SeriNo         NVARCHAR(100) NULL,
    CihazDonanim_Islemci        NVARCHAR(200) NULL,
    CihazDonanim_CekirdekSayisi INT NULL,
    CihazDonanim_RamGb          DECIMAL(6,1) NULL,
    CihazDonanim_RamDetay       NVARCHAR(500) NULL,
    CihazDonanim_Anakart        NVARCHAR(200) NULL,
    CihazDonanim_BiosSurum      NVARCHAR(100) NULL,
    CihazDonanim_EkranKarti     NVARCHAR(300) NULL,
    OlusturanKullanici          INT NULL,
    OlusturmaTarihi             DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici        INT NULL,
    GuncellemeTarihi            DATETIME NULL,
    Durum                       BIT NOT NULL DEFAULT 1,
    CONSTRAINT UQ_CihazDonanim_Cihaz UNIQUE (CihazDonanim_Cihazlar_id)
);
GO

IF OBJECT_ID(N'dbo.CihazDiskleri', N'U') IS NULL
CREATE TABLE dbo.CihazDiskleri (
    CihazDiskleri_id          INT IDENTITY(1,1) PRIMARY KEY,
    CihazDiskleri_Cihazlar_id INT NOT NULL REFERENCES dbo.Cihazlar(Cihazlar_id),
    CihazDiskleri_Surucu      NVARCHAR(5) NULL,
    CihazDiskleri_Model       NVARCHAR(200) NULL,
    CihazDiskleri_Tip         NVARCHAR(20) NULL,
    CihazDiskleri_ToplamGb    DECIMAL(10,1) NULL,
    CihazDiskleri_BosGb       DECIMAL(10,1) NULL,
    OlusturanKullanici        INT NULL,
    OlusturmaTarihi           DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici      INT NULL,
    GuncellemeTarihi          DATETIME NULL,
    Durum                     BIT NOT NULL DEFAULT 1
);
GO

IF OBJECT_ID(N'dbo.CihazYazilimlari', N'U') IS NULL
CREATE TABLE dbo.CihazYazilimlari (
    CihazYazilimlari_id             INT IDENTITY(1,1) PRIMARY KEY,
    CihazYazilimlari_Cihazlar_id    INT NOT NULL REFERENCES dbo.Cihazlar(Cihazlar_id),
    CihazYazilimlari_Ad             NVARCHAR(300) NOT NULL,
    CihazYazilimlari_Surum          NVARCHAR(100) NULL,
    CihazYazilimlari_Yayinci        NVARCHAR(200) NULL,
    CihazYazilimlari_KurulumTarihi  DATE NULL,
    CihazYazilimlari_Mimari         NVARCHAR(10) NULL,
    CihazYazilimlari_KaldirmaKomutu NVARCHAR(1000) NULL,
    OlusturanKullanici              INT NULL,
    OlusturmaTarihi                 DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici            INT NULL,
    GuncellemeTarihi                DATETIME NULL,
    Durum                           BIT NOT NULL DEFAULT 1
);
GO

-- ===================================================================
-- 4b. CİHAZ ETİKETLERİ (cihaz ↔ etiket çok-çok)
-- ===================================================================
IF OBJECT_ID(N'dbo.Etiketler', N'U') IS NULL
CREATE TABLE dbo.Etiketler (
    Etiketler_id         INT IDENTITY(1,1) PRIMARY KEY,
    Etiketler_Ad         NVARCHAR(50)  NOT NULL,
    Etiketler_Renk       VARCHAR(7)    NOT NULL DEFAULT '#6c757d',
    Etiketler_Aciklama   NVARCHAR(250) NULL,
    OlusturanKullanici   INT NULL,
    OlusturmaTarihi      DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici INT NULL,
    GuncellemeTarihi     DATETIME NULL,
    Durum                BIT NOT NULL DEFAULT 1,
    CONSTRAINT UQ_Etiketler_Ad UNIQUE (Etiketler_Ad)
);
GO

IF OBJECT_ID(N'dbo.CihazEtiketleri', N'U') IS NULL
CREATE TABLE dbo.CihazEtiketleri (
    CihazEtiketleri_id           INT IDENTITY(1,1) PRIMARY KEY,
    CihazEtiketleri_Cihazlar_id  INT NOT NULL REFERENCES dbo.Cihazlar(Cihazlar_id),
    CihazEtiketleri_Etiketler_id INT NOT NULL REFERENCES dbo.Etiketler(Etiketler_id),
    OlusturanKullanici   INT NULL,
    OlusturmaTarihi      DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici INT NULL,
    GuncellemeTarihi     DATETIME NULL,
    Durum                BIT NOT NULL DEFAULT 1,
    CONSTRAINT UQ_CihazEtiketleri UNIQUE (CihazEtiketleri_Cihazlar_id, CihazEtiketleri_Etiketler_id)
);
GO

-- ===================================================================
-- 4c. DIŞ UYGULAMA API İSTEMCİLERİ (api/uzak-yonetim.php; anahtar yalnız SHA256)
-- ===================================================================
IF OBJECT_ID(N'dbo.UzakApiIstemcileri', N'U') IS NULL
CREATE TABLE dbo.UzakApiIstemcileri (
    UzakApiIstemcileri_id          INT IDENTITY(1,1) PRIMARY KEY,
    UzakApiIstemcileri_Ad          NVARCHAR(100) NOT NULL,
    UzakApiIstemcileri_AnahtarHash CHAR(64)      NOT NULL,
    UzakApiIstemcileri_Yetkiler    NVARCHAR(200) NOT NULL,   -- virgüllü: cihaz_oku,cihaz_aciklama
    UzakApiIstemcileri_IzinliIpler NVARCHAR(500) NULL,       -- virgüllü; NULL = kısıtsız
    UzakApiIstemcileri_SonKullanim DATETIME      NULL,
    UzakApiIstemcileri_SonIp       VARCHAR(50)   NULL,
    OlusturanKullanici   INT NULL,
    OlusturmaTarihi      DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici INT NULL,
    GuncellemeTarihi     DATETIME NULL,
    Durum                BIT NOT NULL DEFAULT 1
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_UzakApiIstemcileri_AnahtarHash')
    CREATE UNIQUE INDEX UX_UzakApiIstemcileri_AnahtarHash ON dbo.UzakApiIstemcileri (UzakApiIstemcileri_AnahtarHash);
GO
-- İstemci eklemek (anahtar bir kez gösterilir, tabloya yalnız özeti yazılır):
-- DECLARE @anahtar VARCHAR(64) = LOWER(CONVERT(VARCHAR(64), CRYPT_GEN_RANDOM(32), 2));
-- INSERT INTO dbo.UzakApiIstemcileri (UzakApiIstemcileri_Ad, UzakApiIstemcileri_AnahtarHash, UzakApiIstemcileri_Yetkiler, UzakApiIstemcileri_IzinliIpler)
-- VALUES (N'Örnek istemci', LOWER(CONVERT(VARCHAR(64), HASHBYTES('SHA2_256', @anahtar), 2)), N'cihaz_oku,cihaz_aciklama', N'127.0.0.1');
-- SELECT @anahtar AS ApiAnahtari_BirKezGoster;

-- ===================================================================
-- 5. SCRIPT KÜTÜPHANESİ VE KOMUTLAR
-- ===================================================================
IF OBJECT_ID(N'dbo.Scriptler', N'U') IS NULL
CREATE TABLE dbo.Scriptler (
    Scriptler_id           INT IDENTITY(1,1) PRIMARY KEY,
    Scriptler_Ad           NVARCHAR(150) NOT NULL,
    Scriptler_Aciklama     NVARCHAR(1000) NULL,
    Scriptler_Icerik       NVARCHAR(MAX) NOT NULL,
    Scriptler_Parametreler NVARCHAR(MAX) NULL,                     -- JSON: [{ad, etiket, tip, ayarAnahtari}]
    Scriptler_ZamanAsimiSn INT NOT NULL DEFAULT 300,
    OlusturanKullanici     INT NULL,
    OlusturmaTarihi        DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici   INT NULL,
    GuncellemeTarihi       DATETIME NULL,
    Durum                  BIT NOT NULL DEFAULT 1
);
GO

IF OBJECT_ID(N'dbo.Komutlar', N'U') IS NULL
CREATE TABLE dbo.Komutlar (
    Komutlar_id            INT IDENTITY(1,1) PRIMARY KEY,
    Komutlar_Scriptler_id  INT NULL REFERENCES dbo.Scriptler(Scriptler_id),
    Komutlar_Baslik        NVARCHAR(200) NOT NULL,
    Komutlar_Icerik        NVARCHAR(MAX) NOT NULL,
    Komutlar_Parametreler  NVARCHAR(MAX) NULL,                     -- şifreli JSON
    Komutlar_ZamanAsimiSn  INT NOT NULL DEFAULT 300,
    Komutlar_SonGecerlilik DATETIME NOT NULL,
    OlusturanKullanici     INT NULL,
    OlusturmaTarihi        DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici   INT NULL,
    GuncellemeTarihi       DATETIME NULL,
    Durum                  BIT NOT NULL DEFAULT 1
);
GO

IF OBJECT_ID(N'dbo.KomutHedefleri', N'U') IS NULL
CREATE TABLE dbo.KomutHedefleri (
    KomutHedefleri_id                      INT IDENTITY(1,1) PRIMARY KEY,
    KomutHedefleri_Komutlar_id             INT NOT NULL REFERENCES dbo.Komutlar(Komutlar_id),
    KomutHedefleri_Cihazlar_id             INT NOT NULL REFERENCES dbo.Cihazlar(Cihazlar_id),
    KomutHedefleri_Tanim_KomutDurumlari_id INT NOT NULL REFERENCES dbo.Tanim_KomutDurumlari(Tanim_KomutDurumlari_id),
    KomutHedefleri_AlinmaTarihi            DATETIME NULL,
    KomutHedefleri_BaslamaTarihi           DATETIME NULL,
    KomutHedefleri_BitisTarihi             DATETIME NULL,
    KomutHedefleri_CikisKodu               INT NULL,
    KomutHedefleri_Cikti                   NVARCHAR(MAX) NULL,
    KomutHedefleri_Hata                    NVARCHAR(MAX) NULL,
    OlusturanKullanici                     INT NULL,
    OlusturmaTarihi                        DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici                   INT NULL,
    GuncellemeTarihi                       DATETIME NULL,
    Durum                                  BIT NOT NULL DEFAULT 1
);
GO

-- ===================================================================
-- 6. AJAN SÜRÜMLERİ
-- ===================================================================
IF OBJECT_ID(N'dbo.AjanSurumleri', N'U') IS NULL
CREATE TABLE dbo.AjanSurumleri (
    AjanSurumleri_id        INT IDENTITY(1,1) PRIMARY KEY,
    AjanSurumleri_Surum     NVARCHAR(20) NOT NULL,
    AjanSurumleri_DosyaYolu NVARCHAR(500) NOT NULL,
    AjanSurumleri_Sha256    CHAR(64) NOT NULL,
    AjanSurumleri_Imza      NVARCHAR(500) NOT NULL,                -- Ed25519 (base64)
    AjanSurumleri_Kanal     NVARCHAR(10) NOT NULL DEFAULT 'pilot', -- pilot / genel
    AjanSurumleri_Notlar    NVARCHAR(MAX) NULL,
    OlusturanKullanici      INT NULL,
    OlusturmaTarihi         DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici    INT NULL,
    GuncellemeTarihi        DATETIME NULL,
    Durum                   BIT NOT NULL DEFAULT 1,
    CONSTRAINT UQ_AjanSurumleri_Surum UNIQUE (AjanSurumleri_Surum)
);
GO

-- ===================================================================
-- 7. İŞLEM LOGLARI (denetim kaydı) — kullanıcı Destek'in kullanicilar tablosundan
-- ===================================================================
IF OBJECT_ID(N'dbo.UzakYonetimLoglari', N'U') IS NULL
CREATE TABLE dbo.UzakYonetimLoglari (
    UzakYonetimLoglari_id          INT IDENTITY(1,1) PRIMARY KEY,
    UzakYonetimLoglari_kullanici_id INT NULL REFERENCES dbo.kullanicilar(kullanici_id),
    UzakYonetimLoglari_Cihazlar_id INT NULL REFERENCES dbo.Cihazlar(Cihazlar_id),
    UzakYonetimLoglari_Islem       NVARCHAR(100) NOT NULL,
    UzakYonetimLoglari_Detay       NVARCHAR(MAX) NULL,
    UzakYonetimLoglari_Ip          NVARCHAR(45) NULL,
    OlusturanKullanici             INT NULL,
    OlusturmaTarihi                DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici           INT NULL,
    GuncellemeTarihi               DATETIME NULL,
    Durum                          BIT NOT NULL DEFAULT 1
);
GO

-- ===================================================================
-- 8. İNDEKSLER
-- ===================================================================
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_Cihazlar_SonGorulme')
    CREATE INDEX IX_Cihazlar_SonGorulme ON dbo.Cihazlar (Cihazlar_SonGorulme DESC) INCLUDE (Cihazlar_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_Cihazlar_CihazGruplari')
    CREATE INDEX IX_Cihazlar_CihazGruplari ON dbo.Cihazlar (Cihazlar_CihazGruplari_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_CihazEtiketleri_Etiket')
    CREATE INDEX IX_CihazEtiketleri_Etiket ON dbo.CihazEtiketleri (CihazEtiketleri_Etiketler_id) INCLUDE (CihazEtiketleri_Cihazlar_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_Cihazlar_DonanimKimlik')
    CREATE UNIQUE INDEX UX_Cihazlar_DonanimKimlik ON dbo.Cihazlar (Cihazlar_DonanimKimlik) WHERE Cihazlar_DonanimKimlik IS NOT NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_CihazDiskleri_Cihaz')
    CREATE INDEX IX_CihazDiskleri_Cihaz ON dbo.CihazDiskleri (CihazDiskleri_Cihazlar_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_CihazYazilimlari_Cihaz')
    CREATE INDEX IX_CihazYazilimlari_Cihaz ON dbo.CihazYazilimlari (CihazYazilimlari_Cihazlar_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_CihazYazilimlari_AdSurum')
    CREATE INDEX IX_CihazYazilimlari_AdSurum ON dbo.CihazYazilimlari (CihazYazilimlari_Ad, CihazYazilimlari_Surum) INCLUDE (CihazYazilimlari_Cihazlar_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_Komutlar_OlusturmaTarihi')
    CREATE INDEX IX_Komutlar_OlusturmaTarihi ON dbo.Komutlar (OlusturmaTarihi DESC) INCLUDE (Komutlar_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_Komutlar_Script')
    CREATE INDEX IX_Komutlar_Script ON dbo.Komutlar (Komutlar_Scriptler_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_KomutHedefleri_Komut')
    CREATE INDEX IX_KomutHedefleri_Komut ON dbo.KomutHedefleri (KomutHedefleri_Komutlar_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_KomutHedefleri_CihazDurum')
    CREATE INDEX IX_KomutHedefleri_CihazDurum ON dbo.KomutHedefleri (KomutHedefleri_Cihazlar_id, KomutHedefleri_Tanim_KomutDurumlari_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_KomutHedefleri_Durum')
    CREATE INDEX IX_KomutHedefleri_Durum ON dbo.KomutHedefleri (KomutHedefleri_Tanim_KomutDurumlari_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_KayitKodlari_Grup')
    CREATE INDEX IX_KayitKodlari_Grup ON dbo.KayitKodlari (KayitKodlari_CihazGruplari_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_UzakYonetimLoglari_OlusturmaTarihi')
    CREATE INDEX IX_UzakYonetimLoglari_OlusturmaTarihi ON dbo.UzakYonetimLoglari (OlusturmaTarihi DESC) INCLUDE (UzakYonetimLoglari_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_UzakYonetimLoglari_Kullanici')
    CREATE INDEX IX_UzakYonetimLoglari_Kullanici ON dbo.UzakYonetimLoglari (UzakYonetimLoglari_kullanici_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_UzakYonetimLoglari_Cihaz')
    CREATE INDEX IX_UzakYonetimLoglari_Cihaz ON dbo.UzakYonetimLoglari (UzakYonetimLoglari_Cihazlar_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_UzakYonetimLoglari_IslemIpTarih')
    CREATE INDEX IX_UzakYonetimLoglari_IslemIpTarih ON dbo.UzakYonetimLoglari (UzakYonetimLoglari_Islem, UzakYonetimLoglari_Ip, OlusturmaTarihi);
GO

-- ===================================================================
-- 9. BAŞLANGIÇ VERİLERİ (sistem kullanıcısı: kullanici_id = 16)
-- ===================================================================
INSERT INTO dbo.Tanim_KomutDurumlari
    (Tanim_KomutDurumlari_Kod, Tanim_KomutDurumlari_Ad, Tanim_KomutDurumlari_Renk, Tanim_KomutDurumlari_Sira, Tanim_KomutDurumlari_BittiMi, OlusturanKullanici)
SELECT y.Kod, y.Ad, y.Renk, y.Sira, y.BittiMi, 16
FROM (VALUES
    (N'bekliyor',    N'Bekliyor',     N'secondary', 1, 0),
    (N'alindi',      N'Alındı',       N'info',      2, 0),
    (N'calisiyor',   N'Çalışıyor',    N'primary',   3, 0),
    (N'basarili',    N'Başarılı',     N'success',   4, 1),
    (N'hatali',      N'Hatalı',       N'danger',    5, 1),
    (N'zamanasimi',  N'Zaman Aşımı',  N'warning',   6, 1),
    (N'suresidoldu', N'Süresi Doldu', N'dark',      7, 1),
    (N'iptal',       N'İptal',        N'light',     8, 1)
) y (Kod, Ad, Renk, Sira, BittiMi)
WHERE NOT EXISTS (SELECT 1 FROM dbo.Tanim_KomutDurumlari t WHERE t.Tanim_KomutDurumlari_Kod = y.Kod);

IF NOT EXISTS (SELECT 1 FROM dbo.CihazGruplari)
    INSERT INTO dbo.CihazGruplari (CihazGruplari_Ad, CihazGruplari_Aciklama, OlusturanKullanici)
    VALUES (N'Genel', N'Varsayılan grup', 16);

INSERT INTO dbo.UzakYonetimAyarlari
    (UzakYonetimAyarlari_Anahtar, UzakYonetimAyarlari_Deger, UzakYonetimAyarlari_SifreliMi, UzakYonetimAyarlari_Aciklama, OlusturanKullanici)
SELECT y.Anahtar, y.Deger, y.SifreliMi, y.Aciklama, 16
FROM (VALUES
    (N'ajan_sorgu_araligi_sn',            N'60',   0, N'Ajanın panele bağlanma aralığı (saniye)'),
    (N'cihaz_cevrimdisi_dk',              N'5',    0, N'Bu süre görülmeyen cihaz çevrimdışı sayılır'),
    (N'komut_varsayilan_gecerlilik_saat', N'24',   0, N'Komutun çalıştırılabileceği azami süre'),
    (N'ajan_hata_limiti',                 N'20',   0, N'Ajan uç noktalarında IP başına izin verilen hatalı istek (ajan_kilit_dk penceresinde)'),
    (N'ajan_kilit_dk',                    N'15',   0, N'Ajan hata sayımının yapıldığı / kilidin süreceği pencere (dakika)'),
    (N'donanim_kimlik_gecersiz',          N'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF|00000000-0000-0000-0000-000000000000|03000200-0400-0500-0006-000700080009|TO BE FILLED BY O.E.M.|DEFAULT STRING|SYSTEM SERIAL NUMBER|NONE|0|123456789|NOT APPLICABLE',
                                                   0, N'Donanım kimliği sayılmayan anakart UUID / BIOS seri değerleri (| ile ayrılmış)'),
    (N'kayit_kodu_varsayilan_gun',        N'30',   0, N'Yeni kayıt kodunun varsayılan geçerlilik süresi (gün)'),
    (N'kayit_kodu_varsayilan_limit',      N'50',   0, N'Yeni kayıt kodunun varsayılan kullanım limiti'),
    (N'kayit_kodu_azami_gun',             N'365',  0, N'Kayıt kodu geçerliliği üst sınırı (gün)'),
    (N'kayit_kodu_azami_limit',           N'1000', 0, N'Kayıt kodu kullanım limiti üst sınırı'),
    (N'envanter_kontrol_dk',              N'15',   0, N'Ajanın envanter özetini yeniden hesaplama aralığı (dakika)'),
    (N'disk_bos_adim_gb',                 N'5',    0, N'Boş disk alanı envanter özetine bu adımla yuvarlanarak girer (GB)'),
    (N'rustdesk_sunucu',                  N'uzakyonetim.ornekproje.com', 0, N'RustDesk ID/Relay sunucu adresi'),
    (N'rustdesk_anahtar',                 N'ORNEK_RUSTDESK_ACIK_ANAHTAR_BASE64=', 0, N'RustDesk sunucu açık anahtarı (id_ed25519.pub)'),
    (N'shadowdefender_sifre',             NULL,    1, N'Shadow Defender yönetim şifresi'),
    (N'ajan_imza_acik_anahtar',           NULL,    0, N'Ajan güncelleme imzası doğrulama anahtarı')
) y (Anahtar, Deger, SifreliMi, Aciklama)
WHERE NOT EXISTS (SELECT 1 FROM dbo.UzakYonetimAyarlari a WHERE a.UzakYonetimAyarlari_Anahtar = y.Anahtar);
GO

-- ===================================================================
-- 10. KONTROL
-- ===================================================================
SELECT N'Tablo' AS Tur, COUNT(*) AS Adet FROM sys.tables
WHERE name IN (N'UzakYonetimAyarlari', N'CihazGruplari', N'Tanim_KomutDurumlari', N'KayitKodlari', N'Cihazlar', N'CihazDonanim',
               N'CihazDiskleri', N'CihazYazilimlari', N'Scriptler', N'Komutlar', N'KomutHedefleri', N'AjanSurumleri', N'UzakYonetimLoglari',
               N'Etiketler', N'CihazEtiketleri', N'UzakApiIstemcileri')
UNION ALL SELECT N'Komut durumu', COUNT(*) FROM dbo.Tanim_KomutDurumlari
UNION ALL SELECT N'Grup',         COUNT(*) FROM dbo.CihazGruplari
UNION ALL SELECT N'Ayar',         COUNT(*) FROM dbo.UzakYonetimAyarlari;


-- ===================================================================
-- SONRAKİ EKLEMELER (migrasyonlar)
-- ===================================================================
IF COL_LENGTH('dbo.KayitKodlari', 'KayitKodlari_KodSifreli') IS NULL
    ALTER TABLE dbo.KayitKodlari ADD KayitKodlari_KodSifreli NVARCHAR(500) NULL;  -- sodium ile şifreli kod (panelden .cmd indirmek için)
GO
IF COL_LENGTH('dbo.Cihazlar', 'Cihazlar_AnyDeskId') IS NULL
    ALTER TABLE dbo.Cihazlar ADD Cihazlar_AnyDeskId NVARCHAR(20) NULL;            -- ajan nabızda gönderir
GO
-- ===================================================================
-- UZAK YÖNETİM - B1: ilk kurulum listesi, dosya deposu, ayarlar
-- Veritabanı: ornek_destek_DB (10.0.0.10\SQLEXPRESS)
-- Tekrar çalıştırılabilir.
-- ===================================================================
USE [ornek_destek_DB];
GO

-- 1. Script'in ilk kurulumda çalışma sırası (NULL = ilk kurulumda çalışmaz)
IF COL_LENGTH('dbo.Scriptler', 'Scriptler_IlkKurulumSira') IS NULL
    ALTER TABLE dbo.Scriptler ADD Scriptler_IlkKurulumSira INT NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_Scriptler_IlkKurulumSira')
    CREATE INDEX IX_Scriptler_IlkKurulumSira ON dbo.Scriptler (Scriptler_IlkKurulumSira)
    WHERE Scriptler_IlkKurulumSira IS NOT NULL;
GO

-- 2. Dosya deposu (dosya diskte ajan/dosyalar/<sha256>, web'den 403)
IF OBJECT_ID(N'dbo.UzakDosyalar', N'U') IS NULL
CREATE TABLE dbo.UzakDosyalar (
    UzakDosyalar_id        INT IDENTITY(1,1) PRIMARY KEY,
    UzakDosyalar_Paket     NVARCHAR(100) NOT NULL,              -- ör. BGInfo; script paket adıyla indirir
    UzakDosyalar_Ad        NVARCHAR(200) NOT NULL,              -- cihaza yazılacak dosya adı
    UzakDosyalar_Sha256    CHAR(64) NOT NULL,
    UzakDosyalar_Boyut     BIGINT NOT NULL,
    UzakDosyalar_Aciklama  NVARCHAR(500) NULL,
    OlusturanKullanici     INT NULL,
    OlusturmaTarihi        DATETIME NOT NULL DEFAULT GETDATE(),
    GuncelleyenKullanici   INT NULL,
    GuncellemeTarihi       DATETIME NULL,
    Durum                  BIT NOT NULL DEFAULT 1
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_UzakDosyalar_PaketAd')
    CREATE UNIQUE INDEX UX_UzakDosyalar_PaketAd ON dbo.UzakDosyalar (UzakDosyalar_Paket, UzakDosyalar_Ad) WHERE Durum = 1;
GO

-- 3. Ayarlar
INSERT INTO dbo.UzakYonetimAyarlari
    (UzakYonetimAyarlari_Anahtar, UzakYonetimAyarlari_Deger, UzakYonetimAyarlari_SifreliMi, UzakYonetimAyarlari_Aciklama, OlusturanKullanici)
SELECT y.Anahtar, y.Deger, y.SifreliMi, y.Aciklama, 16
FROM (VALUES
    (N'anydesk_sifre',        NULL,   1, N'AnyDesk katılımsız erişim şifresi (ilk kurulum script''i kullanır)'),
    (N'komut_cikti_azami_kb', N'1024', 0, N'Ajanın gönderdiği komut çıktısı bu boyutta kırpılır (KB)'),
    (N'dosya_azami_mb',       N'50',  0, N'Dosya deposuna yüklenebilecek azami dosya boyutu (MB)')
) y (Anahtar, Deger, SifreliMi, Aciklama)
WHERE NOT EXISTS (SELECT 1 FROM dbo.UzakYonetimAyarlari a WHERE a.UzakYonetimAyarlari_Anahtar = y.Anahtar);
GO

-- 4. Kontrol
SELECT N'Scriptler_IlkKurulumSira' AS Oge, CASE WHEN COL_LENGTH('dbo.Scriptler', 'Scriptler_IlkKurulumSira') IS NULL THEN 0 ELSE 1 END AS Var
UNION ALL SELECT N'UzakDosyalar', CASE WHEN OBJECT_ID(N'dbo.UzakDosyalar', N'U') IS NULL THEN 0 ELSE 1 END
UNION ALL SELECT N'Yeni ayar', COUNT(*) FROM dbo.UzakYonetimAyarlari
          WHERE UzakYonetimAyarlari_Anahtar IN (N'anydesk_sifre', N'komut_cikti_azami_kb', N'dosya_azami_mb');
