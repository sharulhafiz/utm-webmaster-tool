# UTM Webmaster Tool -- Deploy Script for Windows PowerShell
# Uses Windows network stack (aTrust VPN) to reach UTM FTP servers.
#
# Usage from WSL:
#   powershell.exe -ExecutionPolicy Bypass -File D:\tools\utm-deploy\deploy\deploy.ps1 -Wave pilot
#   powershell.exe -ExecutionPolicy Bypass -File D:\tools\utm-deploy\deploy\deploy.ps1 -Target dvcdev
#   powershell.exe -ExecutionPolicy Bypass -File D:\tools\utm-deploy\deploy\deploy.ps1 -ListSites

param(
    [string]$Wave = "",
    [string]$Target = "",
    [switch]$DryRun = $false,
    [switch]$ListSites = $false
)

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Resolve-Path (Join-Path $ScriptDir "..")

$SftpJson = Join-Path (Join-Path $RepoRoot ".vscode") "sftp.json"
$EnvFile  = Join-Path (Join-Path $RepoRoot "deploy") ".env"
$ConfigPy = Join-Path (Join-Path $RepoRoot "deploy") "config.py"
$IndexPhp = Join-Path $RepoRoot "index.php"

# Validate
if (-not (Test-Path $SftpJson)) { Write-Host "ERROR: sftp.json not found at $SftpJson"; exit 1 }
if (-not (Test-Path $EnvFile))  { Write-Host "ERROR: .env not found at $EnvFile"; exit 1 }
if (-not (Test-Path $ConfigPy)) { Write-Host "ERROR: config.py not found at $ConfigPy"; exit 1 }

# Get version
$IndexRaw = Get-Content $IndexPhp -Raw
$VerMatch = [regex]::Match($IndexRaw, "define\s*\(\s*'UTM_PLUGIN_VERSION'\s*,\s*'([^']+)'")
$PluginVersion = if ($VerMatch.Success) { $VerMatch.Groups[1].Value } else { "?" }

# Load config.py waves
$ConfigRaw = Get-Content $ConfigPy -Raw
function Parse-WaveList($pattern) {
    $m = [regex]::Match($ConfigRaw, $pattern, [System.Text.RegularExpressions.RegexOptions]::Singleline)
    if (-not $m.Success) { return @() }
    return [regex]::Matches($m.Groups[1].Value, '"([^"]+)"') | ForEach-Object { $_.Groups[1].Value }
}
$PilotWave = Parse-WaveList 'PILOT_WAVE = \[(.*?)\]'
$MidWave   = Parse-WaveList 'MID_WAVE = \[(.*?)\]'
$FullWave  = Parse-WaveList 'FULL_WAVE = \[(.*?)\]'

# Load sftp.json
$SftpRaw = Get-Content $SftpJson -Raw | ConvertFrom-Json
$Profiles = @{}
$SftpRaw.profiles.PSObject.Properties | ForEach-Object { $Profiles[$_.Name] = $_.Value }

# Load passwords
$Passwords = @{}
Get-Content $EnvFile | ForEach-Object {
    if ($_ -match '^([^#=]+)=(.+)$') {
        $Passwords[$matches[1].Trim()] = $matches[2].Trim()
    }
}

# Build target list
$TargetNames = @()
if ($Target) {
    if (-not $Profiles.ContainsKey($Target)) { Write-Host "ERROR: Unknown target '$Target'"; exit 1 }
    $TargetNames = @($Target)
} elseif ($Wave) {
    $map = @{ "pilot" = $PilotWave; "mid" = $MidWave; "full" = $FullWave }
    if (-not $map.ContainsKey($Wave)) { Write-Host "ERROR: Unknown wave '$Wave' (pilot/mid/full)"; exit 1 }
    $TargetNames = $map[$Wave]
} elseif ($ListSites) {
    Write-Host "`n  Sites configured in sftp.json:`n"
    Write-Host ("{0,-20} {1,-10} {2,-25} {3,-8}" -f "Site", "Wave", "Host", "Protocol")
    Write-Host ("{0,-20} {1,-10} {2,-25} {3,-8}" -f ("-"*20), ("-"*10), ("-"*25), ("-"*8))
    $allWaves = @{}
    $PilotWave | ForEach-Object { $allWaves[$_] = "pilot" }
    $MidWave   | ForEach-Object { $allWaves[$_] = "mid" }
    $FullWave  | ForEach-Object { $allWaves[$_] = "full" }
    foreach ($name in ($Profiles.Keys | Sort-Object)) {
        $p = $Profiles[$name]
        $wave = if ($allWaves.ContainsKey($name)) { $allWaves[$name] } else { "-" }
        $proto = if ($p.protocol) { $p.protocol.ToUpper() } else { "FTP" }
        Write-Host ("{0,-20} {1,-10} {2,-25} {3,-8}" -f $name, $wave, $p.host, $proto)
    }
    return
} else {
    $TargetNames = $PilotWave + $MidWave + $FullWave
}

if ($TargetNames.Count -eq 0) { Write-Host "ERROR: No targets specified"; exit 1 }

# File list
$ExcludePatterns = @(
    ".agents", ".github", ".vscode", ".git", ".DS_Store", ".gitignore",
    "assets", "scripts", "vendor", "desktop.ini", "docker-compose",
    "nginx.conf", "*.md", "plans", "tests", "deploy", "__pycache__", "*.pyc"
)

