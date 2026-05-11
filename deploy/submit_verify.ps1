$pythonExe = "C:\Users\sharu\miniconda3\python.exe"
$deployPy = "D:\tools\utm-deploy\deploy\deploy.py"

$result = & $pythonExe $deployPy "--verify-only" "--wave" "full" 2>&1
Write-Host "=== FULL WAVE ==="
$result | ForEach-Object { Write-Host $_ }

$result2 = & $pythonExe $deployPy "--verify-only" "--wave" "mid" 2>&1
Write-Host "=== MID WAVE ==="
$result2 | ForEach-Object { Write-Host $_ }

$result3 = & $pythonExe $deployPy "--verify-only" "--wave" "pilot" 2>&1
Write-Host "=== PILOT WAVE ==="
$result3 | ForEach-Object { Write-Host $_ }
