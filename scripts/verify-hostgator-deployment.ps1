<#
.SYNOPSIS
  Checks a live HostGator deployment from the outside (run it from your own PC after uploading).

.EXAMPLE
  .\scripts\verify-hostgator-deployment.ps1 -ApiUrl https://api.school.edu -SiteUrl https://www.school.edu

  -Origin defaults to the site URL and must match CORS_ALLOWED_ORIGINS exactly.
  Add -Email/-Password (a throw-away account) to also test a real login.
  Exit code 1 = at least one FAIL.
#>
param(
    [Parameter(Mandatory = $true)][string]$ApiUrl,
    [Parameter(Mandatory = $true)][string]$SiteUrl,
    [string]$Origin = '',
    [string]$Email = '',
    [string]$Password = '',
    [switch]$AllowHttp
)

$ErrorActionPreference = 'Stop'
$ApiUrl = $ApiUrl.TrimEnd('/')
$SiteUrl = $SiteUrl.TrimEnd('/')
if (-not $Origin) { $Origin = ([uri]$SiteUrl).GetLeftPart([UriPartial]::Authority) }
if (-not $AllowHttp -and ($ApiUrl -notmatch '^https://' -or $SiteUrl -notmatch '^https://')) {
    Write-Host 'ApiUrl and SiteUrl must start with https:// (use -AllowHttp only for a local rehearsal).' -ForegroundColor Red
    exit 2
}

Add-Type -AssemblyName System.Net.Http
$handler = New-Object System.Net.Http.HttpClientHandler
$handler.AllowAutoRedirect = $false
$client = New-Object System.Net.Http.HttpClient($handler)
$client.Timeout = [TimeSpan]::FromSeconds(30)

function Send-Request {
    param([string]$Method, [string]$Url, [hashtable]$Headers = @{}, [string]$Body = $null)
    $request = New-Object System.Net.Http.HttpRequestMessage([System.Net.Http.HttpMethod]::new($Method), $Url)
    foreach ($key in $Headers.Keys) { [void]$request.Headers.TryAddWithoutValidation($key, [string]$Headers[$key]) }
    if ($Body) { $request.Content = New-Object System.Net.Http.StringContent($Body, [Text.Encoding]::UTF8, 'application/json') }
    try {
        $response = $client.SendAsync($request).GetAwaiter().GetResult()
    } catch {
        return [pscustomobject]@{ Status = 0; Headers = @{}; Body = ''; Error = $_.Exception.GetBaseException().Message }
    }
    $headers = @{}
    foreach ($h in $response.Headers) { $headers[$h.Key.ToLower()] = ($h.Value -join ',') }
    foreach ($h in $response.Content.Headers) { $headers[$h.Key.ToLower()] = ($h.Value -join ',') }
    $text = if ($Method -eq 'HEAD') { '' } else { $response.Content.ReadAsStringAsync().GetAwaiter().GetResult() }
    [pscustomobject]@{ Status = [int]$response.StatusCode; Headers = $headers; Body = $text; Error = $null }
}

$script:fails = 0
$script:warns = 0
function Report([string]$Level, [string]$Name, [string]$Detail = '') {
    $color = switch ($Level) { 'PASS' { 'Green' } 'WARN' { 'Yellow' } 'SKIP' { 'DarkGray' } default { 'Red' } }
    if ($Level -eq 'FAIL') { $script:fails++ }
    if ($Level -eq 'WARN') { $script:warns++ }
    Write-Host ('[{0}] {1}{2}' -f $Level, $Name, $(if ($Detail) { " - $Detail" } else { '' })) -ForegroundColor $color
}

Write-Host "API:    $ApiUrl"
Write-Host "Site:   $SiteUrl"
Write-Host "Origin: $Origin`n"

# ---- API ------------------------------------------------------------------
Write-Host '== API ==' -ForegroundColor Cyan
$health = Send-Request GET "$ApiUrl/api/health"
if ($health.Error) { Report FAIL 'Health endpoint' $health.Error }
elseif ($health.Status -eq 200 -and $health.Body -match '"status"\s*:\s*"ok"') { Report PASS 'Health endpoint' '/api/health returns ok (database reachable)' }
elseif ($health.Status -eq 503) { Report FAIL 'Health endpoint' 'API is up but the database is unavailable (check DB_* in .env, then php artisan config:clear)' }
else { Report FAIL 'Health endpoint' "HTTP $($health.Status): $($health.Body.Substring(0, [Math]::Min(120, $health.Body.Length)))" }

