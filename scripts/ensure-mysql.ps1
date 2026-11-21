# Ensures XAMPP MySQL is running for POSMaster.
# Safe to run repeatedly; starts mysqld only when port 3306 is closed.

$ErrorActionPreference = 'SilentlyContinue'
$port = 3306
$mysqld = 'C:\xampp\mysql\bin\mysqld.exe'
$defaults = 'C:\xampp\mysql\bin\my.ini'
$logDir = Join-Path $env:LOCALAPPDATA 'POSMaster'
$logFile = Join-Path $logDir 'mysql-ensure.log'

if (-not (Test-Path $logDir)) {
    New-Item -ItemType Directory -Path $logDir | Out-Null
}

function Write-Log([string]$message) {
    $line = '{0} {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $message
    Add-Content -Path $logFile -Value $line
}

function Test-MysqlPort {
    try {
        $client = New-Object System.Net.Sockets.TcpClient
        $iar = $client.BeginConnect('127.0.0.1', $port, $null, $null)
        $ok = $iar.AsyncWaitHandle.WaitOne(800)
        if ($ok -and $client.Connected) {
            $client.EndConnect($iar)
            $client.Close()
            return $true
        }
        $client.Close()
    } catch {}
    return $false
}

if (Test-MysqlPort) {
    Write-Log 'MySQL already listening on 3306.'
    exit 0
}

if (-not (Test-Path $mysqld)) {
    Write-Log "ERROR: mysqld not found at $mysqld"
    exit 1
}

if (-not (Test-Path $defaults)) {
    Write-Log "ERROR: my.ini not found at $defaults"
    exit 1
}

Write-Log 'Port 3306 closed. Starting XAMPP MySQL...'
Start-Process -FilePath $mysqld -ArgumentList "--defaults-file=$defaults", '--standalone' -WindowStyle Hidden

for ($i = 0; $i -lt 20; $i++) {
    Start-Sleep -Milliseconds 500
    if (Test-MysqlPort) {
        Write-Log 'MySQL started successfully.'
        exit 0
    }
}

Write-Log 'ERROR: MySQL did not become ready on port 3306.'
exit 1
