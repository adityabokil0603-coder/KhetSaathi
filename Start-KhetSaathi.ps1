
$ErrorActionPreference = "Stop"

$projectPath = Join-Path $PSScriptRoot ""
$phpPath = Join-Path $env:USERPROFILE "OneDrive\Documents\php\php.exe"
$envPath = Join-Path $projectPath ".env"
$publicPath = Join-Path $projectPath "public"

if (-not (Test-Path $phpPath)) {
    throw "PHP executable not found: $phpPath"
}

if (-not (Test-Path $envPath)) {
    throw ".env file not found: $envPath"
}

# Load local database settings from .env.
foreach ($line in Get-Content $envPath) {
    $line = $line.Trim()

    if (-not $line -or $line.StartsWith("#")) {
        continue
    }

    if ($line -match '^([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
        [Environment]::SetEnvironmentVariable(
            $matches[1],
            $matches[2],
            "Process"
        )
    }
}

# Verify required settings without displaying the password.
foreach ($name in @(
    "KHETSAATHI_DB_HOST",
    "KHETSAATHI_DB_PORT",
    "KHETSAATHI_DB_NAME",
    "KHETSAATHI_DB_USER",
    "KHETSAATHI_DB_PASSWORD"
)) {
    if ([string]::IsNullOrEmpty([Environment]::GetEnvironmentVariable($name, "Process"))) {
        throw "Missing or empty setting in .env: $name"
    }
}

Write-Host "Starting KhetSaathi at http://127.0.0.1:8000/" -ForegroundColor Green
Write-Host "Keep this window open while using the website."
Write-Host "Press Ctrl+C to stop the server."
& $phpPath -S 127.0.0.1:8000 -t $publicPath
