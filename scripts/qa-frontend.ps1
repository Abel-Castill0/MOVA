$ErrorActionPreference = "Stop"

function Invoke-NativeCommand {
    param(
        [Parameter(Mandatory = $true)]
        [scriptblock] $Command
    )

    & $Command
    if ($LASTEXITCODE -ne 0) {
        throw "El comando QA fallo con codigo de salida $LASTEXITCODE."
    }
}

Write-Host "== Frontend build =="
Invoke-NativeCommand { npm run build }

Write-Host "== Title fallback regression (no Laravel default) =="
Invoke-NativeCommand { npm run check:title }

if ($env:PLAYWRIGHT_BASE_URL) {
    Write-Host "== Playwright local =="
    Push-Location qa
    try {
        Invoke-NativeCommand { npm test }
    } finally {
        Pop-Location
    }
} else {
    Write-Host "Playwright omitido: define PLAYWRIGHT_BASE_URL apuntando a un servidor local para ejecutarlo."
}

Write-Host "QA frontend completado."
