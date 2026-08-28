<#
.SYNOPSIS
    Fills the database with products so the grid is never empty during a demo.

.DESCRIPTION
    Uses the model factory rather than live scraping, so this works offline and
    finishes instantly. Live scraping is demonstrated separately with:

        php artisan products:scrape <url>

.EXAMPLE
    .\scripts\seed-demo.ps1
    .\scripts\seed-demo.ps1 -Count 24 -Fresh
#>

[CmdletBinding()]
param(
    # How many products to create.
    [int]$Count = 16,

    # Delete existing products first.
    [switch]$Fresh
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot
$backend = Join-Path $root "services\backend"

Push-Location $backend
try {
    if ($Fresh) {
        Write-Host "Clearing existing products..." -ForegroundColor DarkGray
        php artisan tinker --execute="App\Models\Product::query()->delete();"
    }

    Write-Host "Seeding $Count products..." -ForegroundColor Cyan

    # A mix of both storefronts, plus one without an image so the frontend's
    # placeholder path is visible in the demo too.
    $jumia = [math]::Floor($Count / 2)
    $amazon = $Count - $jumia - 1

    php artisan tinker --execute="
        App\Models\Product::factory()->count($jumia)->jumia()->create();
        App\Models\Product::factory()->count($amazon)->amazon()->create();
        App\Models\Product::factory()->withoutImage()->create();
        echo 'total: ' . App\Models\Product::count() . PHP_EOL;
    "

    Write-Host ""
    Write-Host "Done. Open http://localhost:3000/products" -ForegroundColor Green
    Write-Host ""
}
finally {
    Pop-Location
}