if ($ApiUrl -match '^https://') {
    $plain = Send-Request GET (($ApiUrl -replace '^https://', 'http://') + '/api/health')
    if ((@(301, 302, 307, 308) -contains $plain.Status) -and $plain.Headers['location'] -match '^https://') { Report PASS 'API http -> https redirect' }
    elseif ($plain.Status -eq 0) { Report WARN 'API http -> https redirect' 'port 80 not reachable' }
    else { Report WARN 'API http -> https redirect' "HTTP $($plain.Status): enable Force HTTPS Redirect in cPanel" }
}

$allowed = Send-Request OPTIONS "$ApiUrl/api/auth/login" @{ Origin = $Origin; 'Access-Control-Request-Method' = 'POST'; 'Access-Control-Request-Headers' = 'content-type,authorization' }
if ($allowed.Headers['access-control-allow-origin'] -eq $Origin) { Report PASS 'CORS allows the site origin' $Origin }
else { Report FAIL 'CORS allows the site origin' "expected Access-Control-Allow-Origin: $Origin, got '$($allowed.Headers['access-control-allow-origin'])'. Fix CORS_ALLOWED_ORIGINS (exact origin, https, no trailing slash) and run php artisan config:clear" }
$denied = Send-Request OPTIONS "$ApiUrl/api/auth/login" @{ Origin = 'https://evil.example'; 'Access-Control-Request-Method' = 'POST' }
$evilHeader = $denied.Headers['access-control-allow-origin']
if ($evilHeader -and ($evilHeader -eq '*' -or $evilHeader -eq 'https://evil.example')) { Report FAIL 'CORS rejects other origins' "allowed https://evil.example ($evilHeader)" }
else { Report PASS 'CORS rejects other origins' }

$secretPaths = @('/.env', '/.env.example', '/.git/config', '/composer.json', '/composer.lock', '/artisan', '/phpunit.xml',
    '/vendor/autoload.php', '/storage/logs/laravel.log', '/bootstrap/cache/packages.php', '/database.sql',
    '/backend/.env', '/config/app.php', '/app/Http/Controllers/AuthController.php')
$exposed = @()
foreach ($path in $secretPaths) {
    $r = Send-Request GET "$ApiUrl$path"
    if ($r.Status -eq 200 -and $r.Body -notmatch '(?i)<!doctype html|<html') { $exposed += $path }
}
if ($exposed.Count -eq 0) { Report PASS 'API hides .env, vendor, logs, source' ('{0} paths tested' -f $secretPaths.Count) }
else { Report FAIL 'API exposes private files' (($exposed -join ', ') + ' - the API document root must be backend/public') }

$missing = Send-Request GET "$ApiUrl/api/this-route-does-not-exist"
if ($missing.Body -match '(?i)stack trace|vendor[\\/]laravel|Illuminate\\|"trace"|APP_KEY|DB_PASSWORD') { Report FAIL 'Errors do not leak internals' 'error page shows a stack trace or paths (set APP_DEBUG=false and php artisan config:clear)' }
else { Report PASS 'Errors do not leak internals' }

if ($health.Headers['x-content-type-options'] -eq 'nosniff') { Report PASS 'API sends X-Content-Type-Options: nosniff' } else { Report WARN 'API sends X-Content-Type-Options: nosniff' 'header missing' }
if ($ApiUrl -match '^https://') {
    if ($health.Headers['strict-transport-security']) { Report PASS 'API sends HSTS' } else { Report WARN 'API sends HSTS' 'header missing (add it in the API .htaccess or cPanel)' }
}

$unauth = Send-Request GET "$ApiUrl/api/tests/me/attempts"
if ($unauth.Status -eq 401) { Report PASS 'Protected routes require a token' } else { Report FAIL 'Protected routes require a token' "HTTP $($unauth.Status) without a token" }

if ($Email -and $Password) {
    $login = Send-Request POST "$ApiUrl/api/auth/login" @{ Origin = $Origin } (@{ email = $Email; password = $Password } | ConvertTo-Json -Compress)
    if ($login.Status -eq 201 -and $login.Body -match 'accessToken') { Report PASS 'Login works' $Email }
    else { Report FAIL 'Login works' "HTTP $($login.Status)" }
}

# ---- Static site ------------------------------------------------------------
Write-Host "`n== Static site ==" -ForegroundColor Cyan
$homePage = Send-Request GET "$SiteUrl/"
if ($homePage.Status -eq 200 -and $homePage.Body -match 'id="root"') { Report PASS 'Site loads the app shell' }
else { Report FAIL 'Site loads the app shell' "HTTP $($homePage.Status)$(if ($homePage.Error) { ': ' + $homePage.Error })" }

if ($SiteUrl -match '^https://') {
    $plain = Send-Request GET (($SiteUrl -replace '^https://', 'http://') + '/')
    if ((@(301, 302, 307, 308) -contains $plain.Status) -and $plain.Headers['location'] -match '^https://') { Report PASS 'Site http -> https redirect' }
    else { Report FAIL 'Site http -> https redirect' "HTTP $($plain.Status): add the HTTPS redirect to the site .htaccess" }
}

