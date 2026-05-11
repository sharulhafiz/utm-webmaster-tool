$pythonExe = "C:\Users\sharu\miniconda3\python.exe"
$deployPy = "D:\tools\utm-deploy\deploy\deploy.py"

$result = & $pythonExe $deployPy "--wave" "mid" 2>&1
$exitCode = $LASTEXITCODE
$result | ForEach-Object { Write-Host $_ }
Write-Host "EXIT_CODE: $exitCode"
exit $exitCode
