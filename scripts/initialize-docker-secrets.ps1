$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$secretDirectory = Join-Path $projectRoot 'docker\secrets'
$envPath = Join-Path $projectRoot '.env'
if (-not (Test-Path $envPath)) {
    throw 'Create the project-root .env first. This helper will not modify .env.example.'
}

$dockerSettings = [ordered]@{
    DOCKER_APP_URL = 'http://localhost:8080'
    DOCKER_WEB_BIND = '127.0.0.1'
    DOCKER_WEB_PORT = '8080'
    DOCKER_APP_IMAGE = 'multi-ai-agent:local'
    DOCKER_WEB_IMAGE = 'multi-ai-agent-web:local'
    DOCKER_DB_DATABASE = 'multi_ai_agent'
    DOCKER_DB_USERNAME = 'multi_ai_agent'
    DOCKER_APP_KEY_SECRET_FILE = './docker/secrets/app_key'
    DOCKER_DB_PASSWORD_SECRET_FILE = './docker/secrets/db_password'
    DOCKER_DB_ROOT_PASSWORD_SECRET_FILE = './docker/secrets/db_root_password'
}
$existingKeys = @{}
Get-Content -LiteralPath $envPath | ForEach-Object {
    if ($_ -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=') {
        $existingKeys[$matches[1]] = $true
    }
}
$missingSettings = @($dockerSettings.GetEnumerator() | Where-Object { -not $existingKeys.ContainsKey($_.Key) })

$secretFiles = @(
    'app_key',
    'db_password',
    'db_root_password'
)

foreach ($fileName in $secretFiles) {
    if (Test-Path (Join-Path $secretDirectory $fileName)) {
        throw "A Docker secret already exists at $secretDirectory. Rotate secrets deliberately instead of overwriting them."
    }
}

$php = Get-Command php -ErrorAction Stop
New-Item -ItemType Directory -Path $secretDirectory -Force | Out-Null
$account = "$($env:USERDOMAIN)\$($env:USERNAME):(OI)(CI)F"
& icacls.exe $secretDirectory /inheritance:r /grant:r $account | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'Could not restrict permissions on the Docker secrets directory.'
}

$appKey = & $php.Source -r "echo 'base64:'.base64_encode(random_bytes(32));"
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($appKey)) {
    throw 'Could not generate an application encryption key.'
}
$databasePassword = & $php.Source -r "echo bin2hex(random_bytes(32));"
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($databasePassword)) {
    throw 'Could not generate a database password.'
}
$rootPassword = & $php.Source -r "echo bin2hex(random_bytes(32));"
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($rootPassword)) {
    throw 'Could not generate a database administrator password.'
}

$encoding = New-Object System.Text.UTF8Encoding($false)
$values = @{
    'app_key' = $appKey
    'db_password' = $databasePassword
    'db_root_password' = $rootPassword
}
foreach ($fileName in $secretFiles) {
    $secretPath = Join-Path $secretDirectory $fileName
    [System.IO.File]::WriteAllText($secretPath, "$($values[$fileName])`n", $encoding)
    & icacls.exe $secretPath /inheritance:r /grant:r "$($env:USERDOMAIN)\$($env:USERNAME):F" | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Could not restrict permissions on $fileName."
    }
}

if ($missingSettings.Count -gt 0) {
    $lines = @('', '# Docker Compose settings; separate from Laravel Herd settings.') + @(
        $missingSettings | ForEach-Object { '{0}={1}' -f $_.Key, $_.Value }
    )
    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::AppendAllText($envPath, ($lines -join [Environment]::NewLine) + [Environment]::NewLine, $encoding)
}

Write-Output 'Docker settings were added to .env where missing; generated local secrets were not displayed.'
