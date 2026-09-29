$ErrorActionPreference = "Stop"

# Sin URL por defecto a propósito: la producción está migrando de Railway a
# Azure y un default silencioso podría auditar el entorno equivocado.
$baseUrl = $env:MOVA_PRODUCTION_URL
if (-not $baseUrl) {
    Write-Error "Define MOVA_PRODUCTION_URL (URL base del entorno a consultar en solo lectura)."
    exit 1
}

$paths = @("/healthz", "/readyz", "/", "/login", "/marketplace", "/quienes-somos", "/terminos", "/privacidad", "/libro-de-reclamaciones")

foreach ($path in $paths) {
    $url = $baseUrl.TrimEnd("/") + $path
    $response = Invoke-WebRequest -Uri $url -UseBasicParsing -Method GET -TimeoutSec 20
    Write-Host ("{0} {1}" -f $response.StatusCode, $path)
}

Write-Host "QA produccion solo lectura completado. No se enviaron formularios ni se modificaron datos."
