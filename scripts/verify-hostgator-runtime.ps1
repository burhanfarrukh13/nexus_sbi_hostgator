param(
    [string]$PhpExecutable = ''
)

$ErrorActionPreference = 'Stop'

$phpCandidates = @(
    $PhpExecutable,
    'php',
    'C:\laragon\bin\php\php-8.2.*\php.exe',
    'C:\laragon\bin\php\php-8.3.*\php.exe',
    'C:\laragon\bin\php\php-8.4.*\php.exe',
    'C:\Users\user\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe',
    'C:\Program Files\PHP\php.exe',
    'C:\Program Files\PHP-8.2\php.exe'
)

$phpPath = $null
foreach ($candidate in $phpCandidates) {
    if (-not $candidate) { continue }
    if ($candidate -match '\*') {
        $matches = Get-ChildItem -Path $candidate -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($matches) { $phpPath = $matches.FullName; break }
        continue
    }
    if (Test-Path $candidate) {
        $phpPath = $candidate
        break
    }
    $resolved = Get-Command $candidate -ErrorAction SilentlyContinue
    if ($resolved) { $phpPath = $resolved.Source; break }
}

$required = @(
    'openssl',
    'pdo_mysql',
    'mbstring',
    'fileinfo',
    'curl',
    'xml',
    'zip',
    'dom',
    'json',
    'session',
    'tokenizer',
    'ctype',
    'filter',
    'hash',
    'pdo',
    'pdo_sqlite'
)

if (-not $phpPath) {
    Write-Host 'PHP runtime not found. Install PHP 8.2+ with the required extensions before running Composer or Laravel.' -ForegroundColor Yellow
    exit 1
}

Write-Host "Using PHP: $phpPath"
$phpDir = Split-Path -Parent $phpPath
$extDir = Join-Path $phpDir 'ext'
$missing = @()
foreach ($ext in $required) {
    $dllName = if ($ext -eq 'pdo_sqlite') { 'php_pdo_sqlite.dll' } elseif ($ext -eq 'pdo_mysql') { 'php_pdo_mysql.dll' } elseif ($ext -eq 'openssl') { 'php_openssl.dll' } elseif ($ext -eq 'curl') { 'php_curl.dll' } elseif ($ext -eq 'mbstring') { 'php_mbstring.dll' } elseif ($ext -eq 'fileinfo') { 'php_fileinfo.dll' } elseif ($ext -eq 'zip') { 'php_zip.dll' } elseif ($ext -eq 'xml') { 'php_xml.dll' } elseif ($ext -eq 'dom') { 'php_dom.dll' } elseif ($ext -eq 'json') { 'php_json.dll' } elseif ($ext -eq 'session') { 'php_session.dll' } elseif ($ext -eq 'tokenizer') { 'php_tokenizer.dll' } elseif ($ext -eq 'ctype') { 'php_ctype.dll' } elseif ($ext -eq 'filter') { 'php_filter.dll' } elseif ($ext -eq 'hash') { 'php_hash.dll' } elseif ($ext -eq 'pdo') { 'php_pdo.dll' } else { "php_$ext.dll" }

    if (-not (Test-Path (Join-Path $extDir $dllName))) {
        $missing += $ext
    }
}

if ($missing.Count -gt 0) {
    Write-Host 'Missing required PHP extensions:' -ForegroundColor Yellow
    $missing | ForEach-Object { Write-Host "  - $_" }
    exit 2
}

Write-Host 'PHP runtime check passed. Required extensions are available.' -ForegroundColor Green
exit 0
