$ErrorActionPreference = "Stop"

$baseUrl = $env:MOVA_PRODUCTION_URL
if (-not $baseUrl) {
    $baseUrl = "https://mova-production-8750.up.railway.app"
}

$paths = @("/healthz", "/", "/login", "/marketplace", "/quienes-somos", "/terminos", "/privacidad")

foreach ($path in $paths) {
    $url = $baseUrl.TrimEnd("/") + $path
    $response = Invoke-WebRequest -Uri $url -UseBasicParsing -Method GET -TimeoutSec 20
    Write-Host ("{0} {1}" -f $response.StatusCode, $path)
}

Write-Host "QA produccion solo lectura completado. No se enviaron formularios ni se modificaron datos."
