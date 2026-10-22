---
name: mova-safe-qa
description: Selecciona y ejecuta las pruebas apropiadas de MOVA en entornos aislados (PHPUnit SQLite/MySQL QA, checks npm, Playwright en Docker) según los archivos cambiados, y emite un informe compacto de verificación. Úsalo antes de dar por terminado un cambio o cuando el usuario pida "probar/verificar". Nunca apunta a producción, staging ni proveedores live.
argument-hint: "[dirigido | final] [archivos o área]"
---

# /mova-safe-qa $ARGUMENTS

Modo `dirigido` (por defecto) = pruebas del área tocada. Modo `final` = gate único contra el árbol final. No repitas una suite si no cambió el código desde su última ejecución verde.

## 0. Entorno (una sola vez)
- `git status -sb` y `git diff --name-only` → lista de archivos (snapshot a declarar en el informe).
- PHP del host es 8.2 y el proyecto exige ≥ 8.3: **no** ejecutes `artisan`/`phpunit` en el host. Comprueba `php -v`; si < 8.3, usa Docker (`docker compose -f docker-compose.qa.yml ...`, servicios `php_qa`/`mysql_qa`) y, si Docker no está disponible, dilo y delega a CI.
- Prohibido: `.env` real de producción/staging, `migrate:fresh` fuera de `mova_qa`/SQLite, seeders en entornos reales, URLs de producción (salvo `npm run qa:production-readonly`, solo GET).

## 1. Selección por archivo cambiado
| Cambio en | Ejecutar |
|---|---|
| `app/**`, `routes/**`, `config/**` | `phpunit --filter <Clase\|Test>` de los tests que mencionan la clase/ruta (`grep -rl "<Clase>" tests/`) |
| `database/migrations/**` | filtro de migraciones/`*DriftGuardTest` en SQLite **y** perfil MySQL (`phpunit.mysql.xml` tras `mova:qa-mysql-fresh-migrate`) |
| dinero/créditos/pagos/webhooks | `FinancialConcurrencyTest`, `MonetizationIntegrityTest`, `Mercado*`/`PaymentOrderTest`, `LedgerInfrastructureTest` + MySQL |
| autorización/menores | `AuthorizationPolicyTest`, `CrossTenantAccessTest`, `*ExposureTest`, `StudentDataConsentTest` |
| `resources/js/**`, `tailwind`, `vite` | `npm run build` + `npm run check:*` relevantes |
| flujo de usuario crítico | Playwright en Docker: `docker compose -f docker-compose.qa.yml run --rm e2e_qa <spec>` |
| cualquier cambio | `git diff --check`; `vendor/bin/pint --test <archivos>` si PHP ≥ 8.3 |

## 2. Gate final (una vez)
`composer validate` → suite SQLite completa → perfil MySQL completo si hubo migraciones/dinero → `npm run build` y `check:*` → `git diff --check`. Equivalente local: `composer qa:safe` (solo si el PHP del host es ≥ 8.3 o dentro de `php_qa`).

## 3. Informe compacto (máx. 15 líneas, sin pegar logs)
```
Snapshot: <rama> <HEAD corto> · <N archivos modificados>
Ejecutado: <comando> → OK <N tests/M aserciones> | FALLÓ <test> (1 línea de causa)
No ejecutado: <qué y por qué (PHP<8.3 / sin Docker / no aplica)>
Riesgo residual: <1-3 líneas>
```
Un fallo se reporta tal cual, con su causa probable; no se declara «verificado» nada no observado. Para revisión previa de huecos, usa `mova-qa-reviewer`.
