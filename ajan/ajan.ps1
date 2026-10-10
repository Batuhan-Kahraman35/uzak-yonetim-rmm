#Requires -Version 5.1
<#
    Uzak Yonetim - ajan
    baslatici.ps1 tarafindan SYSTEM olarak calistirilir. Uzaktan guncellenen katman.

    Dongu:
      - Her araligSn'de nabiz: temel bilgiler + envanter ozetleri
      - Sunucu ozet farkli derse (envanterIste) tam liste gonderilir
      - Envanter ozeti envanterKontrolDk'da bir yeniden hesaplanir (her nabizda CIM sorgusu yok)
      - 401/429: 15 dk bekler. Ofiste tum PC'ler ayni dis IP'den ciktigi icin,
        bozuk bir ajanin israrla denemesi butun ofisin IP'sini kilitletebilir.
      - YenidenBaslatSaat sonra 0 koduyla cikar; baslatici hemen yeniden baslatir (bellek temizligi)
      - Nabiz yanitindaki komutlar sirayla calistirilir (0.2.0): her komut ayri powershell surecinde,
        zaman asimi dolunca surec agaci sonlandirilir; sonuc komut-sonuc.php'ye gonderilir.
      - Script'ler Get-UzakEkranGoruntusu ile oturumdaki kullanicinin ekranini komuta ekleyebilir (0.3.0).
    Dosya ASCII tutulur: Windows PowerShell 5.1 BOM'suz dosyayi ANSI okur.
#>

$AjanSurum          = '0.3.0'
$YenidenBaslatSaat  = 12
$HataBeklemeSn      = 900

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
# .NET POST isteklerine "Expect: 100-continue" ekler; Plesk ModSecurity (CRS 920450) bu baslik yuzunden 403 doner
[Net.ServicePointManager]::Expect100Continue = $false
Add-Type -AssemblyName System.Security

$Kok    = $PSScriptRoot
$LogYol = Join-Path $Kok 'log\ajan.log'
$IsKlasor = Join-Path $Kok 'is'          # komut dosyalari; $Kok'un yetkilerini devralir (SYSTEM + Administrators)
$GuncelleBayrak = Join-Path $Kok 'guncelle.flag'   # uzaktan guncelleme: dosya varsa ajan yeni surumle yeniden baslar
$Ps     = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$CiktiSiniri = 1MB                       # sunucu ayrica komut_cikti_azami_kb ile kirpar

# ============================================================================
# Yardimcilar
# ============================================================================

function Log([string]$Mesaj) {
    try {
        if ((Test-Path $LogYol) -and (Get-Item $LogYol).Length -gt 1MB) {
            Move-Item $LogYol ($LogYol -replace '\.log$', '.1.log') -Force
        }
        Add-Content -Path $LogYol -Value ('[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Mesaj) -Encoding UTF8
    } catch {}
}

function Get-Ozet([string]$Metin) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        return -join ($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($Metin)) | ForEach-Object { $_.ToString('x2') })
    } finally {
        $sha.Dispose()
    }
}

function Metin($Deger) {
    if ($null -eq $Deger) { return $null }
    $m = ([string]$Deger).Trim()
    if ($m -eq '') { return $null }
    return $m
}

function Invoke-Api([string]$Yol, $Govde) {
    $json = ConvertTo-Json -InputObject $Govde -Depth 6 -Compress
    try {
        $cevap = Invoke-RestMethod -Uri "$($script:Sunucu)/api/ajan/$Yol" -Method Post -UseBasicParsing -TimeoutSec 120 `
            -Body ([Text.Encoding]::UTF8.GetBytes($json)) -ContentType 'application/json; charset=utf-8' `
            -Headers $script:Basliklar
        return @{ Kod = 200; Veri = $cevap }
    } catch {
        $kod = 0
        if ($_.Exception.Response) { $kod = [int]$_.Exception.Response.StatusCode }
        $mesaj = $_.ErrorDetails.Message
        if (-not $mesaj) { $mesaj = $_.Exception.Message }
        return @{ Kod = $kod; Hata = $mesaj }
    }
}

# ============================================================================
# Bilgi toplama
# ============================================================================

$script:OsOnbellek = $null

