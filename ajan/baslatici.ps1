#Requires -Version 5.1
<#
    Uzak Yonetim - baslatici
    Kucuk ve neredeyse degismez katman. ajan.ps1'i ayri surecte calistirir, cikarsa yeniden baslatir.
      - cikis kodu 0 : planli yeniden baslatma (periyodik / guncelleme) -> hemen tekrar
      - diger        : hata -> artan bekleme (15 sn ... 5 dk)
    Tek kopya: Global mutex. (Gorev de MultipleInstances=IgnoreNew.)
    6. adimda guncelleme + geri alma buraya eklenecek.
#>

$Kok  = $PSScriptRoot
$Ajan = Join-Path $Kok 'ajan.ps1'
$Log  = Join-Path $Kok 'log\baslatici.log'
$Ps   = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'

function Log([string]$Mesaj) {
    try {
        if ((Test-Path $Log) -and (Get-Item $Log).Length -gt 512KB) { Move-Item $Log ($Log -replace '\.log$', '.1.log') -Force }
        Add-Content -Path $Log -Value ('[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Mesaj)
    } catch {}
}

$mutex = New-Object Threading.Mutex($false, 'Global\UzakYonetimAjan')
try {
    if (-not $mutex.WaitOne(0)) { exit 0 }
} catch [Threading.AbandonedMutexException] {
    # Onceki kopya zorla kapatilmis; mutex artik bizde
}

Log 'Baslatici basladi.'
$hataSayisi = 0

while ($true) {
    if (-not (Test-Path $Ajan)) {
        Log 'ajan.ps1 bulunamadi, 5 dk sonra tekrar bakilacak.'
        Start-Sleep -Seconds 300
        continue
    }

    $baslangic = Get-Date
    $surec = Start-Process -FilePath $Ps -WindowStyle Hidden -PassThru -Wait `
        -ArgumentList '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', "`"$Ajan`""
    $kod = $surec.ExitCode

    if ($kod -eq 0) {
        $hataSayisi = 0
        continue
    }

    # 10 dk'dan uzun calistiysa ardisik hata sayilmaz
    if (((Get-Date) - $baslangic).TotalMinutes -gt 10) { $hataSayisi = 0 }
    $hataSayisi++
    $bekle = [Math]::Min(300, 15 * $hataSayisi)
    Log "ajan.ps1 cikis kodu $kod; $bekle sn sonra yeniden baslatilacak."
    Start-Sleep -Seconds $bekle
}
