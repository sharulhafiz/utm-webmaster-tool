Write-Host "=== studentaffairs version check ==="
try {
    $url = "https://studentaffairs.utm.my/wp-json/utm-webmaster/v1/version"
    $req = [System.Net.WebRequest]::Create($url)
    $req.Timeout = 15000
    $resp = $req.GetResponse()
    $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
    $body = $reader.ReadToEnd()
    Write-Host "Response: $body"
    $resp.Close()
} catch {
    Write-Host "FAIL: $($_.Exception.Message)"
}

Write-Host "`n=== kl version check ==="
try {
    $url = "https://kl.utm.my/wp-json/utm-webmaster/v1/version"
    $req = [System.Net.WebRequest]::Create($url)
    $req.Timeout = 15000
    $resp = $req.GetResponse()
    $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
    $body = $reader.ReadToEnd()
    Write-Host "Response: $body"
    $resp.Close()
} catch {
    Write-Host "FAIL: $($_.Exception.Message)"
}

Write-Host "`n=== Check studentaffairs plugin dir via SSH ==="
ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 www2 "ls -la /var/www/vhosts/studentaffairs.utm.my/httpdocs/wp-content/plugins/utm-webmaster-tool/index.php 2>&1 | head -3"