function Get-TemelBilgi {
    if (-not $script:OsOnbellek) {
        $os = Get-CimInstance Win32_OperatingSystem
        $cv = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion'
        $surum = $cv.DisplayVersion
        if (-not $surum) { $surum = $cv.ReleaseId }
        $derleme = [string]$cv.CurrentBuild
        if ($null -ne $cv.UBR) { $derleme = "$derleme.$($cv.UBR)" }
        $script:OsOnbellek = @{
            ad        = ($os.Caption -replace '^Microsoft\s+', '').Trim()
            surum     = Metin $surum
            derleme   = $derleme
            sonAcilis = $os.LastBootUpTime.ToString('yyyy-MM-dd HH:mm:ss')
        }
    }

    # Konsolda oturum acmis kullanici; RDP oturumunda bos gelir -> explorer.exe sahibi
    $kullanici = Metin (Get-CimInstance Win32_ComputerSystem).UserName
    if (-not $kullanici) {
        $explorer = Get-CimInstance Win32_Process -Filter "Name='explorer.exe'" | Select-Object -First 1
        if ($explorer) {
            $sahip = Invoke-CimMethod -InputObject $explorer -MethodName GetOwner
            if ($sahip.User) { $kullanici = "$($sahip.Domain)\$($sahip.User)" }
        }
    }

    # Varsayilan rotanin arayuzu = asil ag karti
    $ip = $null; $mac = $null
    $rota = Get-NetRoute -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue |
        Sort-Object { $_.RouteMetric + $_.InterfaceMetric } | Select-Object -First 1
    if ($rota) {
        $ip  = (Get-NetIPAddress -InterfaceIndex $rota.ifIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue |
                Select-Object -First 1).IPAddress
        $mac = (Get-NetAdapter -InterfaceIndex $rota.ifIndex -ErrorAction SilentlyContinue).MacAddress
    }

    return [ordered]@{
        bilgisayarAdi  = $env:COMPUTERNAME
        isletimSistemi = $script:OsOnbellek.ad
        osSurum        = $script:OsOnbellek.surum
        osDerleme      = $script:OsOnbellek.derleme
        aktifKullanici = $kullanici
        icIp           = $ip
        macAdres       = $mac
        anyDeskId      = Get-AnyDeskId
        ajanSurum      = $AjanSurum
        sonAcilis      = $script:OsOnbellek.sonAcilis
    }
}

# AnyDesk ID'yi system.conf / service.conf'tan okur (SYSTEM erisebilir). Yoksa null.
function Get-AnyDeskId {
    foreach ($cf in 'C:\ProgramData\AnyDesk\system.conf', 'C:\ProgramData\AnyDesk\service.conf') {
        if (Test-Path $cf) {
            foreach ($satir in Get-Content $cf -ErrorAction SilentlyContinue) {
                if ($satir -match '^ad\.anynet\.id=(\d+)$' -and $Matches[1] -ne '0') { return $Matches[1] }
            }
        }
    }
    return $null
}

