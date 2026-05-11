# UTM Webmaster Tool -- Deploy via Windows Python
# Routes through aTrust VPN (which only works on Windows/Mac)
#
# Usage:
#   powershell.exe -ExecutionPolicy Bypass -File D:\tools\utm-deploy\deploy\run.ps1 --wave pilot
#   powershell.exe -ExecutionPolicy Bypass -File D:\tools\utm-deploy\deploy\run.ps1 --target dvcdev

param(
    [string]$Wave = "",
    [string]$Target = "",
    [switch]$DryRun = $false,
    [switch]$ListSites = $false,
    [switch]$VerifyOnly = $false
)

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Resolve-Path (Join-Path $ScriptDir "..")
$deployPy = Join-Path (Join-Path $RepoRoot "deploy") "deploy.py"
$pythonExe = "C:\Users\sharu\miniconda3\python.exe"

$argsList = @()
if ($Wave) { $argsList += "--wave"; $argsList += $Wave }
if ($Target) { $argsList += "--target"; $argsList += $Target }
if ($DryRun) { $argsList += "--dry-run" }
if ($ListSites) { $argsList += "--list-sites" }
if ($VerifyOnly) { $argsList += "--verify-only" }

Write-Host "UTM Webmaster Tool -- Deploy via Windows Python"
Write-Host ""

# Run Python from Windows (routes through aTrust VPN)
$result = & $pythonExe $deployPy $argsList 2>&1
$exitCode = $LASTEXITCODE
$result | ForEach-Object { Write-Host $_ }

exit $exitCode
