$pythonExe = "C:\Users\sharu\miniconda3\python.exe"
$deployPy = "D:\tools\utm-deploy\deploy\deploy.py"

# Monkey-patch timeout: write a temp version with higher timeout for fke
$content = Get-Content $deployPy -Raw
$patched = $content -replace 'timeout=15', 'timeout=45'
$tmpPy = "D:\tools\utm-deploy\deploy\deploy_fke_patched.py"
Set-Content -Path $tmpPy -Value $patched

$result = & $pythonExe $tmpPy "--target" "fke" 2>&1
$exitCode = $LASTEXITCODE
$result | ForEach-Object { Write-Host $_ }
Write-Host "EXIT_CODE: $exitCode"

# Clean up
Remove-Item $tmpPy -Force -ErrorAction SilentlyContinue
exit $exitCode