function Get-Yazilimlar {
    $kaynaklar = @(
        @{ Yol = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall'
           Mimari = $(if ([Environment]::Is64BitOperatingSystem) { 'x64' } else { 'x86' }) },
        @{ Yol = 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall'; Mimari = 'x86' }
    )
    # Oturumu acik kullanicilarin kendi profillerine kurduklari (Teams, Zoom, kullanici Chrome'u vb.)
    foreach ($h in Get-ChildItem 'Registry::HKEY_USERS' -ErrorAction SilentlyContinue) {
        if ($h.PSChildName -match '^S-1-5-21-[\d-]+$') {
            $kaynaklar += @{ Yol = "Registry::HKEY_USERS\$($h.PSChildName)\Software\Microsoft\Windows\CurrentVersion\Uninstall"; Mimari = 'Kullanici' }
        }
    }

    $liste = foreach ($k in $kaynaklar) {
        if (-not (Test-Path $k.Yol)) { continue }
        foreach ($anahtar in Get-ChildItem $k.Yol -ErrorAction SilentlyContinue) {
            $p = Get-ItemProperty -LiteralPath $anahtar.PSPath -ErrorAction SilentlyContinue
            if (-not $p -or -not (Metin $p.DisplayName)) { continue }
            # Sistem bilesenleri ve guncelleme paketleri envantere girmez
            if ($p.SystemComponent -eq 1 -or $p.ParentKeyName -or ($p.ReleaseType -in 'Security Update', 'Update Rollup', 'Hotfix')) { continue }

            $kaldir = Metin $p.QuietUninstallString
            if (-not $kaldir) { $kaldir = Metin $p.UninstallString }

            [ordered]@{
                ad             = Metin $p.DisplayName
                surum          = Metin $p.DisplayVersion
                yayinci        = Metin $p.Publisher
                kurulumTarihi  = Metin $p.InstallDate
                mimari         = $k.Mimari
                kaldirmaKomutu = $kaldir
            }
        }
    }
    return @($liste | Sort-Object { $_.ad }, { $_.surum }, { $_.mimari })
}

function Get-Donanim([int]$DiskAdimGb) {
    $cs   = Get-CimInstance Win32_ComputerSystem
    $bios = Get-CimInstance Win32_BIOS
    $cpu  = @(Get-CimInstance Win32_Processor)
    $kart = Get-CimInstance Win32_BaseBoard | Select-Object -First 1
    $ekran = @(Get-CimInstance Win32_VideoController | ForEach-Object { Metin $_.Name } | Where-Object { $_ })

    # RAM: "2x8GB DDR4 3200"
    $bellek = @(Get-CimInstance Win32_PhysicalMemory)
    $tipAd  = @{ 20 = 'DDR'; 21 = 'DDR2'; 24 = 'DDR3'; 26 = 'DDR4'; 34 = 'DDR5'; 35 = 'LPDDR5' }
    $ramDetay = ($bellek | Group-Object { [Math]::Round($_.Capacity / 1GB) } | ForEach-Object {
        $b = $_.Group[0]
        $parca = '{0}x{1}GB' -f $_.Count, $_.Name
        $tip = $tipAd[[int]$b.SMBIOSMemoryType]
        if ($tip) { $parca += " $tip" }
        $hiz = $b.ConfiguredClockSpeed
        if (-not $hiz) { $hiz = $b.Speed }
        if ($hiz) { $parca += " $hiz" }
        $parca
    }) -join ', '
    $ramToplam = ($bellek | Measure-Object -Property Capacity -Sum).Sum
    if (-not $ramToplam) { $ramToplam = $cs.TotalPhysicalMemory }

    $bilgi = [ordered]@{
        uretici        = Metin $cs.Manufacturer
        model          = Metin $cs.Model
        seriNo         = Metin $bios.SerialNumber
        islemci        = Metin (($cpu | Group-Object { Metin $_.Name } | ForEach-Object {
                             if ($_.Count -gt 1) { "$($_.Count)x $($_.Name)" } else { $_.Name } }) -join ' + ')
        cekirdekSayisi = [int](($cpu | Measure-Object -Property NumberOfCores -Sum).Sum)
        ramGb          = [Math]::Round($ramToplam / 1GB, 1)
        ramDetay       = Metin $ramDetay
        anakart        = Metin ("$($kart.Manufacturer) $($kart.Product)")
        biosSurum      = Metin $bios.SMBIOSBIOSVersion
        ekranKarti     = Metin ($ekran -join ', ')
    }

    $diskler = @(foreach ($ld in Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3') {
        $model = $null; $tip = $null
        try {
            $disk = Get-Partition -DriveLetter $ld.DeviceID.TrimEnd(':') -ErrorAction Stop | Get-Disk -ErrorAction Stop
            $model = Metin $disk.FriendlyName
            $fiz = Get-PhysicalDisk -ErrorAction Stop | Where-Object { $_.DeviceId -eq [string]$disk.Number } | Select-Object -First 1
            if ($fiz) {
                switch ([string]$fiz.MediaType) {
                    'SSD'   { $tip = 'SSD' }
                    'HDD'   { $tip = 'HDD' }
                    default { if ([string]$fiz.BusType -eq 'NVMe') { $tip = 'SSD' } }
                }
            }
        } catch {}
        [ordered]@{
            surucu   = $ld.DeviceID
            model    = $model
            tip      = $tip
            toplamGb = [Math]::Round($ld.Size / 1GB, 1)
            bosGb    = [Math]::Round($ld.FreeSpace / 1GB, 1)
        }
    })

    # Ozet: bos alan adima yuvarlanir; yoksa her dosya degisikliginde envanter gonderilirdi
    $ozetMetni = (ConvertTo-Json -InputObject $bilgi -Compress) + '|' + (($diskler | ForEach-Object {
        '{0}/{1}/{2}/{3}/{4}' -f $_.surucu, $_.model, $_.tip, $_.toplamGb, ([Math]::Floor($_.bosGb / $DiskAdimGb) * $DiskAdimGb)
    }) -join ';')

    return @{ bilgi = $bilgi; diskler = $diskler; ozet = (Get-Ozet $ozetMetni) }
}

# ============================================================================
# Komut calistirma (0.2.0)
# ============================================================================

# Her komut bu calistirici ile ayri surecte kosar. Script'ler param() ile parametre alir ve
# asagidaki yardimcilari kullanabilir. Ajan kimligi ortam degiskeninden gelir (diske yazilmaz).
$script:Calistirici = @'
param([string]$ScriptYol, [string]$ParamYol, [int]$HedefId)
$ErrorActionPreference = 'Stop'
$script:UzakHedefId = $HedefId
[Console]::OutputEncoding = [Text.Encoding]::UTF8
$OutputEncoding = [Text.Encoding]::UTF8
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
[Net.ServicePointManager]::Expect100Continue = $false

# Parametreler okunur okunmaz dosya silinir (gizli degerler diskte kalmaz)
$Parametreler = @{}
if ($ParamYol -and (Test-Path -LiteralPath $ParamYol)) {
    try {
        $j = [IO.File]::ReadAllText($ParamYol, [Text.Encoding]::UTF8) | ConvertFrom-Json
        if ($j) { foreach ($p in $j.PSObject.Properties) { $Parametreler[$p.Name] = $p.Value } }
    } finally {
        Remove-Item -LiteralPath $ParamYol -Force -ErrorAction SilentlyContinue
    }
}

function New-UzakIstemci {
    $wc = New-Object Net.WebClient
    $wc.Headers['X-Ajan-Guid']  = $env:UZAK_GUID
    $wc.Headers['X-Ajan-Token'] = $env:UZAK_TOKEN
    return $wc
}

# Depodaki paketi hedef klasore indirir; SHA256 dogrulanir, ayni dosya tekrar indirilmez.
# Ornek: Get-UzakPaket -Paket 'BGInfo' -Hedef 'C:\Programlar\BGInfo'
function Get-UzakPaket {
    param([Parameter(Mandatory)][string]$Paket, [Parameter(Mandatory)][string]$Hedef)
    $adres = "$env:UZAK_SUNUCU/api/ajan/depo.php"
    $liste = ([Text.Encoding]::UTF8.GetString((New-UzakIstemci).DownloadData("$adres`?paket=" + [Uri]::EscapeDataString($Paket))) | ConvertFrom-Json).dosyalar
    New-Item -ItemType Directory -Path $Hedef -Force | Out-Null
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        foreach ($d in @($liste)) {
            if ([string]$d.ad -notmatch '^[^\\/:*?"<>|]+$') { throw "Gecersiz dosya adi: $($d.ad)" }
            $yol = Join-Path $Hedef $d.ad
            if ((Test-Path -LiteralPath $yol) -and ((Get-FileHash -LiteralPath $yol -Algorithm SHA256).Hash -eq $d.sha256)) {
                Write-Output "  ayni      $($d.ad)"
                continue
            }
            $bayt = (New-UzakIstemci).DownloadData("$adres`?id=$([int]$d.id)")
            $ozet = -join ($sha.ComputeHash($bayt) | ForEach-Object { $_.ToString('x2') })
            if ($ozet -ne $d.sha256) { throw "SHA256 uyusmadi: $($d.ad)" }
            $gecici = "$yol.indiriliyor"
            [IO.File]::WriteAllBytes($gecici, $bayt)
            Move-Item -LiteralPath $gecici -Destination $yol -Force
            Write-Output "  indirildi $($d.ad) ($($bayt.Length) bayt)"
        }
    } finally {
        $sha.Dispose()
    }
}

