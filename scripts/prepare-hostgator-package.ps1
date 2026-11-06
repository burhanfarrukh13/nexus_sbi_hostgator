<#
.SYNOPSIS
Builds the two HostGator deployment archives from this repository.

.DESCRIPTION
Produces, in -OutputDir (default: artifacts\):
  nexus-api.zip  Laravel API built with `composer install --no-dev --optimize-autoloader`.
                 Extract it OUTSIDE the web root (for example /home/ACCOUNT/nexus-api) and point the
                 API subdomain's document root at its public/ folder.
  nexus-web.zip  Static React build. Extract it into the static site's document root.

The API archive is assembled from an explicit allow-list into a clean staging folder; nothing is
copied wholesale from backend\. The real .env, private uploads, logs, tests and caches can never
be included, and the script fails if one of them shows up.

.PARAMETER ApiUrl
Public origin of the deployed API, e.g. https://api.yourdomain.com (no trailing slash, no /api).
It is compiled into the browser bundle, so it is required and checked.

.EXAMPLE
.\prepare-hostgator-package.ps1 -ApiUrl https://api.yourdomain.com
#>
param(
    [Parameter(Mandatory = $true)]
    [string]$ApiUrl,
    # Where the two zip files (and the checklist) are written. Defaults to <repo>\artifacts.
    [string]$OutputDir,
    # Optional path to composer, composer.bat or composer.phar when it is not on PATH.
    [string]$ComposerPath,
    # Pass --ignore-platform-reqs to composer (only if THIS machine lacks PHP extensions that HostGator has).
    [switch]$IgnorePlatformReqs,
    # Reuse web\dist instead of rebuilding (it must already be built for -ApiUrl).
    [switch]$SkipWebBuild,
    # Optional Cloudflare Worker URL for Zero Server Load AI Notebook chat
    [string]$AiWorkerUrl = ''
)

$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

# ---------------------------------------------------------------------------------------------
# Input checks
# ---------------------------------------------------------------------------------------------
$ApiUrl = $ApiUrl.Trim().TrimEnd('/')
if ($ApiUrl -notmatch '^https://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d+)?$') {
    throw "ApiUrl must be an https:// origin without a path, for example https://api.yourdomain.com (got '$ApiUrl'). Do not add /api."
}
if ($ApiUrl -match '://(localhost|127\.0\.0\.1|([^/]*\.)?example\.(com|org|net))(:\d+)?$') {
    throw "ApiUrl '$ApiUrl' is a local or placeholder address and cannot be used for a HostGator build."
}

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$backendRoot = Join-Path $root 'backend'
$webRoot = Join-Path $root 'web'
if (-not (Test-Path -LiteralPath $backendRoot)) { throw "Backend directory not found: $backendRoot" }
if (-not (Test-Path -LiteralPath $webRoot)) { throw "Web directory not found: $webRoot" }

if (-not $OutputDir) { $OutputDir = Join-Path $root 'artifacts' }
$OutputDir = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($OutputDir)
$stagingRoot = Join-Path $OutputDir 'staging'
$apiStage = Join-Path $stagingRoot 'api'
$webStage = Join-Path $stagingRoot 'web'
$apiZip = Join-Path $OutputDir 'nexus-api.zip'
$webZip = Join-Path $OutputDir 'nexus-web.zip'
$checklistPath = Join-Path $OutputDir 'PACKAGE-CHECKLIST.txt'

# ---------------------------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------------------------
$report = New-Object System.Collections.Generic.List[string]
function Say([string]$Text, [string]$Color = '') {
    $report.Add($Text)
    if ($Color) { Write-Host $Text -ForegroundColor $Color } else { Write-Host $Text }
}

function Resolve-Composer([string]$Explicit) {
    if ($Explicit) {
        if (-not (Test-Path -LiteralPath $Explicit)) { throw "Composer was not found at '$Explicit'." }
        $full = (Resolve-Path -LiteralPath $Explicit).Path
        if ($full -like '*.phar') { return @('php', $full) }
        return @($full)
    }
    $command = Get-Command composer -ErrorAction SilentlyContinue
    if ($command) { return @($command.Source) }
    $phar = Join-Path $backendRoot 'composer.phar'
    if (Test-Path -LiteralPath $phar) { return @('php', $phar) }
    throw 'Composer was not found. Install it from https://getcomposer.org (needs PHP 8.2+ on PATH) or pass -ComposerPath C:\path\to\composer.bat'
}

