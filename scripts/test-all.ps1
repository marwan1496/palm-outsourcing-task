<#
.SYNOPSIS
    Runs every test suite across all three services.

.DESCRIPTION
    Go      table-driven tests    (internal/config, internal/pool, internal/httpapi)
    Laravel Pest unit + feature   (against the plam_task_test MySQL database)
    Next.js Vitest + Testing Library

    Also runs the static checks: Laravel Pint, Larastan level 6, and tsc.

    Exits non-zero if any suite fails, so it works as a CI entrypoint.

.EXAMPLE
    .\scripts\test-all.ps1
    .\scripts\test-all.ps1 -SkipStatic
#>

[CmdletBinding()]
param(
    # Run only the tests, skipping Pint / Larastan / tsc.
    [switch]$SkipStatic
)

$root = Split-Path -Parent $PSScriptRoot
$services = Join-Path $root "services"
$failures = @()

function Invoke-Check {
    param([string]$Name, [string]$WorkingDir, [string]$Exe, [string[]]$CmdArgs)

    Write-Host ""
    Write-Host "-> $Name" -ForegroundColor Cyan

    if (-not (Get-Command $Exe -ErrorAction SilentlyContinue) -and -not (Test-Path $Exe)) {
        Write-Host "   skipped - '$Exe' not found" -ForegroundColor Yellow
        return
    }

    Push-Location $WorkingDir
    try {
        & $Exe @CmdArgs
        if ($LASTEXITCODE -ne 0) {
            $script:failures += $Name
            Write-Host "   FAILED" -ForegroundColor Red
        }
        else {
            Write-Host "   passed" -ForegroundColor Green
        }
    }
    finally {
        Pop-Location
    }
}

Write-Host ""
Write-Host "Palm Task - full test suite" -ForegroundColor Cyan
Write-Host "===========================" -ForegroundColor Cyan

# --- Go ----------------------------------------------------------------------
# Note: `go test -race` needs CGO and a C compiler, which a stock Windows
# install does not have. Plain `go test` still exercises the concurrency test.
Invoke-Check "Go tests" (Join-Path $services "proxy-manager") "go" @("test", "-cover", "./...")

if (-not $SkipStatic) {
    Invoke-Check "Go vet" (Join-Path $services "proxy-manager") "go" @("vet", "./...")
}

# --- Laravel -----------------------------------------------------------------
$backend = Join-Path $services "backend"

Invoke-Check "Laravel tests (Pest)" $backend "php" @("vendor/bin/pest")

if (-not $SkipStatic) {
    Invoke-Check "Laravel formatting (Pint)" $backend "php" @("vendor/bin/pint", "--test")
    Invoke-Check "Laravel static analysis (Larastan lvl 6)" $backend "php" @("vendor/bin/phpstan", "analyse", "--memory-limit=1G", "--no-progress")
}

# --- Next.js -----------------------------------------------------------------
$frontend = Join-Path $services "frontend"

Invoke-Check "Frontend tests (Vitest)" $frontend "npm" @("test")

if (-not $SkipStatic) {
    Invoke-Check "Frontend typecheck" $frontend "npm" @("run", "typecheck")
}

# --- Summary -----------------------------------------------------------------
Write-Host ""
Write-Host "===========================" -ForegroundColor Cyan

if ($failures.Count -eq 0) {
    Write-Host "All checks passed." -ForegroundColor Green
    Write-Host ""
    exit 0
}

Write-Host "$($failures.Count) check(s) failed:" -ForegroundColor Red
$failures | ForEach-Object { Write-Host "  - $_" -ForegroundColor Red }
Write-Host ""
exit 1
