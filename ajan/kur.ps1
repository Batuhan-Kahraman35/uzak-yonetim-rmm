#Requires -Version 5.1
<#
    Uzak Yonetim - ajan kurulum betigi
    Sunucudan indirilip calistirilir (api/ajan/kur.php). Yonetici olarak calismalidir.

    Kullanim:
      & ([scriptblock]::Create((irm https://<sunucu>/api/ajan/kur.php))) -KayitKodu 'XXXX-XXXX-XXXX-XXXX'
      & ([scriptblock]::Create((irm ...))) -KayitKoduDosya '\\Ornek-PC\Batch\UzakYonetim\kayit_kodu.txt'
      & ([scriptblock]::Create((irm ...))) -Kaldir

    Adimlar:
      1) C:\ProgramData\UzakYonetim klasoru (yalniz SYSTEM + Administrators)
      2) Mevcut kimlik bu donanima aitse ve sunucu kabul ediyorsa kayit atlanir
         (bat tekrar calistirilirsa kod bosuna harcanmaz). Aksi halde kayit olunur.
         Klon imajdan gelen kimlik dosyasi donanim uyusmadigi icin yenilenir.
      3) baslatici.ps1 + ajan.ps1 indirilir, SHA256 dogrulanir
      4) "UzakYonetim Ajan" zamanlanmis gorevi (SYSTEM) kurulur ve baslatilir
      5) Ilk nabiz log'da gorulene kadar beklenir
    Hata olursa istisna firlatir (powershell -Command altinda cikis kodu 1)
