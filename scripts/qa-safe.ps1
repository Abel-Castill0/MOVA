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

Write-Host "== Composer validate =="
Invoke-NativeCommand { composer validate }

Write-Host "== Laravel tests (safe sqlite :memory:) =="
Invoke-NativeCommand { .\vendor\bin\phpunit.bat }

Write-Host "== Frontend build =="
Invoke-NativeCommand { npm run build }

Write-Host "== Git diff check =="
Invoke-NativeCommand { git diff --check }

Write-Host "QA local seguro completado."
