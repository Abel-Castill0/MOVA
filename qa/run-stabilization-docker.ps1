# MOVA E2E DOCKER GATE — único comando que el host necesita para correr el
# gate Playwright reproducible (ver docker/qa-e2e-serve.sh). El host solo
# necesita Docker Desktop; PHP/Composer/Node/Playwright viven dentro del
# container e2e_qa.
#
# Uso:
#   qa/run-stabilization-docker.ps1
#   qa/run-stabilization-docker.ps1 -Spec tests/flujo-completo.spec.js
#   qa/run-stabilization-docker.ps1 -List          # descubre los tests sin correrlos
param([string]$Spec = '', [switch]$List)
$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..')

$playwrightArgs = @()
if ($List) { $playwrightArgs += '--list' }
if ($Spec) { $playwrightArgs += $Spec }

docker compose -f docker-compose.qa.yml run --rm e2e_qa @playwrightArgs
exit $LASTEXITCODE