#>
param(
    [string]$KayitKodu,
    [string]$KayitKoduDosya,
    [string]$Sunucu = '__UZAK_YONETIM_SUNUCU__',   # kur.php indirme adresiyle doldurur
    [switch]$Kaldir,
    [switch]$IlkKurulum                            # kurulumdan sonra ilk kurulum script'lerini kuyruga alir
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
# .NET POST isteklerine "Expect: 100-continue" ekler; Plesk ModSecurity (CRS 920450) bu baslik yuzunden 403 doner
[Net.ServicePointManager]::Expect100Continue = $false
Add-Type -AssemblyName System.Security

$Kok      = Join-Path $env:ProgramData 'UzakYonetim'
$GorevAdi = 'UzakYonetim Ajan'

function Yaz([string]$Mesaj) { Write-Output ('[UzakYonetim] ' + $Mesaj) }

function Stop-Ajan {
    $gorev = Get-ScheduledTask -TaskName $GorevAdi -ErrorAction SilentlyContinue
    if ($gorev) { Stop-ScheduledTask -TaskName $GorevAdi -ErrorAction SilentlyContinue }
    Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" |
        Where-Object { $_.ProcessId -ne $PID -and $_.CommandLine -like "*$Kok*" } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}

function Get-DonanimKimlik {
    $uuid = [string](Get-CimInstance Win32_ComputerSystemProduct).UUID
    $seri = [string](Get-CimInstance Win32_BIOS).SerialNumber
    return @{ uuid = $uuid.Trim(); seri = $seri.Trim() }
}

function Invoke-Api([string]$Yol, $Govde, [hashtable]$Basliklar = @{}) {
    $json = ConvertTo-Json -InputObject $Govde -Depth 6 -Compress
    try {
        $cevap = Invoke-RestMethod -Uri "$Sunucu/api/ajan/$Yol" -Method Post -UseBasicParsing -TimeoutSec 60 `
            -Body ([Text.Encoding]::UTF8.GetBytes($json)) -ContentType 'application/json; charset=utf-8' -Headers $Basliklar
        return @{ Kod = 200; Veri = $cevap }
    } catch {
        $kod = 0
        if ($_.Exception.Response) { $kod = [int]$_.Exception.Response.StatusCode }
        $mesaj = $_.ErrorDetails.Message
        if (-not $mesaj) { $mesaj = $_.Exception.Message }
        return @{ Kod = $kod; Hata = $mesaj }
    }
}

function Save-Dosya([string]$Ad, [hashtable]$Basliklar) {
    $wc = New-Object Net.WebClient
    foreach ($b in $Basliklar.Keys) { $wc.Headers.Add($b, $Basliklar[$b]) }
    $bayt = $wc.DownloadData("$Sunucu/api/ajan/dosya.php?ad=$Ad")
    $beklenen = [string]$wc.ResponseHeaders['X-Sha256']
    $sha = [Security.Cryptography.SHA256]::Create()
    $gercek = -join ($sha.ComputeHash($bayt) | ForEach-Object { $_.ToString('x2') })
    $sha.Dispose()
    if (-not $beklenen -or $gercek -ne $beklenen.ToLower()) {
        throw "$Ad dogrulanamadi (SHA256 uyusmuyor)"
    }
    [IO.File]::WriteAllBytes((Join-Path $Kok $Ad), $bayt)
}

try {
    # --- Yetki ----------------------------------------------------------------
    $kimlikBen = [Security.Principal.WindowsIdentity]::GetCurrent()
    $yonetici = (New-Object Security.Principal.WindowsPrincipal $kimlikBen).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    if (-not $yonetici -and $kimlikBen.User.Value -ne 'S-1-5-18') {
        throw 'Yonetici olarak calistirilmali.'
    }

    # --- Kaldirma -------------------------------------------------------------
    if ($Kaldir) {
        Stop-Ajan
        Unregister-ScheduledTask -TaskName $GorevAdi -Confirm:$false -ErrorAction SilentlyContinue
        if (Test-Path $Kok) { Remove-Item $Kok -Recurse -Force }
        Yaz 'Ajan kaldirildi. (Cihaz kaydi panelde durur, gerekirse panelden silin.)'
        return
    }

    # --- Kayit kodu -----------------------------------------------------------
    if (-not $KayitKodu -and $KayitKoduDosya) {
        if (-not (Test-Path -LiteralPath $KayitKoduDosya)) { throw "Kayit kodu dosyasi yok: $KayitKoduDosya" }
        $KayitKodu = (Get-Content -LiteralPath $KayitKoduDosya -TotalCount 1).Trim()
    }

    # --- Klasor + yetkiler ----------------------------------------------------
    New-Item -ItemType Directory -Path $Kok -Force | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $Kok 'log') -Force | Out-Null
    # SID'ler kullanilir (Turkce Windows'ta grup adlari farkli). Devralma kapatilir: kullanicilar goremez.
    & icacls.exe $Kok /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' /Q | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Klasor yetkileri ayarlanamadi.' }

    Stop-Ajan

    $ayarDosya   = Join-Path $Kok 'ayar.json'
    $kimlikDosya = Join-Path $Kok 'kimlik.json'
    ConvertTo-Json -InputObject @{ sunucu = $Sunucu } | Set-Content -Path $ayarDosya -Encoding ASCII

    $donanim = Get-DonanimKimlik
    $donanimMetni = $donanim.uuid + '|' + $donanim.seri

    # --- Mevcut kimlik gecerli mi? --------------------------------------------
    $basliklar = $null
    if (Test-Path $kimlikDosya) {
        try {
            $k = Get-Content $kimlikDosya -Raw | ConvertFrom-Json
            if ($k.donanim -eq $donanimMetni) {
                $token = [Text.Encoding]::UTF8.GetString(
                    [Security.Cryptography.ProtectedData]::Unprotect([Convert]::FromBase64String($k.token), $null, 'LocalMachine'))
                $b = @{ 'X-Ajan-Guid' = $k.guid; 'X-Ajan-Token' = $token }
                $test = Invoke-Api 'nabiz.php' @{ bilgisayarAdi = $env:COMPUTERNAME } $b
                if ($test.Kod -eq 200) {
                    $basliklar = $b
                    Yaz "Cihaz zaten kayitli ($($k.guid)), kayit atlandi."
                } else {
                    Yaz "Mevcut kimlik sunucuda gecersiz (HTTP $($test.Kod)), yeniden kayit olunacak."
                }
            } else {
                Yaz 'Kimlik dosyasi baska bir donanima ait (klon imaj), yeniden kayit olunacak.'
            }
        } catch {
            Yaz "Kimlik dosyasi okunamadi, yeniden kayit olunacak: $($_.Exception.Message)"
        }
    }

    # --- Kayit ----------------------------------------------------------------
    if (-not $basliklar) {
        if (-not $KayitKodu) { throw 'Kayit kodu verilmedi (-KayitKodu veya -KayitKoduDosya).' }
        $c = Invoke-Api 'kayit.php' @{
            kayitKodu     = $KayitKodu
            bilgisayarAdi = $env:COMPUTERNAME
            anakartUuid   = $donanim.uuid
            biosSeri      = $donanim.seri
        }
        if ($c.Kod -ne 200 -or -not $c.Veri.basarili) { throw "Kayit basarisiz (HTTP $($c.Kod)): $($c.Hata)" }

        $sifreli = [Convert]::ToBase64String([Security.Cryptography.ProtectedData]::Protect(
            [Text.Encoding]::UTF8.GetBytes($c.Veri.token), $null, 'LocalMachine'))
        ConvertTo-Json -InputObject @{ guid = $c.Veri.guid; token = $sifreli; donanim = $donanimMetni } |
            Set-Content -Path $kimlikDosya -Encoding ASCII
        $basliklar = @{ 'X-Ajan-Guid' = $c.Veri.guid; 'X-Ajan-Token' = $c.Veri.token }
        if ($c.Veri.yeniKayit) { Yaz "Yeni cihaz kaydi: $($c.Veri.guid)" } else { Yaz "Mevcut cihaz kaydi yenilendi: $($c.Veri.guid)" }
    }

    # --- Dosyalar -------------------------------------------------------------
    foreach ($ad in 'baslatici.ps1', 'ajan.ps1') {
        Save-Dosya $ad $basliklar
        Yaz "$ad indirildi ve dogrulandi."
    }

    # --- Zamanlanmis gorev ----------------------------------------------------
    $ps     = '%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe'
    $eylem  = New-ScheduledTaskAction -Execute $ps -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$Kok\baslatici.ps1`""
    $tetik1 = New-ScheduledTaskTrigger -AtStartup
    # Yedek: dongu bir sekilde durursa saatte bir yeniden baslar (calisiyorsa IgnoreNew)
    $tetik2 = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(5) -RepetitionInterval (New-TimeSpan -Hours 1)
    $ayar   = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
                -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) `
                -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
    $kim    = New-ScheduledTaskPrincipal -UserId 'S-1-5-18' -LogonType ServiceAccount -RunLevel Highest
    Register-ScheduledTask -TaskName $GorevAdi -TaskPath '\' -Action $eylem -Trigger $tetik1, $tetik2 `
        -Settings $ayar -Principal $kim -Description "Uzak Yonetim ajani ($Sunucu)" -Force | Out-Null
    Start-ScheduledTask -TaskName $GorevAdi
    Yaz 'Zamanlanmis gorev kuruldu ve baslatildi.'

    # --- Ilk nabiz ------------------------------------------------------------
    $log = Join-Path $Kok 'log\ajan.log'
    $sinir = (Get-Date).AddSeconds(90)
    $tamam = $false
    while ((Get-Date) -lt $sinir) {
        Start-Sleep -Seconds 3
        if ((Test-Path $log) -and (Select-String -Path $log -Pattern 'Nabiz basarili' -Quiet)) { $tamam = $true; break }
    }
    if ($tamam) {
        Yaz 'Ajan calisiyor, ilk nabiz alindi.'
    } else {
        Yaz "UYARI: 90 sn icinde nabiz gorulmedi. Log: $log"
    }

    # --- Ilk kurulum islemleri (istege bagli) ---------------------------------
    # Grubun "ilk kurulumda calisacak" script'leri (BGInfo, AnyDesk, bilgisayar adi) kuyruga alinir.
    # Ajan bunlari sirayla SYSTEM olarak calistirir; sonuclar panelde cihaz detayinda gorunur.
    if ($IlkKurulum) {
        $ik = Invoke-Api 'ilk-kurulum.php' @{} $basliklar
        if ($ik.Kod -eq 200 -and $ik.Veri.basarili) {
            Yaz "Ilk kurulum islemleri kuyruga alindi ($($ik.Veri.eklenen) adim). Ajan sirayla calistiracak."
        } else {
            Yaz "UYARI: Ilk kurulum kuyruga alinamadi (HTTP $($ik.Kod)): $($ik.Hata)"
        }
    }
} catch {
    # exit yerine throw: elle acilmis konsolu kapatmaz, -Command altinda cikis kodu 1 olur
    Yaz "HATA: $($_.Exception.Message)"
    throw
}
