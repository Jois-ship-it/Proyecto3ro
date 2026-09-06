# ============================================================
# stop_tunnel.ps1 — Detiene el Cloudflare Tunnel de FlexArena
# Uso:  powershell -ExecutionPolicy Bypass -File scripts\stop_tunnel.ps1
# ============================================================

$proc = Get-Process cloudflared -ErrorAction SilentlyContinue
if ($proc) {
    Stop-Process -Name cloudflared -Force
    Write-Host "Túnel detenido (cloudflared finalizado)."
} else {
    Write-Host "No hay ningún túnel cloudflared corriendo."
}