# Oturum acmis kullanicinin ekranini JPEG olarak yakalar ve bu komuta ekler (panelde komut sonucunda gorunur).
# SYSTEM'in ekrani yoktur: yakalama, depodan inen UzakEkran.exe ile kullanicinin oturumunda tek seferlik
# zamanlanmis gorevle yapilir. Ekran kilitliyse ya da oturum yoksa hata verir.
# Ornek: Get-UzakEkranGoruntusu
#        Get-UzakEkranGoruntusu -Kullanici 'DOMAIN\ali' -Kalite 50
function Get-UzakEkranGoruntusu {
    param(
        [string]$Kullanici,
        [ValidateRange(10, 95)][int]$Kalite = 60,
        [ValidateRange(0, 10000)][int]$AzamiGenislik = 2560,
        [ValidateRange(5, 300)][int]$BeklemeSn = 30
    )
    if ($script:UzakHedefId -lt 1) { throw 'Komut kimligi yok; ajan 0.3.0 calistiricisi gerekli.' }

    if (-not $Kullanici) {
        # Konsol oturumundaki explorer sahibi; konsolda kimse yoksa (yalniz RDP) ilk explorer
        if (-not ('UzakEkran.Wts' -as [type])) {
            Add-Type -Namespace UzakEkran -Name Wts -MemberDefinition '[DllImport("kernel32.dll")] public static extern uint WTSGetActiveConsoleSessionId();'
        }
        $konsol = [UzakEkran.Wts]::WTSGetActiveConsoleSessionId()
        $exp = @(Get-CimInstance Win32_Process -Filter "Name='explorer.exe'") |
            Sort-Object { if ($_.SessionId -eq $konsol) { 0 } else { 1 } } | Select-Object -First 1
        if (-not $exp) { throw 'Oturum acmis kullanici yok (explorer.exe calismiyor).' }
        $sahip = Invoke-CimMethod -InputObject $exp -MethodName GetOwner
        if (-not $sahip.User) { throw 'Oturum sahibi okunamadi.' }
        $Kullanici = "$($sahip.Domain)\$($sahip.User)"
    }

    # Yakalayici exe depodan alinir (SHA256 dogrulamali; degismediyse tekrar inmez)
    $aracKlasor = Join-Path $env:UZAK_KOK 'arac'
    Get-UzakPaket -Paket 'UzakEkran' -Hedef $aracKlasor | Out-Null
    $kaynakExe = Join-Path $aracKlasor 'UzakEkran.exe'
    if (-not (Test-Path -LiteralPath $kaynakExe)) { throw 'UzakEkran.exe depodan alinamadi.' }

    $ad     = 'UzakEkran_' + [guid]::NewGuid().ToString('N').Substring(0, 12)
    $klasor = Join-Path $env:ProgramData "UzakYonetimEkran\$ad"
    $bayt   = $null
    try {
        New-Item -ItemType Directory -Path $klasor -Force | Out-Null
        # Miras kapatilir: SYSTEM + Administrators tam; hedef kullanici degistirme (exe'yi calistirir, jpg yazar).
        # Ajan klasoru yalniz SYSTEM/Admin oldugu icin exe buraya kopyalanir, kullanici oradan calistirir.
        & icacls.exe $klasor /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' "${Kullanici}:(OI)(CI)M" | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "Klasor yetkisi verilemedi: $Kullanici" }
        Copy-Item -LiteralPath $kaynakExe -Destination (Join-Path $klasor 'UzakEkran.exe') -Force

        $exe = Join-Path $klasor 'UzakEkran.exe'
        $jpg = Join-Path $klasor 'ekran.jpg'
        $arg = "`"$jpg`" $Kalite $AzamiGenislik"
        # conhost --headless (Win10 1809+) konsol penceresi hic acmaz; eski surumlerde pencere bir an gorunebilir
        $eylem = if ([Environment]::OSVersion.Version.Build -ge 17763) {
            New-ScheduledTaskAction -Execute (Join-Path $env:SystemRoot 'System32\conhost.exe') -Argument "--headless `"$exe`" $arg"
        } else {
            New-ScheduledTaskAction -Execute $exe -Argument $arg
        }
        $kim   = New-ScheduledTaskPrincipal -UserId $Kullanici -LogonType Interactive -RunLevel Limited
        $gayar = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 2)
        Register-ScheduledTask -TaskName $ad -TaskPath '\' -Action $eylem -Principal $kim -Settings $gayar -Force | Out-Null
        Start-ScheduledTask -TaskName $ad -TaskPath '\'

        $hata = "$jpg.hata.txt"
        $son  = (Get-Date).AddSeconds($BeklemeSn)
        while (-not (Test-Path -LiteralPath $jpg) -and -not (Test-Path -LiteralPath $hata) -and (Get-Date) -lt $son) {
            Start-Sleep -Milliseconds 500
        }
        if (Test-Path -LiteralPath $hata) {
            throw "Ekran yakalanamadi ($Kullanici): $([IO.File]::ReadAllText($hata).Trim()) Ekran kilitli olabilir."
        }
        if (-not (Test-Path -LiteralPath $jpg)) { throw "Ekran goruntusu $BeklemeSn sn icinde alinamadi ($Kullanici)." }
        $bayt = [IO.File]::ReadAllBytes($jpg)
    } finally {
        Stop-ScheduledTask -TaskName $ad -TaskPath '\' -ErrorAction SilentlyContinue
        Unregister-ScheduledTask -TaskName $ad -TaskPath '\' -Confirm:$false -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $klasor -Recurse -Force -ErrorAction SilentlyContinue
    }

    # Govde elle kurulur: buyuk base64 metninde ConvertTo-Json (PS 5.1) yavas
    $govde = '{"hedefId":' + [int]$script:UzakHedefId + ',"jpeg":"' + [Convert]::ToBase64String($bayt) + '","oturum":' + (ConvertTo-Json -InputObject $Kullanici -Compress) + '}'
    $wc = New-UzakIstemci
    $wc.Headers['Content-Type'] = 'application/json; charset=utf-8'
    try {
        $cevap = [Text.Encoding]::UTF8.GetString(
            $wc.UploadData("$env:UZAK_SUNUCU/api/ajan/ekran-goruntusu.php", 'POST', [Text.Encoding]::UTF8.GetBytes($govde))) | ConvertFrom-Json
    } catch {
        $e = $_.Exception
        while ($e.InnerException -and -not ($e -is [Net.WebException])) { $e = $e.InnerException }
        $m = $e.Message
        if ($e -is [Net.WebException] -and $e.Response) {
            try { $m = (New-Object IO.StreamReader($e.Response.GetResponseStream())).ReadToEnd() } catch {}
        }
        throw "Ekran goruntusu yuklenemedi: $m"
    }
    Write-Output ("Ekran goruntusu: {0} ({1}, {2} KB)" -f $cevap.dosya, $Kullanici, [Math]::Round($bayt.Length / 1KB))
}