foreach ($pair in @(
        @('strict-transport-security', 'HSTS'),
        @('x-content-type-options', 'X-Content-Type-Options'),
        @('content-security-policy', 'Content-Security-Policy'))) {
    if ($homePage.Headers[$pair[0]]) { Report PASS "Site sends $($pair[1])" } else { Report WARN "Site sends $($pair[1])" 'header missing' }
}
if ($homePage.Headers['x-frame-options'] -or $homePage.Headers['content-security-policy'] -match 'frame-ancestors') { Report PASS 'Site blocks framing' } else { Report WARN 'Site blocks framing' 'no X-Frame-Options or frame-ancestors' }
$csp = $homePage.Headers['content-security-policy']
if ($csp -and $csp -notmatch [regex]::Escape(([uri]$ApiUrl).GetLeftPart([UriPartial]::Authority)) -and $csp -match 'connect-src') {
    Report FAIL 'CSP allows the API' "connect-src does not list $ApiUrl, so the browser will block every API call"
}

$siteExposed = @()
foreach ($path in @('/.env', '/.git/config', '/package.json', '/.htaccess.bak', '/database.sql')) {
    $r = Send-Request GET "$SiteUrl$path"
    if ($r.Status -eq 200 -and $r.Body -notmatch '(?i)<!doctype html|<html') { $siteExposed += $path }
}
if ($siteExposed.Count -eq 0) { Report PASS 'Site hides private files' } else { Report FAIL 'Site exposes private files' ($siteExposed -join ', ') }

$deep = Send-Request GET "$SiteUrl/tests/some-deep-link"
if ($deep.Status -eq 200 -and $deep.Body -match 'id="root"') { Report PASS 'Deep links fall back to the app' }
else { Report WARN 'Deep links fall back to the app' "HTTP $($deep.Status): check the SPA rewrite rule in .htaccess" }

$scriptMatch = [regex]::Match($homePage.Body, 'src="([^"]+\.js)"')
if (-not $scriptMatch.Success) {
    Report SKIP 'Built bundle checks' 'no script tag found in index.html'
} else {
    $scriptUrl = if ($scriptMatch.Groups[1].Value -match '^https?://') { $scriptMatch.Groups[1].Value } else { "$SiteUrl/" + $scriptMatch.Groups[1].Value.TrimStart('/') }
    $bundle = Send-Request GET $scriptUrl
    $apiOrigin = ([uri]$ApiUrl).GetLeftPart([UriPartial]::Authority)
    if ($bundle.Status -ne 200) { Report FAIL 'Built bundle is served' "HTTP $($bundle.Status) for $scriptUrl" }
    else {
        if ($bundle.Headers['content-type'] -match 'javascript') { Report PASS 'JavaScript content type' } else { Report FAIL 'JavaScript content type' $bundle.Headers['content-type'] }
        if ($bundle.Body.Contains($apiOrigin)) { Report PASS 'Bundle was built with the real API URL' $apiOrigin }
        else { Report FAIL 'Bundle was built with the real API URL' "$apiOrigin not found in the bundle; rebuild with the API URL" }
        if ($bundle.Body -match '127\.0\.0\.1:8000|localhost:8000|api\.example\.com') { Report FAIL 'Bundle has no local/placeholder URL' 'found 127.0.0.1:8000, localhost:8000 or api.example.com' }
        else { Report PASS 'Bundle has no local/placeholder URL' }
        $worker = [regex]::Match($bundle.Body, '/?assets/[A-Za-z0-9_.\-]*pdf[.\-_]worker[A-Za-z0-9_.\-]*\.m?js')
        if ($worker.Success) {
            $workerUrl = "$SiteUrl/" + $worker.Value.TrimStart('/')
            $w = Send-Request HEAD $workerUrl
            if ($w.Status -eq 200 -and $w.Headers['content-type'] -match 'javascript') { Report PASS 'PDF.js worker content type' $w.Headers['content-type'] }
            elseif ($w.Status -eq 200) { Report FAIL 'PDF.js worker content type' "served as '$($w.Headers['content-type'])': add AddType text/javascript .mjs to .htaccess" }
            else { Report WARN 'PDF.js worker content type' "HTTP $($w.Status) for $workerUrl" }
        } else { Report SKIP 'PDF.js worker content type' 'worker file not referenced from the main bundle' }
    }
}

Write-Host ("`n{0} failed, {1} warning(s)." -f $script:fails, $script:warns) -ForegroundColor $(if ($script:fails) { 'Red' } else { 'Green' })
exit $(if ($script:fails) { 1 } else { 0 })
