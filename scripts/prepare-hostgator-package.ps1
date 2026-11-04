param(
    [string]$OutputPath = (Join-Path $PSScriptRoot '..\artifacts\hostgator-package.zip'),
    [switch]$SkipWebBuild
)

$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$backendRoot = Join-Path $root 'backend'
$webRoot = Join-Path $root 'web'
$artifactRoot = Join-Path $root 'artifacts\hostgator-package'

if (-not (Test-Path $backendRoot)) {
    throw "Backend directory not found: $backendRoot"
}

if (-not (Test-Path $webRoot)) {
    throw "Web directory not found: $webRoot"
}

if (-not $SkipWebBuild) {
    Write-Host 'Building static web app for HostGator deployment...'
    Push-Location $webRoot
    try {
        npm ci
        $env:VITE_API_BASE_URL = 'https://api.example.com'
        npm run build
    }
    finally {
        Pop-Location
    }
}

if (Test-Path $artifactRoot) {
    Remove-Item $artifactRoot -Recurse -Force
}

New-Item -ItemType Directory -Path $artifactRoot -Force | Out-Null

Write-Host 'Copying PHP API onto the HostGator package root...'
Copy-Item (Join-Path $backendRoot '*') $artifactRoot -Recurse -Force

$webDist = Join-Path $webRoot 'dist'
if (Test-Path $webDist) {
    Write-Host 'Copying static site bundle into the HostGator package...'
    Copy-Item (Join-Path $webDist '*') (Join-Path $artifactRoot 'web-dist') -Recurse -Force
}
else {
    throw "Static web build was not found at $webDist. Run the web build before packaging or use -SkipWebBuild only when the dist directory already exists."
}

$parent = Split-Path -Parent $OutputPath
if (-not (Test-Path $parent)) {
    New-Item -ItemType Directory -Path $parent -Force | Out-Null
}

if (Test-Path $OutputPath) {
    Remove-Item $OutputPath -Force
}

Compress-Archive -Path (Join-Path $artifactRoot '*') -DestinationPath $OutputPath -Force

Write-Host "HostGator package created at: $OutputPath"
Write-Host 'Next steps:'
Write-Host '  1. Upload the ZIP to the HostGator account or extract it into the correct API/web document roots.'
Write-Host '  2. Set APP_ENV=production, DB_* values, JWT secrets, storage signing secret, and EMBEDDINGS_* keys in the backend .env file.'
Write-Host '  3. Ensure the backend public root points to backend/public and the static site points to web-dist.'