$sb = [scriptblock]::Create([IO.File]::ReadAllText($ScriptYol, [Text.Encoding]::UTF8))
try {
    & $sb @Parametreler
} catch {
    [Console]::Error.WriteLine(($_ | Out-String).Trim())
    exit 1
}
exit 0
'@

function Oku-Cikti([string]$Yol) {
    if (-not (Test-Path -LiteralPath $Yol)) { return $null }
    $m = [IO.File]::ReadAllText($Yol, [Text.Encoding]::UTF8).Trim()
    if ($m.Length -gt $CiktiSiniri) { $m = $m.Substring(0, $CiktiSiniri) + "`n... [ajan tarafinda kirpildi]" }
    if ($m -eq '') { return $null }
    return $m
}

# Sonuc gonderimi: ag hatasinda 3 deneme. 409 = sunucu hedefi zaten kapatmis, tekrar denenmez.
function Send-KomutSonuc([hashtable]$Govde) {
    for ($i = 1; $i -le 3; $i++) {
        $c = Invoke-Api 'komut-sonuc.php' $Govde
        if ($c.Kod -in 200, 409) { return }
        if ($c.Kod -in 400, 401, 429) { break }
        Start-Sleep -Seconds (5 * $i)
    }
    Log "Komut sonucu gonderilemedi (hedef $($Govde.hedefId), $($Govde.durum)): HTTP $($c.Kod) $($c.Hata)"
}

