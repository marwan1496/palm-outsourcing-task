<#
.SYNOPSIS
    Starts all three services for local development.

.DESCRIPTION
    Opens one PowerShell window per service so each keeps its own readable log:

      proxy-manager  http://127.0.0.1:8081   (Go)
      backend        http://127.0.0.1:8000   (Laravel)
      frontend       http://localhost:3000   (Next.js)

    The Go service is optional. Laravel connects directly when it is missing,
    so -SkipProxy is a perfectly normal way to run the stack.

.EXAMPLE
    .\scripts\dev.ps1
    .\scripts\dev.ps1 -SkipProxy
#>

[CmdletBinding()]
param(
    # Do not start the Go proxy-manager. Scraping then connects directly.
    [switch]$SkipProxy,

    # Shared secret between Laravel and the Go service.
    [string]$ProxyKey = "dev-secret-key"
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot
$services = Join-Path $root "services"

function Start-Service-Window {
    param([string]$Title, [string]$WorkingDir, [string]$Command)

    Write-Host "  starting $Title..." -ForegroundColor DarkGray

    Start-Process powershell -ArgumentList @(
        "-NoExit",
        "-Command",
        "`$Host.UI.RawUI.WindowTitle = '$Title'; Set-Location '$WorkingDir'; $Command"
    )
}

Write-Host ""
Write-Host "Palm Task - starting services" -ForegroundColor Cyan
Write-Host ""

# --- Preflight ---------------------------------------------------------------
# Catch the two mistakes that actually happen, with a fix for each, rather than
# letting a service start and fail confusingly.

$backendEnv = Join-Path $services "backend\.env"
if (-not (Test-Path $backendEnv)) {
    Write-Host "  services/backend/.env is missing." -ForegroundColor Red
    Write-Host "  Fix: cd services/backend; cp .env.example .env; php artisan key:generate"
    exit 1
}

$frontendEnv = Join-Path $services "frontend\.env.local"
if (-not (Test-Path $frontendEnv)) {
    Write-Host "  services/frontend/.env.local is missing." -ForegroundColor Red
    Write-Host "  Fix: cd services/frontend; cp .env.local.example .env.local"
    Write-Host "       then add a token from: php artisan api:token frontend"
    exit 1
}

if (-not (Select-String -Path $frontendEnv -Pattern '^BACKEND_API_TOKEN=.+' -Quiet)) {
    Write-Host "  BACKEND_API_TOKEN is empty in services/frontend/.env.local." -ForegroundColor Yellow
    Write-Host "  The product grid will show an auth error until it is set."
    Write-Host "  Fix: cd services/backend; php artisan api:token frontend"
    Write-Host ""
}

# --- Go proxy-manager --------------------------------------------------------
if (-not $SkipProxy) {
    $proxyDir = Join-Path $services "proxy-manager"

    if (-not (Get-Command go -ErrorAction SilentlyContinue)) {
        Write-Host "  Go is not on PATH - skipping proxy-manager." -ForegroundColor Yellow
        Write-Host "  Scraping will connect directly, which is fine."
    }
    else {
        if (-not (Test-Path (Join-Path $proxyDir "proxies.yaml"))) {
            Copy-Item (Join-Path $proxyDir "proxies.example.yaml") (Join-Path $proxyDir "proxies.yaml")
            Write-Host "  created proxies.yaml from the example" -ForegroundColor DarkGray
        }

        Start-Service-Window "proxy-manager :8081" $proxyDir `
            "`$env:PROXY_API_KEY='$ProxyKey'; go run ./cmd/proxyd"
    }
}
else {
    Write-Host "  skipping proxy-manager (-SkipProxy)" -ForegroundColor DarkGray
}

# --- Laravel -----------------------------------------------------------------
Start-Service-Window "backend :8000" (Join-Path $services "backend") `
    "`$env:PROXY_MANAGER_ENABLED='$(if ($SkipProxy) { 'false' } else { 'true' })'; `$env:PROXY_MANAGER_KEY='$ProxyKey'; php artisan serve --port=8000"

# --- Next.js -----------------------------------------------------------------
Start-Service-Window "frontend :3000" (Join-Path $services "frontend") "npm run dev"

Write-Host ""
Write-Host "All services starting. Give them a few seconds, then open:" -ForegroundColor Green
Write-Host ""
Write-Host "    http://localhost:3000/products" -ForegroundColor White
Write-Host ""
Write-Host "Close the individual windows to stop each service." -ForegroundColor DarkGray
Write-Host ""
