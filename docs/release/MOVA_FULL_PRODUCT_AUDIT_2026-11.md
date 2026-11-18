# MOVA — Auditoría de producto, 2026-11-17

Registro detallado de cada hallazgo: [`MOVA_AUDIT_FINDINGS.md`](MOVA_AUDIT_FINDINGS.md). Capturas: [`audit-2026-11/`](audit-2026-11/) (datos sintéticos).

> **Alcance honesto.** Esta sesión no cubre las 17 fases del encargo: se priorizó lo que las pruebas automáticas no ven (navegador real,
> móvil, accesibilidad, flujos completos) y la eliminación segura de cuentas. Lo no verificado está listado en §G. **No hay despliegue
> a producción de estos cambios** (§F).

## A. Resumen ejecutivo

- **Estado inicial:** `master` = `origin/master` = `2473c0b`, árbol limpio. Producción sana (lecturas públicas: todas las páginas 200,
  `www`/`http` → 301, HSTS, 404 con marca). La rama `feat/admin-user-safe-erasure` **no contiene trabajo propio** (su punta ya estaba en `master`).
- **Problemas más graves encontrados:** ninguno P0/P1 reproducido. Hay defectos P2 de experiencia y accesibilidad que ninguna prueba
  verde detectaba: administración de usuarios inutilizable en móvil, nombres de materia cortados en el paso central del flujo del padre,
  desborde a 320 px, contraste insuficiente generalizado (113 páginas-resolución) y la lista de bloqueo de cuentas sancionadas evadible
  con alias de Gmail. Un riesgo P2 financiero preexistente (cobro y abono sobre cuentas cerradas, O-01/O-02) se corrigió en un segundo commit (F-13/F-14).
- **Resultado:** eliminación segura de cuentas por administrador implementada y probada (19 pruebas + e2e en navegador), 12 hallazgos
  corregidos y medidos antes/después (§D–E), 8 abiertos (4 requieren decisión o tarea financiera propia).
- **Preparación para usuarios reales:** ver §G «Veredicto».

## B. Inventario funcional (lo auditado)

Rutas GET: 77 (63 sin parámetros; 17 públicas 200, 41 protegidas → `/login`, sin ningún 500). Frontend: 69 páginas Vue. Pruebas PHP: 166 archivos.
Jobs: 4; comandos: 36; tareas programadas: 13 (heartbeat, recordatorios, liquidación, conciliación Culqi/MP, cierre de cuentas, expiración de solicitudes, WAHA).

| Módulo | Estado inicial | Problemas | Correcciones | Estado final |
|---|---|---|---|---|
| Público (landing, legales, catálogo, 404) | Funcional | Desborde 320 px; contraste; `h1` ausente en catálogo; objetivos táctiles | F-06, F-08, F-09, F-10 | Sin desbordes ni fallos axe salvo logotipo «plin» |
| Padre: dashboard, hijos, solicitudes, reportes | Funcional | Materias truncadas en móvil; contraste | F-07, F-08 | Flujo padre→profesor recorrido de punta a punta en móvil: estados coherentes |
| Profesor: bandeja, créditos, perfil | Funcional | Contraste, `h1` doble | F-08, F-09 | Bandeja ve la solicitud creada por el padre |
| Admin: usuarios | Tabla ilegible en móvil, sin búsqueda, sin eliminación | F-01–F-03 | Rediseño + eliminación segura + auditoría | Probado en navegador 375/1440 |
| Admin: resto de paneles | Sin desbordes ni errores | Contraste, regiones con scroll sin foco | F-08, F-10 | OK |
| Cierre de cuentas / lista de bloqueo | Correcto salvo evasión por alias | F-04, F-05, F-12 | Variantes de correo, conteos, textos | 19 + 153 pruebas verdes |
| Pagos Culqi, créditos, ledger | Funcional; cuenta cerrada no contemplada | Cobro/abono sobre cuenta cerrada (F-13, F-14) | Guarda en el proveedor + incidencia crítica en `RechargeApprovalService` | Probado SQLite y MySQL; sin tocar idempotencia ni orden de locks |
| Tiempo real, WhatsApp, correo, JaaS, Azure | **No auditados en vivo** | — | — | Ver §G |

## C. Defectos corregidos

Cada uno con causa raíz, archivos, prueba y evidencia en [`MOVA_AUDIT_FINDINGS.md`](MOVA_AUDIT_FINDINGS.md) (F-01 a F-12).
Archivos principales: `app/Services/AccountLifecycleService.php`, `app/Http/Controllers/AdminController.php`, `app/Models/ClosureBlocklist.php`,
`routes/web.php`, `resources/js/Pages/Admin/Users.vue` + `Admin/Partials/*`, `LandingFooter.vue`, `LandingNavbar.vue`, `ClassRequests/Create.vue`,
`Welcome.vue`, `resources/css/app.css` (tokens `ink-*`), `tailwind.config.js` (`slate-500`, `gray-500`), ~45 componentes Vue (contraste, `h1`, zona Lima),
`tests/Feature/AdminUserErasureTest.php`, `docs/ACCOUNT_LIFECYCLE.md`.

Revisión de seguridad (`mova-security-finance`, obligatoria por tocar auth/menores/dinero): «apto con condiciones»; se incorporaron P2-1, P2-3, P2-4a, P3-1, P3-2, P3-3.
El P2-1 se resolvió con un saneado acotado (correos y números largos) en lugar del redactor de IA, que convertía «Solicitud» en `[NOMBRE]`.

## D. Experiencia visual

