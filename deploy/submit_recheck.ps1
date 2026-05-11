$pythonExe = "C:\Users\sharu\miniconda3\python.exe"
$deployPy = "D:\tools\utm-deploy\deploy\deploy.py"

$result = & $pythonExe $deployPy "--verify-only" "--target" "studentaffairs" 2>&1
$result | ForEach-Object { Write-Host $_ }

Write-Host "`n=== Quick re-check of IP-host sites ==="
$result2 = & $pythonExe $deployPy "--verify-only" "--target" "fke" 2>&1
$result2 | ForEach-Object { Write-Host $_ }

$result3 = & $pythonExe $deployPy "--verify-only" "--target" "kl" 2>&1
$result3 | ForEach-Object { Write-Host $_ }