function Invoke-Komut($Komut) {
    $id    = [int]$Komut.hedefId
    $sure  = [Math]::Max(10, [int]$Komut.zamanAsimiSn)
    $utf8  = New-Object Text.UTF8Encoding($true)   # BOM: PS 5.1 Turkce karakterli script'i dogru okusun
    $scr   = Join-Path $IsKlasor "$id.ps1"
    $par   = Join-Path $IsKlasor "$id.json"
    $cal   = Join-Path $IsKlasor "$id.calistirici.ps1"
    $out   = Join-Path $IsKlasor "$id.out"
    $err   = Join-Path $IsKlasor "$id.err"

    Log "Komut basliyor: #$id $($Komut.baslik) (zaman asimi $sure sn)"
    Send-KomutSonuc @{ hedefId = $id; durum = 'calisiyor' }

    $durum = 'hatali'; $kod = $null; $ek = $null
    try {
        New-Item -ItemType Directory -Path $IsKlasor -Force | Out-Null
        [IO.File]::WriteAllText($scr, [string]$Komut.icerik, $utf8)
        [IO.File]::WriteAllText($par, (ConvertTo-Json -InputObject $Komut.parametreler -Depth 6 -Compress), $utf8)
        [IO.File]::WriteAllText($cal, $script:Calistirici, $utf8)

        $s = Start-Process -FilePath $Ps -NoNewWindow -PassThru `
            -RedirectStandardOutput $out -RedirectStandardError $err `
            -ArgumentList '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', "`"$cal`"",
                          '-ScriptYol', "`"$scr`"", '-ParamYol', "`"$par`"", '-HedefId', $id
        $null = $s.Handle   # PS 5.1: tutamac alinmazsa ExitCode bos doner
        if ($s.WaitForExit($sure * 1000)) {
            $s.WaitForExit()
            $kod = $s.ExitCode
            $durum = if ($kod -eq 0) { 'basarili' } else { 'hatali' }
        } else {
            & taskkill.exe /T /F /PID $s.Id 2>&1 | Out-Null
            $durum = 'zamanasimi'
            $ek = "Zaman asimi: $sure sn icinde bitmedi, surec sonlandirildi."
        }
    } catch {
        $ek = "Ajan komutu calistiramadi: $($_.Exception.Message)"
    }

    $hata = @((Oku-Cikti $err), $ek) | Where-Object { $_ }
    Send-KomutSonuc @{
        hedefId   = $id
        durum     = $durum
        cikisKodu = $kod
        cikti     = Oku-Cikti $out
        hata      = $(if ($hata) { $hata -join "`n" } else { $null })
    }
    Remove-Item -LiteralPath $scr, $par, $cal, $out, $err -Force -ErrorAction SilentlyContinue
    Log "Komut bitti: #$id $durum (kod $kod)"
}

