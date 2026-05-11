$pythonExe = "C:\Users\sharu\miniconda3\python.exe"
$deployPy = "D:\tools\utm-deploy\deploy\deploy.py"

Write-Host "=== FULL WAVE ==="
$result = & $pythonExe $deployPy "--verify-only" "--wave" "full" 2>&1
$result | ForEach-Object { Write-Host $_ }

Write-Host "`n=== MID WAVE ==="
$result2 = & $pythonExe $deployPy "--verify-only" "--wave" "mid" 2>&1
$result2 | ForEach-Object { Write-Host $_ }

Write-Host "`n=== PILOT WAVE ==="
$result3 = & $pythonExe $deployPy "--verify-only" "--wave" "pilot" 2>&1
$result3 | ForEach-Object { Write-Host $_ }
