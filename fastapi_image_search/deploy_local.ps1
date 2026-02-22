param(
    [string]$PythonPath = "C:\Users\boot\AppData\Local\Programs\Python\Python312\python.exe",
    [string]$ListenHost = "127.0.0.1",
    [int]$Port = 8001,
    [switch]$InstallOnly,
    [switch]$SkipInstall
)

$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $scriptDir

Write-Host "[INFO] Working directory: $scriptDir"
Write-Host "[INFO] Python: $PythonPath"

if (-not (Test-Path $PythonPath)) {
    throw "Python not found at path: $PythonPath"
}

if (-not $SkipInstall) {
    Write-Host "[INFO] Installing requirements from requirements.txt ..."
    & $PythonPath -m pip install -r requirements.txt
}

if ($InstallOnly) {
    Write-Host "[OK] Requirements installed. Install-only mode complete."
    exit 0
}

$env:KMP_DUPLICATE_LIB_OK = "TRUE"

Write-Host "[INFO] Starting FastAPI server on http://$ListenHost`:$Port"
& $PythonPath -m uvicorn --app-dir $scriptDir app.main:app --host $ListenHost --port $Port