# ============================================================================
# Baslangic
# ============================================================================

try {
    $ayar   = Get-Content (Join-Path $Kok 'ayar.json') -Raw | ConvertFrom-Json
    $kimlik = Get-Content (Join-Path $Kok 'kimlik.json') -Raw | ConvertFrom-Json
    $token  = [Text.Encoding]::UTF8.GetString(
        [Security.Cryptography.ProtectedData]::Unprotect([Convert]::FromBase64String($kimlik.token), $null, 'LocalMachine'))
    $script:Sunucu    = $ayar.sunucu.TrimEnd('/')
    $script:Basliklar = @{ 'X-Ajan-Guid' = $kimlik.guid; 'X-Ajan-Token' = $token }
    # Komut surecleri (Get-UzakPaket) kimligi buradan devralir; yalniz bu surec ve alt surecleri gorur
    $env:UZAK_SUNUCU = $script:Sunucu
    $env:UZAK_GUID   = $kimlik.guid
    $env:UZAK_TOKEN  = $token
    $env:UZAK_KOK    = $Kok   # Get-UzakEkranGoruntusu yakalayici exe'yi buradaki arac\ klasorunde arar
} catch {
    Log "Ayar/kimlik okunamadi: $($_.Exception.Message)"
    exit 2
}

# Onceki calismadan yarim kalmis komut dosyalari (parametre dosyasi gizli deger icerebilir)
if (Test-Path $IsKlasor) { Remove-Item (Join-Path $IsKlasor '*') -Force -ErrorAction SilentlyContinue }

Log "Ajan $AjanSurum basladi (PID $PID)."

$baslangic      = Get-Date
$aralikSn       = 60
$kontrolDk      = 15
$diskAdimGb     = 5
$ozetZamani     = [datetime]::MinValue
$yazilimlar     = $null
$yazilimOzet    = $null
$donanim        = $null
$ilkNabiz       = $true

