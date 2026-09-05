param([ValidateSet('prepare','serve','test')][string]$Action = 'test', [string]$Spec = '')
$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..')
[xml]$config = Get-Content phpunit.xml
foreach ($entry in $config.phpunit.php.env) { [Environment]::SetEnvironmentVariable($entry.name, $entry.value, 'Process') }
$env:APP_ENV = 'local'
$env:DB_DATABASE = Join-Path (Get-Location) 'storage/logs/phase2b-e2e.sqlite'
$env:DATABASE_URL = ''
$env:APP_URL = 'http://127.0.0.1:8012'
$env:BASE_URL = $env:APP_URL
$env:SESSION_DRIVER = 'file'
$env:SESSION_COOKIE = 'mova_phase2b_qa'
$env:SESSION_SECURE_COOKIE = 'false'
$env:BROADCAST_DRIVER = 'log'
$env:GOOGLE_LOGIN_ENABLED = 'false'
$env:RECHARGES_ENABLED = 'true'
if ($Action -eq 'prepare') {
    # DETERMINISMO: la base se recrea SIEMPRE desde cero.
    #
    # Antes solo se creaba si no existia, asi que los datos de una ejecucion
    # anterior sobrevivian. Eso produjo un falso positivo caro en Fase 2B: el
    # usuario 'google-phase2b@mova.test' quedaba persistido, GoogleAuthController
    # encontraba una cuenta existente y —correctamente— saltaba la seleccion de
    # rol. El spec lo leyo como un bug de producto cuando era residuo del
    # harness.
    #
    # Es seguro: este fichero es exclusivo del gate E2E (storage/logs/
    # phase2b-e2e.sqlite) y qa/stabilization-server.php se niega a arrancar
    # contra cualquier otra base.
    if (Test-Path $env:DB_DATABASE) { Remove-Item $env:DB_DATABASE -Force }
    New-Item -ItemType File -Path $env:DB_DATABASE | Out-Null
    php artisan migrate --no-interaction
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    php artisan db:seed --class=LocalTestDataSeeder --no-interaction
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    php qa/stabilization-server.php
    exit $LASTEXITCODE
}
if ($Action -eq 'serve') { php -S 127.0.0.1:8012 -t public qa/stabilization-server.php; exit $LASTEXITCODE }
Set-Location qa
$specArgs = @()
if ($Spec) { $specArgs += $Spec }
node node_modules/@playwright/test/cli.js test --config=playwright.local.config.js @specArgs
exit $LASTEXITCODE