Viewports del barrido automático: 320, 375, 768, 1440 px × 4 perfiles (visitante, padre, profesor, admin) × 12 páginas ≈ 184 comprobaciones por pasada;
revisión manual de capturas a 375/390/1440. **No se probó** 430, 1024, 1920, otros navegadores ni dispositivo físico.

| Antes | Después |
|---|---|
| `before_admin_users_375.png` (columnas cortadas, acciones fuera de alcance) | `after_admin_users_375.png`, `after_erase_modal_375.png`, `after_erase_modal_1440.png` |
| `before_class_request_subjects_375.png` («Matemá…», «Program…») | `after_class_request_subjects_390.png` |

Pendiente visual: calendario semanal (O-06) y revisión en teléfono real.

## E. Calidad técnica

| Medición | Antes | Después |
|---|---|---|
| Nodos con violación axe (WCAG 2.2 AA, 184 comprobaciones) | 656 | 225 en el barrido completo; tras los ajustes finos posteriores solo queda el logotipo «plin» en las páginas re-medidas |
| Páginas-resolución con ≥1 violación | 125 | 35 en el barrido completo (las 6 con contraste y las 8 de tamaño de objetivo restantes se corrigieron después y se re-midieron en 12 páginas representativas) |
| Desbordes horizontales | 7 | 2 (ambos `/notifications`, endpoint JSON: falso positivo) |
| Páginas con `h1` ≠ 1 | 4 | 0 |
| Errores de consola / peticiones fallidas | 0 | 0 |

Pruebas: `AdminUserErasureTest` 19/19; regresión dirigida 153 (admin, ciclo de vida, MFA, operaciones, exposición, carrera de cierre) 0 fallos, 3 omitidas;
`npm run build` OK; `check:title|chatbot-escape|movi-availability|checkout-poll|local-storage` OK. Suite PHPUnit completa (SQLite): ver resultado final en la sección «Gate final» más abajo.
**No ejecutado:** PHPUnit MySQL, Playwright del repositorio (`qa/`), pruebas de carga, concurrencia multi-proceso.

### Gate final

PHPUnit completo (SQLite en memoria, PHP 8.3.36, contenedor `php_qa`) sobre el árbol final: **1956 pruebas, 9456 aserciones, 0 fallos, 25 omitidas** (tras el segundo commit, F-13/F-14; el primero fue 1947) (las omisiones son pruebas exclusivas de MySQL/QA; igual que la línea base documentada de 25). `npm run build` y los 5 `check:*` OK. Perfil MySQL (`phpunit.mysql.xml`, `mysql_qa`) sobre las suites de dinero y cuentas (`ClosedAccountPaymentGuardTest`, `AdminUserErasureTest`, `AccountLifecycleTest`, `AdminActionsTest`, `CulqiPaymentTest`, `AdminRechargeReversalTest`): **179 OK**. En `QaAccountClosureRaceGuardTest` 3 pruebas fallan en este contenedor por entorno (el subproceso usa `DB_HOST=127.0.0.1` y no `mysql_qa`), no por el código. Playwright del repositorio y CI de GitHub: no ejecutados; la carrera multi-proceso real cierre-vs-abono tampoco (solo su simulación determinista).

## F. Infraestructura

- **Producción (solo lectura HTTP):** `/healthz` 200, `/readyz` ready (base, migraciones, caché), TTFB ≈ 0,9 s en páginas renderizadas, 0,5 s en 404; cabeceras HSTS, `nosniff`, `X-Frame-Options`, `Referrer-Policy`; CSP con `script-src` en Report-Only (O-05).
- **Última imagen desplegada conocida (documentada en `DEPLOY_HISTORY.md`):** `ghcr.io/abel-castill0/mova@sha256:5eb62f85…8088` (`27a5ce4`). Estado real de revisiones, MySQL, Key Vault, SignalR, WAHA, scheduler y colas: **no verificado** (no hay `az` en el equipo ni sesión activa comprobada).
- **Estado de despliegue de este trabajo:** sin migraciones nuevas (solo código y assets), por lo que el rollback es la imagen anterior sin tocar datos. Para publicar hace falta: build desde `git archive` del commit, push a GHCR (credencial) y `az containerapp update` ×3 (sesión Azure con MFA): pasos físicos del propietario, ver mensaje de cierre.

## G. Riesgos, pendientes y veredicto

- **Defectos reales abiertos:** O-03–O-06 (P3). O-01/O-02 corregidos (F-13/F-14).
- **Decisiones del propietario:** aviso previo a la persona eliminada (O-08), hashes latentes de bloqueo (O-04).
- **No verificado:** ver el final de `MOVA_AUDIT_FINDINGS.md`.
- **Veredicto (honesto):** no se encontró ningún P0/P1 reproducible en los flujos recorridos (visitante, padre→profesor, panel admin en navegador real, producción en solo lectura). La plataforma es utilizable por usuarios reales **en lo auditado**; esa afirmación no se extiende a pagos en vivo, tiempo real, WhatsApp, correo, videoclases ni infraestructura Azure, que esta sesión no pudo comprobar. No se declara cumplimiento del 100 % de las condiciones de la fase 16 (faltan: CI en verde sobre el commit, despliegue, MySQL, E2E del repositorio, rollback verificado en caliente).

## H. Evidencia de producción

Solo lecturas HTTP sin sesión contra `https://movaeduca.me` el 2026-11-17 (versión desplegada documentada `27a5ce4`; no se identificó la versión por el servidor). **Ningún recorrido autenticado, compra, reembolso ni escritura se hizo en producción.** Todas las pruebas con cuentas se hicieron en el entorno aislado local con cuentas `*@mova.test`.