while ($true) {
    # Uzaktan guncelleme: "Ajani guncelle" script'i yeni ajan.ps1'i indirip bu bayragi birakir.
    # Temiz cikariz (kod 0); baslatici yeni ajan.ps1 ile yeniden baslatir.
    if (Test-Path $GuncelleBayrak) {
        Remove-Item $GuncelleBayrak -Force -ErrorAction SilentlyContinue
        Log 'Guncelleme bayragi: yeni surumle yeniden baslatiliyor.'
        exit 0
    }
    if (((Get-Date) - $baslangic).TotalHours -ge $YenidenBaslatSaat) {
        Log 'Periyodik yeniden baslatma.'
        exit 0
    }

    $bekle = $aralikSn
    try {
        # Envanter ozetleri (seyrek). Hata nabzi engellemez; ozet bos giderse sunucu yalniz istemez.
        if (((Get-Date) - $ozetZamani).TotalMinutes -ge $kontrolDk) {
            $ozetZamani = Get-Date
            try {
                $yazilimlar  = Get-Yazilimlar
                $yazilimOzet = Get-Ozet (ConvertTo-Json -InputObject $yazilimlar -Depth 3 -Compress)
            } catch { Log "Yazilim envanteri alinamadi: $($_.Exception.Message)" }
            try {
                $donanim = Get-Donanim $diskAdimGb
            } catch { Log "Donanim envanteri alinamadi: $($_.Exception.Message)" }
        }

        $govde = Get-TemelBilgi
        $govde['yazilimHash'] = $yazilimOzet
        $govde['donanimHash'] = $donanim.ozet

        $c = Invoke-Api 'nabiz.php' $govde
        if ($c.Kod -eq 200) {
            if ($ilkNabiz) { Log 'Nabiz basarili.'; $ilkNabiz = $false }
            $v = $c.Veri
            if ($v.araligSn -ge 15)       { $aralikSn  = [int]$v.araligSn }
            if ($v.envanterKontrolDk -ge 1) { $kontrolDk = [int]$v.envanterKontrolDk }
            if ($v.diskBosAdimGb -ge 1)   { $diskAdimGb = [int]$v.diskBosAdimGb }
            $bekle = $aralikSn

            $iste = @($v.envanterIste)
            if ($iste.Count -gt 0) {
                $envanter = @{}
                if ($iste -contains 'yazilim' -and $yazilimOzet) { $envanter['yazilim'] = @{ hash = $yazilimOzet; liste = $yazilimlar } }
                if ($iste -contains 'donanim' -and $donanim)     { $envanter['donanim'] = @{ hash = $donanim.ozet; bilgi = $donanim.bilgi; diskler = $donanim.diskler } }
                $e = if ($envanter.Count -gt 0) { Invoke-Api 'envanter.php' $envanter } else { @{ Kod = 0; Hata = 'Gonderilecek envanter yok' } }
                if ($e.Kod -eq 200) {
                    Log ('Envanter gonderildi: ' + ($iste -join ', ') + " ($($yazilimlar.Count) yazilim)")
                } else {
                    Log "Envanter gonderilemedi (HTTP $($e.Kod)): $($e.Hata)"
                }
            }

            # Komutlar sirayla; ardindan hemen yeni nabiz (kuyrukta kalan varsa gelsin, sonuclar gorunsun)
            $komutlar = @($v.komutlar | Where-Object { $_ })
            if ($komutlar.Count -gt 0) {
                foreach ($k in $komutlar) {
                    try { Invoke-Komut $k } catch { Log "Komut hatasi (#$($k.hedefId)): $($_.Exception.Message)" }
                }
                $bekle = 5
            }
        } elseif ($c.Kod -in 401, 429) {
            Log "Sunucu reddetti (HTTP $($c.Kod)): $($c.Hata). $HataBeklemeSn sn beklenecek."
            $bekle = $HataBeklemeSn
        } else {
            Log "Nabiz basarisiz (HTTP $($c.Kod)): $($c.Hata)"
        }
    } catch {
        Log "Hata: $($_.Exception.Message) @ satir $($_.InvocationInfo.ScriptLineNumber)"
    }

    Start-Sleep -Seconds $bekle
}
