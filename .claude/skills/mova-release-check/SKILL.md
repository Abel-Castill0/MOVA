---
name: mova-release-check
description: Lista de verificación previa a un release o push a `master` de MOVA — estado Git, secretos, migraciones, CI, riesgos de dinero/seguridad y rollback. Invocación manual únicamente vía /mova-release-check. Es de solo lectura: reporta GO / NO-GO y no hace commit, push ni deploy.
disable-model-invocation: true
argument-hint: "[rango o descripción del release]"
---

# /mova-release-check $ARGUMENTS

Solo lectura. Entrega **GO / GO con condiciones / NO-GO** con evidencia de cada punto. No ejecutes commit, push, deploy ni cambios en Azure/DNS/proveedores; eso es una acción posterior y explícita (autorizada por `AGENTS.md` solo para cambios validados).

## 1. Snapshot Git
`git status -sb`, `git rev-parse --short HEAD origin/master`, `git rev-list --left-right --count origin/master...HEAD`, `git diff --stat origin/master...HEAD | tail -1`. Declara rama/HEAD/ahead-behind/working tree. Archivos ajenos o sin relación con el release → listarlos y excluirlos.

## 2. Secretos y archivos sensibles (nombres, nunca valores)
- Archivos del diff que coincidan con `.env*` (salvo `*.example`), `qa/auth/`, `*.local.env`, `auth.json`, claves privadas.
- Patrones de credenciales en líneas añadidas (`git diff origin/master...HEAD -U0`): reporta solo `archivo:patrón`. El hook `.claude/hooks/guard-bash.mjs` hace el mismo escaneo al `git commit`.

## 3. Migraciones y datos
- `git diff --name-status origin/master...HEAD -- database/migrations`: ¿alguna migración **aplicada** fue editada (M en vez de A)? → NO-GO (forward-fix).
- Revisa cada migración nueva: reversible, compatible MySQL+SQLite, sin pérdida de datos, tiempo de bloqueo razonable. `php artisan migrate --force` corre en el arranque de Azure: riesgo crítico de producción.
- Sin seeders ni datos QA hacia producción.

## 4. Riesgo funcional
- Si el diff toca dinero/auth/menores/webhooks/JaaS → exige informe reciente de `mova-security-finance` sobre este mismo HEAD (si no existe, invócalo).
- Mercado Pago/pagos/WhatsApp/liquidaciones siguen apagados salvo decisión vigente en el Ledger; no los actives por defecto.

## 5. CI y pruebas
- `gh run list --branch master --limit 3` / estado del último run del HEAD (solo lectura). Verde en: frontend, composer (`composer audit`), sqlite, mysql, e2e.
- Si no hay evidencia verde para este HEAD, ejecuta `/mova-safe-qa final` o marca «no verificado».

## 6. Operación y rollback
- Impacto en Azure/costos (crédito limitado, ver `AGENTS.md`), variables nuevas (solo nombres), orden de despliegue, ¿cómo se revierte? (imagen previa, backup/PITR para datos). Docs a actualizar: Ledger y `docs/architecture/` si cambia comportamiento financiero, estados o despliegue.

## Informe (≤ 20 líneas)
Snapshot · tabla `punto | OK/FALLA/no verificado | evidencia` · bloqueos · condiciones · acciones que requieren al propietario (solo las concretas).