function Get-RelativeFiles([string]$Folder) {
    $base = (Resolve-Path -LiteralPath $Folder).Path.TrimEnd('\')
    $files = Get-ChildItem -LiteralPath $base -Recurse -Force -File
    $names = foreach ($file in $files) { $file.FullName.Substring($base.Length + 1).Replace('\', '/') }
    return ,@($names | Sort-Object)
}

# Entry names always use "/" (Compress-Archive in Windows PowerShell 5.1 writes "\", which breaks on Linux).
function New-ZipArchive([string]$Folder, [string[]]$Entries, [string]$ZipPath) {
    if (Test-Path -LiteralPath $ZipPath) { Remove-Item -LiteralPath $ZipPath -Force }
    $base = (Resolve-Path -LiteralPath $Folder).Path.TrimEnd('\')
    $stream = [System.IO.File]::Open($ZipPath, [System.IO.FileMode]::Create)
    try {
        $zip = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create)
        try {
            foreach ($entry in $Entries) {
                $source = Join-Path $base $entry.Replace('/', '\')
                [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $source, $entry, [System.IO.Compression.CompressionLevel]::Optimal)
            }
        }
        finally { $zip.Dispose() }
    }
    finally { $stream.Dispose() }
}

# Prints one line per excluded item and returns how many of them were found in the package by mistake.
function Test-Excluded([string]$Title, [string[]]$Entries, [object[]]$Rules) {
    Say ''
    Say "$Title - files that must NOT be in the archive:"
    $violations = 0
    foreach ($rule in $Rules) {
        $hits = @($Entries | Where-Object { $_ -match $rule.Pattern })
        if ($hits.Count -eq 0) {
            Say ("  [OK]   absent: " + $rule.Label) 'Green'
        }
        else {
            $violations++
            Say ("  [FAIL] FOUND:  " + $rule.Label) 'Red'
            $hits | Select-Object -First 5 | ForEach-Object { Say "           $_" 'Red' }
        }
    }
    return $violations
}

function Test-Present([string]$Title, [string[]]$Entries, [string[]]$Required) {
    Say ''
    Say "$Title - files that MUST be in the archive:"
    $missing = 0
    foreach ($item in $Required) {
        if ($Entries -contains $item) { Say "  [OK]   present: $item" 'Green' }
        else { $missing++; Say "  [FAIL] MISSING: $item" 'Red' }
    }
    return $missing
}

function New-Placeholder([string]$Folder) {
    New-Item -ItemType Directory -Path $Folder -Force | Out-Null
    # Same convention as Laravel: keeps the empty folder in the archive without shipping its contents.
    [System.IO.File]::WriteAllText((Join-Path $Folder '.gitignore'), "*`n!.gitignore`n")
}

# ---------------------------------------------------------------------------------------------
# Tool checks (fail before any slow work)
# ---------------------------------------------------------------------------------------------
$composer = @(Resolve-Composer $ComposerPath)
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw 'php was not found on PATH. Composer needs PHP 8.2+ to build the API package.'
}

# A failed run must never leave stale archives behind.
if (Test-Path -LiteralPath $stagingRoot) { Remove-Item -LiteralPath $stagingRoot -Recurse -Force }
foreach ($old in @($apiZip, $webZip, $checklistPath)) {
    if (Test-Path -LiteralPath $old) { Remove-Item -LiteralPath $old -Force }
}
New-Item -ItemType Directory -Path $apiStage -Force | Out-Null
New-Item -ItemType Directory -Path $webStage -Force | Out-Null

# ---------------------------------------------------------------------------------------------
# 1. API: allow-list copy into a clean staging folder
# ---------------------------------------------------------------------------------------------
Write-Host 'Staging the API from the allow-list...'

$apiAllowedFolders = @('app', 'config', 'database/migrations', 'public', 'routes')
$apiAllowedFiles = @('artisan', 'composer.json', 'composer.lock', '.env.example', 'bootstrap/app.php', 'bootstrap/providers.php')
# Empty folders Laravel writes to at runtime (contents are never shipped).
$apiRuntimeFolders = @('bootstrap/cache', 'storage/app', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs')

foreach ($relative in $apiAllowedFolders) {
    $source = Join-Path $backendRoot $relative.Replace('/', '\')
    if (-not (Test-Path -LiteralPath $source)) { throw "Allow-listed backend folder is missing: backend/$relative" }
    $target = Join-Path $apiStage $relative.Replace('/', '\')
    New-Item -ItemType Directory -Path (Split-Path -Parent $target) -Force | Out-Null
    Copy-Item -LiteralPath $source -Destination $target -Recurse -Force
}
foreach ($relative in $apiAllowedFiles) {
    $source = Join-Path $backendRoot $relative.Replace('/', '\')
    if (-not (Test-Path -LiteralPath $source)) { throw "Allow-listed backend file is missing: backend/$relative" }
    $target = Join-Path $apiStage $relative.Replace('/', '\')
    New-Item -ItemType Directory -Path (Split-Path -Parent $target) -Force | Out-Null
    Copy-Item -LiteralPath $source -Destination $target -Force
}
# Local-only artefacts that can sit inside allow-listed folders.
foreach ($junk in @('public\hot', 'public\storage')) {
    $path = Join-Path $apiStage $junk
    if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path -Recurse -Force }
}
foreach ($relative in $apiRuntimeFolders) {
    New-Placeholder (Join-Path $apiStage $relative.Replace('/', '\'))
}

# ---------------------------------------------------------------------------------------------
# 2. API: production-only dependencies and a fresh bootstrap cache
# ---------------------------------------------------------------------------------------------
Write-Host 'Installing production dependencies (composer install --no-dev --optimize-autoloader)...'

# The local cache can reference packages that will not exist on the server. Start from nothing.
Get-ChildItem -LiteralPath (Join-Path $apiStage 'bootstrap\cache') -Filter '*.php' -Force -ErrorAction SilentlyContinue |
    Remove-Item -Force

$composerExe = $composer[0]
$composerPrefix = @()
if ($composer.Count -gt 1) { $composerPrefix = $composer[1..($composer.Count - 1)] }
$installArgs = @('install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--prefer-dist')
if ($IgnorePlatformReqs) { $installArgs += '--ignore-platform-reqs' }

Push-Location $apiStage
try {
    & $composerExe @composerPrefix @installArgs
    if ($LASTEXITCODE -ne 0) { throw "composer install failed (exit code $LASTEXITCODE)." }

    # composer's post-autoload-dump normally regenerates the cache; make sure it really happened.
    if (-not ((Test-Path -LiteralPath 'bootstrap\cache\packages.php') -and (Test-Path -LiteralPath 'bootstrap\cache\services.php'))) {
        & php artisan package:discover --ansi
        if ($LASTEXITCODE -ne 0) { throw "php artisan package:discover failed (exit code $LASTEXITCODE)." }
    }
}
finally {
    Pop-Location
}

foreach ($cacheFile in @('packages.php', 'services.php')) {
    $path = Join-Path $apiStage "bootstrap\cache\$cacheFile"
    if (-not (Test-Path -LiteralPath $path)) { throw "bootstrap/cache/$cacheFile was not regenerated." }
    if ([System.IO.File]::ReadAllText($path) -match '(?i)phpunit|mockery|collision|laravel/pail|laravel/sail') {
        throw "bootstrap/cache/$cacheFile still references a dev-only package."
    }
}

# ---------------------------------------------------------------------------------------------
# 3. Web: build against the real API URL and verify the bundle
# ---------------------------------------------------------------------------------------------
if (-not $SkipWebBuild) {
    Write-Host "Building the static web app against $ApiUrl ..."
    Push-Location $webRoot
    try {
        npm ci
        if ($LASTEXITCODE -ne 0) { throw 'npm ci failed.' }
        # A process environment variable overrides any VITE_API_BASE_URL in a .env file.
        $env:VITE_API_BASE_URL = $ApiUrl
        if ($AiWorkerUrl) { $env:VITE_AI_WORKER_URL = $AiWorkerUrl.Trim().TrimEnd('/') }
        npm run build
        if ($LASTEXITCODE -ne 0) { throw 'npm run build failed.' }
    }
    finally {
        Remove-Item Env:\VITE_API_BASE_URL -ErrorAction SilentlyContinue
        Remove-Item Env:\VITE_AI_WORKER_URL -ErrorAction SilentlyContinue
        Pop-Location
    }
}

$webDist = Join-Path $webRoot 'dist'
if (-not (Test-Path -LiteralPath $webDist)) {
    throw "Static web build was not found at $webDist. Run the web build before packaging or use -SkipWebBuild only when the dist directory already exists."
}

# A stale dist can still hold a local URL.
$bundleFiles = Get-ChildItem (Join-Path $webDist 'assets') -Filter '*.js' -Recurse -ErrorAction SilentlyContinue
if (-not $bundleFiles) { throw "No JavaScript bundle found in $webDist\assets." }
$bundleHasApiUrl = $false
foreach ($bundle in $bundleFiles) {
    $bundleText = [System.IO.File]::ReadAllText($bundle.FullName)
    if ($bundleText -match '127\.0\.0\.1:8000|localhost:8000|api\.example\.com') {
        throw "The web build contains a local or placeholder API address ($($bundle.Name)). Rebuild without -SkipWebBuild."
    }
    if ($bundleText.Contains($ApiUrl)) { $bundleHasApiUrl = $true }
}
if (-not $bundleHasApiUrl) {
    throw "The web build does not contain $ApiUrl. It was built for a different API; rebuild without -SkipWebBuild."
}

Copy-Item -Path (Join-Path $webDist '*') -Destination $webStage -Recurse -Force
# Netlify-only redirect file and source maps are of no use on Apache.
Get-ChildItem -LiteralPath $webStage -Recurse -Force -File |
    Where-Object { $_.Name -eq '_redirects' -or $_.Extension -eq '.map' } |
    Remove-Item -Force

$webHtaccess = Join-Path $webStage '.htaccess'
if (-not (Test-Path -LiteralPath $webHtaccess)) {
    throw 'web/dist/.htaccess is missing; the SPA fallback, HTTPS redirect and security headers would not apply.'
}
$htaccessText = [System.IO.File]::ReadAllText($webHtaccess)
# Narrow the Content-Security-Policy to the API origin this build talks to.
$allowedConnect = if ($AiWorkerUrl) { "connect-src 'self' $ApiUrl $AiWorkerUrl" } else { "connect-src 'self' $ApiUrl" }
$narrowed = $htaccessText.Replace("connect-src 'self' https:", $allowedConnect)
if ($narrowed -eq $htaccessText) {
    Write-Warning 'Could not narrow connect-src in .htaccess; it keeps the generic https: rule.'
}
else {
    [System.IO.File]::WriteAllText($webHtaccess, $narrowed)
}

# ---------------------------------------------------------------------------------------------
# 4. Verify exactly what will be zipped, then zip
# ---------------------------------------------------------------------------------------------
$apiEntries = Get-RelativeFiles $apiStage
$webEntries = Get-RelativeFiles $webStage

$apiRules = @(
    @{ Label = 'backend/.env (real keys and passwords)'; Pattern = '^(?!vendor/)(.*/)?\.env$' },
    @{ Label = 'other .env.* variants (only .env.example is allowed)'; Pattern = '^(?!vendor/)(.*/)?\.env\.(?!example$)' },
    @{ Label = 'storage/private/* (student papers and uploads)'; Pattern = '^storage/private/' },
    @{ Label = 'storage/logs/*'; Pattern = '^storage/logs/(?!\.gitignore$)' },
    @{ Label = 'storage/framework/testing'; Pattern = '^storage/framework/testing/' },
    @{ Label = 'storage/framework cache, sessions and compiled views'; Pattern = '^storage/framework/(cache/data|sessions|views)/(?!\.gitignore$)' },
    @{ Label = '.phpunit.result.cache'; Pattern = '(^|/)\.phpunit\.result\.cache$' },
    @{ Label = 'route-list-folders.json'; Pattern = '(^|/)route-list-folders\.json$' },
    @{ Label = '.freebuff'; Pattern = '(^|/)\.freebuff(/|$)' },
    @{ Label = '.git'; Pattern = '(^|/)\.git(/|$)' },
    @{ Label = 'tests/'; Pattern = '^tests/' },
    @{ Label = 'phpunit.xml'; Pattern = '(^|/)phpunit\.xml(\.dist)?$' },
    @{ Label = 'dev packages (vendor/phpunit, vendor/mockery, vendor/hamcrest)'; Pattern = '^vendor/(phpunit|mockery|hamcrest)/' },
    @{ Label = 'public/hot and public/storage'; Pattern = '^public/(hot|storage)(/|$)' }
)
$apiRequired = @(
    'artisan', 'composer.json', 'composer.lock', '.env.example',
    'public/index.php', 'public/.htaccess',
    'bootstrap/app.php', 'bootstrap/providers.php',
    'bootstrap/cache/packages.php', 'bootstrap/cache/services.php',
    'vendor/autoload.php', 'vendor/laravel/framework/composer.json'
)
$webRules = @(
    @{ Label = 'source maps (*.map)'; Pattern = '\.map$' },
    @{ Label = '_redirects (Netlify only)'; Pattern = '(^|/)_redirects$' },
    @{ Label = '.env files'; Pattern = '(^|/)\.env' },
    @{ Label = 'node_modules'; Pattern = '(^|/)node_modules/' }
)
$webRequired = @('index.html', '.htaccess')

Say ''
Say '==================== PACKAGE CHECKLIST ===================='
Say ("Built " + (Get-Date -Format 'yyyy-MM-dd HH:mm') + " for API " + $ApiUrl)

$problems = 0
$problems += Test-Excluded 'API archive' $apiEntries $apiRules
$problems += Test-Present 'API archive' $apiEntries $apiRequired
$problems += Test-Excluded 'Web archive' $webEntries $webRules
$problems += Test-Present 'Web archive' $webEntries $webRequired
if ($problems -gt 0) {
    $report | Set-Content -LiteralPath (Join-Path $stagingRoot 'FAILED-CHECKLIST.txt')
    throw "$problems packaging check(s) failed (see above). No archives were created."
}

New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
New-ZipArchive $apiStage $apiEntries $apiZip
New-ZipArchive $webStage $webEntries $webZip

function Format-Size([string]$Path) { '{0:N1} MB' -f ((Get-Item -LiteralPath $Path).Length / 1MB) }
Say ''
Say 'Created:'
Say ("  {0}  ({1}, {2} files, SHA256 {3})" -f $apiZip, (Format-Size $apiZip), $apiEntries.Count, (Get-FileHash -LiteralPath $apiZip -Algorithm SHA256).Hash)
Say ("  {0}  ({1}, {2} files, SHA256 {3})" -f $webZip, (Format-Size $webZip), $webEntries.Count, (Get-FileHash -LiteralPath $webZip -Algorithm SHA256).Hash)
Say ''
Say 'Next steps:'
Say '  1. Extract nexus-api.zip OUTSIDE any web root, for example /home/ACCOUNT/nexus-api, and point the API subdomain document root at nexus-api/public.'
Say '  2. Extract nexus-web.zip into the static site document root (for example public_html).'
Say '  3. Create the production .env on the server from .env.example (never reuse the local one): APP_ENV=production, APP_DEBUG=false, new APP_KEY,'
Say '     new JWT_* and STORAGE_SIGNING_SECRET, real DB_*, PRIVATE_STORAGE_PATH=/home/ACCOUNT/private/nexus and CORS_ALLOWED_ORIGINS set to the static site''s exact https origin.'
Say '  4. Follow docs/HOSTGATOR-DEPLOYMENT.md for the database import, admin creation and the 15-minute cron.'

$report | Set-Content -LiteralPath $checklistPath
Remove-Item -LiteralPath $stagingRoot -Recurse -Force
Write-Host ''
Write-Host "Checklist saved to $checklistPath"