function Should-Exclude($relPath) {
    $parts = $relPath.Split('/')
    foreach ($pat in $ExcludePatterns) {
        if ($pat -like '*.*' -and $relPath -like $pat) { return $true }
        if ($parts -contains $pat) { return $true }
    }
    return $false
}

function Get-FileList {
    $files = @()
    $items = Get-ChildItem $RepoRoot -Recurse -File
    foreach ($item in $items) {
        $rel = $item.FullName.Substring($RepoRoot.Length + 1).Replace('\', '/')
        if (-not (Should-Exclude $rel)) { $files += $rel }
    }
    return $files
}

# FTP helpers
function Ensure-FtpDir($ftpHost, $username, $password, $remoteDir) {
    $parts = $remoteDir.Split('/', [StringSplitOptions]::RemoveEmptyEntries)
    $current = ""
    foreach ($part in $parts) {
        $current += "/$part"
        try {
            $req = [System.Net.FtpWebRequest]::Create("ftp://$ftpHost$current/")
            $req.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
            $req.Credentials = New-Object System.Net.NetworkCredential($username, $password)
            $req.UsePassive = $true
            $req.Timeout = 10000
            $resp = $req.GetResponse()
            $resp.Close()
        } catch {
            # 550 = already exists, ignore
        }
    }
}

function Upload-FtpFile($ftpHost, $username, $password, $localFile, $remoteFilePath) {
    try {
        $req = [System.Net.FtpWebRequest]::Create("ftp://$ftpHost/$remoteFilePath")
        $req.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
        $req.Credentials = New-Object System.Net.NetworkCredential($username, $password)
        $req.UsePassive = $true
        $req.UseBinary = $true
        $req.KeepAlive = $false
        $req.Timeout = 30000

        $fileBytes = [System.IO.File]::ReadAllBytes($localFile)
        $req.ContentLength = $fileBytes.Length

        $stream = $req.GetRequestStream()
        $stream.Write($fileBytes, 0, $fileBytes.Length)
        $stream.Close()

        $resp = $req.GetResponse()
        $resp.Close()
        return $true
    } catch {
        return $false
    }
}

$script:ShowFtpErrors = $true

# Deploy to single site
function Deploy-Site($siteName, $profile) {
    $hostname = $profile.host
    $username = $profile.username
    $remotePath = $profile.remotePath
    $protocol = if ($profile.protocol) { $profile.protocol } else { "ftp" }
    $port = if ($profile.port) { [int]$profile.port } else { 21 }
    $envKey = "UTM_FTP_$($siteName.ToUpper())_PASSWORD"
    $password = $Passwords[$envKey]

    if (-not $password -and $protocol -ne "sftp") {
        Write-Host "  [!] No password for $siteName (set $envKey in .env)"
        return $false
    }

    if ($protocol -eq "sftp") {
        Write-Host "  [..] $siteName (SFTP) -- use deploy.py for SFTP targets"
        return $false
    }

    Write-Host "  [..] $siteName (FTP -> $hostname`:$port)" -NoNewline

    $tnc = Test-NetConnection $hostname -Port $port -WarningAction SilentlyContinue -InformationLevel Quiet 2>$null
    if (-not $tnc) {
        Write-Host " [SKIP] port unreachable"
        return $false
    }
    Write-Host ""

    if ($DryRun) {
        $files = Get-FileList
        Write-Host "    Files to deploy: $($files.Count) (dry-run)"
        return $true
    }

    $files = Get-FileList
    $uploaded = 0
    $failed = 0
    $firstError = $true

    foreach ($relFile in $files) {
        $localFile = Join-Path $RepoRoot $relFile.Replace('/', '\')
        $remoteFile = $relFile.Replace('\', '/')

        $dir = [System.IO.Path]::GetDirectoryName($remoteFile).Replace('\', '/')
        if ($dir) {
            Ensure-FtpDir $hostname $username $password "$remotePath/$dir"
        }

        if (Upload-FtpFile $hostname $username $password $localFile "$remotePath/$remoteFile") {
            $uploaded++
        } else {
            if ($firstError) {
                # Show first error then suppress the rest
                $firstError = $false
            }
            $failed++
        }

        if (($uploaded + $failed) % 30 -eq 0) {
            Write-Host "    Progress: $($uploaded+$failed)/$($files.Count) files"
        }

        Start-Sleep -Milliseconds 50
    }

    Write-Host "    Done: $uploaded/$($files.Count) files to $siteName"
    if ($failed -gt 0) { Write-Host "    Warnings: $failed files failed" }
    return ($failed -eq 0)
}

# Main
Write-Host "`n  UTM Webmaster Tool v$PluginVersion"
$waveLabel = if ($Target) { $Target } else { "$Wave wave" }
Write-Host "  Deploying to $($TargetNames.Count) site(s) ($waveLabel)"
Write-Host ""

$ok = 0; $fail = 0
foreach ($name in $TargetNames) {
    $profile = $Profiles[$name]
    if (-not $profile) { Write-Host "  [?] $name -- not in sftp.json"; continue }
    Write-Host ""
    if (Deploy-Site $name $profile) { $ok++ } else { $fail++ }
}

Write-Host "`n  ======================="
Write-Host "  OK: $ok  |  FAIL: $fail"
if ($fail -gt 0 -and -not $DryRun) { exit 1 }
