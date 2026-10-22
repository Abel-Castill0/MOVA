---
name: mova-prompt
description: Interpreta una instrucción informal en español sobre MOVA y la convierte en un prompt claro (PREVIEW, por defecto) o la ejecuta con el workflow de MOVA (EXECUTE). Invocación manual únicamente vía /mova-prompt [preview|execute] <instrucción>.
disable-model-invocation: true
argument-hint: "[preview|execute] <instrucción informal>"
---

# /mova-prompt $ARGUMENTS

**Modo:** si `$ARGUMENTS` empieza por `execute`, `ejecuta` o `exec` → EXECUTE. Si empieza por `preview` o no lleva modo → **PREVIEW** (por defecto). El resto es la instrucción, que se conserva literal.

## Reglas de interpretación (ambos modos)
- Preserva el significado. No inventes requisitos de negocio, políticas de devolución/ausencia, métodos de pago, montos, consentimientos, roles ni funcionalidades que no pidió. No conviertas una petición de analizar en cambiar, ni una de cambiar en borrar/migrar datos.
- Resuelve el módulo con ≤ 3 búsquedas dirigidas (Grep/Glob), no leyendo documentos. Pistas (verifícalas, no las asumas): créditos/cancelación/liquidación → `LessonController::cancel`, `LessonSettlementService`, `RechargeApprovalService`, `credit_transactions`; dashboard profesor/padre → `resources/js/Pages/Dashboard`, `Teacher/`; administración → `Http/Controllers/Admin`, `Pages/Admin`; pagos → skill `mova-mercadopago`, `PaymentOrder`, `MercadoPagoPaymentProvider`; auth/MFA → `Middleware/EnsureAdminMfa`, `AdminMfaService`; video → `JaasService`, `useJitsiMeet.js`.
- Invariantes que SIEMPRE aplican como restricciones (no son requisitos nuevos): transacción + lock + idempotencia + ledger append-only en dinero; autorización server-side; sin secretos; migraciones forward-only; sin commit/push/deploy salvo orden expresa.
- Si algo es ambiguo y toca dinero, auth, menores o datos: elige lo más conservador (diagnosticar sin cambiar reglas de negocio) y lista el supuesto; no lo resuelvas inventando.

## PREVIEW (no modifica archivos; ≤ 200 palabras; sin planes largos)
Responde exactamente con:
```
Entendí: <1 línea fiel a lo que escribió>
Tipo: bug | mejora | funcionalidad | investigación | revisión   ·   Módulo: <rutas verificadas>
Objetivo: …   Restricciones: <las suyas + invariantes aplicables>
Resultado esperado: …
Aceptación: 3–5 criterios verificables (comportamiento + prueba)
Supuestos a confirmar: <solo si los hay, máx. 3>
Prompt mejorado: «…» (≤ 100 palabras, listo para pegar)
→ Para ejecutarlo: /mova-prompt execute <tu texto o el prompt mejorado>
```
No ejecutes herramientas de escritura, pruebas ni agentes en PREVIEW.

## EXECUTE
1. Interpreta internamente con las reglas de arriba; **no imprimas** la especificación intermedia.
2. Clasifica S/M/L con `/mova-feature-workflow` y síguelo: S directo en la sesión principal (sin subagentes); M/L con architect/revisores según esa tabla (`mova-security-finance` obligatorio si toca dinero/auth/menores/webhooks). Usa `mova-context-scout` solo para búsquedas amplias.
3. Si la instrucción exige una decisión de negocio que no puedes inferir con seguridad (p. ej. ¿devolver créditos?, ¿cuánto?), investiga y reporta el estado actual del código y las opciones; **no implementes la regla** hasta que el propietario decida.
4. Verifica con `/mova-safe-qa` (dirigido). Cierre ≤ 10 líneas: qué cambió (archivos), pruebas ejecutadas y resultado, riesgos y qué queda no verificado.
