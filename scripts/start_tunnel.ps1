# ============================================================
# start_tunnel.ps1 — Inicia un Cloudflare Tunnel efímero para FlexArena
# Fuerza Host: flexarena.local para que Apache sirva el proyecto (no htdocs).
# Uso:  powershell -ExecutionPolicy Bypass -File scripts\start_tunnel.ps1
# ============================================================

$log = Join-Path $PSScriptRoot "..\cf_tunnel.log"
if (Test-Path $log) { Remove-Item $log -Force }

# Asegurar que Apache (XAMPP) esté corriendo
if (-not (Get-Process httpd -ErrorAction SilentlyContinue)) {
    Write-Host "ADVERTENCIA: Apache no parece estar corriendo. Inícialo desde el panel de XAMPP."
}

Start-Process "cloudflared" `
    -ArgumentList 'tunnel','--url','http://localhost:80','--http-host-header','flexarena.local','--no-autoupdate' `
    -RedirectStandardError $log -WindowStyle Hidden

Write-Host "Iniciando túnel… (esperando URL pública)"
Start-Sleep -Seconds 8

$url = [regex]::Match((Get-Content $log -Raw -ErrorAction SilentlyContinue), 'https://[a-z0-9-]+\.trycloudflare\.com').Value
if ($url) {
    Write-Host ""
    Write-Host "  URL PÚBLICA:  $url"
    Write-Host ""
    Write-Host "  Para detener el túnel:  scripts\stop_tunnel.ps1"
} else {
    Write-Host "No se pudo obtener la URL. Revisá cf_tunnel.log"
}
