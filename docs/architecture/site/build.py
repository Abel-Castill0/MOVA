#!/usr/bin/env python3
"""Genera docs/architecture/site/index.html a partir de los diagramas Mermaid.

Fuente única de verdad: los bloques ```mermaid de docs/architecture/*.md y los
archivos site/diagrams/*.mmd. No hay copia manual de diagramas: reconstruir con

    python docs/architecture/site/build.py

Solo biblioteca estándar. No toca nada fuera de docs/architecture/site/.
"""
import html
import json
import re
from pathlib import Path

HERE = Path(__file__).resolve().parent
ARCH = HERE.parent
MERMAID_CDN = "https://cdn.jsdelivr.net/npm/mermaid@11.4.1/dist/mermaid.esm.min.mjs"
TAILWIND_CDN = "https://cdn.tailwindcss.com/3.4.16"

CATEGORIES = [
    ("deploy", "Despliegue y red"),
    ("er", "Modelo de datos (ER)"),
    ("states", "Máquinas de estado"),
    ("seq", "Secuencias y flujos"),
    ("api", "Mapa de APIs"),
]

# src: ("md", archivo, índice de bloque) | ("mmd", archivo)
# iso: claves → texto corto. Mapeo orientativo (ver normas.md); no es certificación.
DIAGRAMS = [
    dict(id="deploy-topology", cat="deploy", title="Topología de despliegue en Azure",
         src=("md", "01-deployment.md", 0),
         note="Web, worker y scheduler sobre Azure Container Apps (mexicocentral), MySQL privado, ACR por Managed Identity y Log Analytics. Entorno ACA compartido con staging por restricción de la suscripción.",
         iso={"25010": "Fiabilidad · Eficiencia", "27001": "A.8.20 · A.8.22 · A.8.31", "22301": "RTO ≈ 50 min · PITR 14 d"}),
    dict(id="deploy-network", cat="deploy", title="Conexión de red y servicios externos",
         src=("mmd", "network.mmd"),
         note="Flujo entre Container Apps, MySQL en subred delegada sin IP pública, ACR y servicios externos (Mercado Pago, JaaS, SMTP, Cloudinary, Sentry). OpenAI/Gemini y WhatsApp aparecen apagados.",
         iso={"25010": "Seguridad · Fiabilidad", "27001": "A.8.20 · A.8.21 · A.8.22 · A.5.23", "27701": "Flujo transfronterizo (Azure MX, Cloudinary, Sentry)", "42001": "IA externa apagada"}),
    dict(id="deploy-pipeline", cat="deploy", title="Pipeline CI/CD y despliegue manual",
         src=("md", "01-deployment.md", 1),
         note="CI automático en GitHub Actions; despliegue manual por digest con Job de migración revisada y rollback por revisión.",
         iso={"25010": "Mantenibilidad · Fiabilidad", "27001": "A.8.32 · A.8.9", "29119": "Pruebas de sistema en CI; gap: sin deploy automatizado"}),
    dict(id="er-identity", cat="er", title="ER · Identidad y catálogo",
         src=("md", "02-data-model.md", 0),
         note="users, students (sin cuenta propia), teacher_profiles, subjects y agenda. Roles Spatie; MFA TOTP para admin.",
         iso={"25010": "Seguridad · Compatibilidad", "27001": "A.5.15 · A.5.16 · A.8.5", "27701": "Datos de menores: consentimiento parental"}),
    dict(id="er-classes", cat="er", title="ER · Solicitudes y clases",
         src=("md", "02-data-model.md", 1),
         note="class_requests, classes (modelo Lesson), eventos, reportes, presencia JaaS y reseñas.",
         iso={"25010": "Adecuación funcional · Fiabilidad", "27001": "A.8.15 (class_events)", "29119": "Nivel: sistema + integración"}),
    dict(id="er-finance", cat="er", title="ER · Créditos y pagos (ledger)",
         src=("md", "02-data-model.md", 2),
         note="Ledger append-only con idempotency_key único; payment_orders y payment_webhooks de Mercado Pago.",
         iso={"25010": "Fiabilidad · Seguridad funcional", "27001": "A.8.3 · A.8.15 · A.8.16", "29119": "Concurrencia obligatoria (probes reales)", "22301": "Reconciliación diaria del ledger"}),
    dict(id="er-ai", cat="er", title="ER · Diagnóstico, IA, consentimientos y soporte",
         src=("md", "02-data-model.md", 3),
         note="student_diagnostics, recomendaciones, ai_usage_logs, student_data_consents, legal_acceptances y libro de reclamaciones.",
         iso={"25010": "Seguridad · Capacidad de interacción", "27701": "Consentimiento por menor · minimización", "42001": "Registro de uso de IA (ai_usage_logs)", "27001": "A.5.34"}),
    dict(id="state-request", cat="states", title="Estados de ClassRequest",
         src=("md", "03-state-machines.md", 0),
         note="De pending_parent_approval/open a accepted, counteroffered, teacher_rejected, expired y cancelled.",
         iso={"25010": "Adecuación funcional", "29119": "Cobertura de transiciones: tests de flujo de solicitud"}),
    dict(id="state-lesson", cat="states", title="Estados de Lesson (classes.status)",
         src=("md", "03-state-machines.md", 1),
         note="scheduled → paid (declarado por el padre) → pending_parent_confirmation → completed; escalado a needs_admin_review a los 7 días.",
         iso={"25010": "Adecuación funcional · Fiabilidad", "29119": "Gap: «paid» no es pago verificado (decisión de producto F-11)"}),
    dict(id="state-credits", cat="states", title="Ciclo de créditos del profesor",
         src=("md", "03-state-machines.md", 2),
         note="deposit → disponible → reservation → consumption/refund; reversal puede dejar saldo negativo, que bloquea nuevas reservas.",
         iso={"25010": "Fiabilidad · Seguridad funcional", "29119": "MonetizationIntegrityTest (saldo negativo, concurrencia)"}),
    dict(id="state-payment", cat="states", title="Estados de PaymentOrder y recarga",
         src=("md", "03-state-machines.md", 3),
         note="created → pending → paid/failed/expired/cancelled. Incierto nunca se vuelve éxito ni rechazo sin evidencia.",
         iso={"25010": "Fiabilidad · Seguridad", "27001": "A.8.15 · A.8.16", "29119": "Gap: sandbox/live fuera de CI"}),
    dict(id="seq-booking", cat="seq", title="Secuencia · Reserva de clase",
         src=("md", "04-flows.md", 0),
         note="Aceptación con locks (alumno, usuario y perfil del profesor), conflicto de horario y reserva de créditos en una transacción.",
         iso={"25010": "Fiabilidad · Eficiencia", "29119": "Concurrencia: accept-lesson (8 procesos)", "27001": "A.8.28"}),
    dict(id="seq-settlement", cat="seq", title="Flujo · Liquidación de créditos",
         src=("md", "04-flows.md", 1),
         note="mova:settle-lessons cada hora: consume con reporte, escala sin reporte; modo live solo mueve el ledger interno.",
         iso={"25010": "Fiabilidad · Seguridad funcional", "29119": "Dry-run con safe_to_enable", "22301": "Recuperación tras parada del scheduler"}),
    dict(id="seq-payment", cat="seq", title="Secuencia · Recarga con Mercado Pago (incluye error 2072)",
         src=("md", "04-flows.md", 2),
         note="Creación del intento, ramas de rechazo terminal (HTTP 400 / 2072) e incertidumbre, y confirmación por webhook. Caso 2072 abierto con el proveedor.",
         iso={"25010": "Fiabilidad · Seguridad", "27001": "A.8.24 · A.8.5", "29119": "Gap: sin contract test contra sandbox en CI", "22301": "Rollback: cierre administrativo auditado"}),
    dict(id="seq-webhook", cat="seq", title="Secuencia · Webhook y reconciliación",
         src=("mmd", "sequence-webhook.mmd"),
         note="Validaciones del controlador, persistencia idempotente, procesamiento en el worker y barrido cada 5 min con umbrales de config/payments.php.",
         iso={"25010": "Fiabilidad · Seguridad", "27001": "A.8.24 · A.8.16", "29119": "Pruebas de webhook firmado + recovery", "22301": "Recuperación de eventos perdidos"}),
    dict(id="seq-diagnostic", cat="seq", title="Flujo · Diagnóstico y recomendación (IA opcional)",
         src=("md", "04-flows.md", 3),
         note="Recomendación por reglas; enriquecimiento con IA externa solo si está habilitado (hoy apagado).",
         iso={"25010": "Seguridad · Capacidad de interacción", "42001": "Gobierno de IA: apagada, política declara no enviar datos", "27701": "Datos de menores antes de activar IA"}),
    dict(id="api-map", cat="api", title="Mapa de APIs por dominio",
         src=("mmd", "api-map.mmd"),
         note="Rutas web y webhooks agrupadas por público, autenticación, padre, profesor, admin y entrantes. Fuente: routes/web.php, routes/auth.php, routes/api.php.",
         iso={"25010": "Seguridad · Mantenibilidad", "27001": "A.5.15 · A.8.5 · A.8.3", "29119": "Gap: sin prueba de contrato de rutas automática"}),
]

ISO_LABELS = {
    "25010": "ISO/IEC 25010",
    "29119": "ISO/IEC/IEEE 29119",
    "27001": "ISO/IEC 27001",
    "27701": "ISO/IEC 27701",
    "42001": "ISO/IEC 42001",
    "22301": "ISO 22301",
}


def md_blocks(path):
    text = (ARCH / path).read_text(encoding="utf-8")
    return [m.group(1).strip("\n") for m in re.finditer(r"```mermaid\n(.*?)```", text, re.S)]


def load(d):
    kind = d["src"][0]
    if kind == "md":
        return md_blocks(d["src"][1])[d["src"][2]]
    return (HERE / "diagrams" / d["src"][1]).read_text(encoding="utf-8").strip("\n")


def inline(t):
    t = esc(t)
    t = re.sub(r"\*\*(.+?)\*\*", lambda m: "<b>" + m.group(1) + "</b>", t)
    t = re.sub(r"\*(.+?)\*", lambda m: "<i>" + m.group(1) + "</i>", t)
    t = re.sub(r"`(.+?)`", lambda m: "<code>" + m.group(1) + "</code>", t)
    return t


def md_to_html(text):
    """Conversor mínimo (encabezados, tablas, citas, párrafos) para normas.md."""
    out, lines, i = [], text.splitlines(), 0
    while i < len(lines):
        ln = lines[i]
        if ln.startswith("# "):
            i += 1
        elif ln.startswith("## "):
            out.append(f'<h3 class="mt-6 mb-2 text-lg font-semibold">{inline(ln[3:])}</h3>')
            i += 1
        elif ln.startswith("> "):
            out.append(f'<p class="my-2 rounded border-l-4 border-indigo-400 bg-indigo-50 p-2 text-sm dark:bg-indigo-950">{inline(ln[2:])}</p>')
            i += 1
        elif ln.startswith("|"):
            rows = []
            while i < len(lines) and lines[i].startswith("|"):
                rows.append([c.strip() for c in lines[i].strip().strip("|").split("|")])
                i += 1
            head, body = rows[0], [r for r in rows[2:]]
            th = "".join(f'<th class="border border-slate-300 bg-slate-100 px-2 py-1 text-left dark:border-slate-600 dark:bg-slate-800">{inline(c)}</th>' for c in head)
            tb = "".join("<tr>" + "".join(f'<td class="border border-slate-300 px-2 py-1 align-top dark:border-slate-600">{inline(c)}</td>' for c in r) + "</tr>" for r in body)
            out.append(f'<div class="overflow-x-auto"><table class="my-2 min-w-full border-collapse text-xs"><thead><tr>{th}</tr></thead><tbody>{tb}</tbody></table></div>')
        elif ln.strip():
            out.append(f'<p class="my-2 text-sm">{inline(ln)}</p>')
            i += 1
        else:
            i += 1
    return "\n".join(out)


def esc(s):
    return html.escape(s, quote=True)


def build():
    for d in DIAGRAMS:
        d["code"] = load(d)

    nav = []
    sections = []
    for key, label in CATEGORIES:
        items = [d for d in DIAGRAMS if d["cat"] == key]
        nav.append(f'<p class="mt-5 mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{esc(label)}</p>')
        for d in items:
            nav.append(f'<a class="navlink block rounded px-2 py-1 text-sm hover:bg-slate-200 dark:hover:bg-slate-800" href="#{d["id"]}">{esc(d["title"])}</a>')
        sections.append(f'<h2 class="mt-12 mb-2 text-2xl font-bold" id="cat-{key}">{esc(label)}</h2>')
        for d in items:
            chips = "".join(
                f'<span class="rounded-full border border-indigo-300 bg-indigo-50 px-2 py-0.5 text-xs text-indigo-800 dark:border-indigo-700 dark:bg-indigo-950 dark:text-indigo-200"><b>{esc(ISO_LABELS[k])}</b> · {esc(v)}</span>'
                for k, v in d["iso"].items()
            )
            sections.append(f"""
<section id="{d['id']}" class="diagram-card mt-6 rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900" data-diagram="{d['id']}">
  <div class="flex flex-wrap items-start justify-between gap-2">
    <h3 class="text-lg font-semibold">{esc(d['title'])}</h3>
    <button type="button" class="copy-btn rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-800" data-target="{d['id']}">Copiar código Mermaid</button>
  </div>
  <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{esc(d['note'])}</p>
  <div class="mt-2 flex flex-wrap gap-1">{chips}</div>
  <div class="diagram-out mt-3 overflow-x-auto" id="out-{d['id']}" aria-label="Diagrama: {esc(d['title'])}"><p class="text-sm text-slate-500">Cargando diagrama…</p></div>
  <script type="text/plain" id="src-{d['id']}">{d['code'].replace('</script', '<' + chr(92) + '/script')}</script>
</section>""")

    normas = md_to_html((HERE / "normas.md").read_text(encoding="utf-8"))
    nav.append('<p class="mt-5 mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Normas</p>')
    nav.append('<a class="navlink block rounded px-2 py-1 text-sm hover:bg-slate-200 dark:hover:bg-slate-800" href="#normas">Mapeo a normas ISO</a>')
    sections.append('<h2 class="mt-12 mb-2 text-2xl font-bold" id="cat-normas">Normas ISO</h2>')
    sections.append(f'<section id="normas" class="diagram-card mt-6 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">{normas}</section>')
    data = json.dumps([d["id"] for d in DIAGRAMS])
    page = f"""<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Arquitectura MOVA</title>
<meta name="description" content="Diagramas de arquitectura, datos, estados, flujos y APIs de MOVA con mapeo a normas ISO.">
<script src="{TAILWIND_CDN}"></script>
<script>tailwind.config = {{ darkMode: 'class' }};</script>
<script>
  // Aplica el tema antes de pintar (evita parpadeo). Persistencia en localStorage con try/catch.
  (function () {{
    var t = null;
    try {{ t = localStorage.getItem('mova-arch-theme'); }} catch (e) {{}}
    if (!t) t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    document.documentElement.classList.toggle('dark', t === 'dark');
  }})();
</script>
<style>
  html {{ scroll-behavior: smooth; scroll-padding-top: 4.5rem; }}
  .navlink.active {{ background: rgb(99 102 241 / .15); font-weight: 600; }}
  .diagram-out svg {{ max-width: none; height: auto; }}
  @media (prefers-reduced-motion: reduce) {{ html {{ scroll-behavior: auto; }} }}
</style>
</head>
<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
<header class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white/90 px-4 py-3 backdrop-blur dark:border-slate-700 dark:bg-slate-900/90">
  <div class="flex items-center gap-3">
    <button id="menu-btn" class="rounded border border-slate-300 px-2 py-1 text-sm lg:hidden dark:border-slate-600" aria-controls="sidebar" aria-expanded="false">Índice</button>
    <h1 class="text-base font-bold sm:text-lg">MOVA · Arquitectura</h1>
  </div>
  <div class="flex items-center gap-2">
    <a class="text-sm underline" href="#normas">Mapeo ISO</a>
    <button id="theme-btn" class="rounded border border-slate-300 px-3 py-1 text-sm hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-800" aria-label="Cambiar tema claro u oscuro">Tema</button>
  </div>
</header>
<div class="mx-auto flex max-w-[1500px]">
  <aside id="sidebar" class="fixed inset-y-0 left-0 z-10 hidden w-72 overflow-y-auto border-r border-slate-200 bg-white px-4 pb-8 pt-16 dark:border-slate-700 dark:bg-slate-900 lg:sticky lg:top-0 lg:block lg:h-screen lg:pt-6">
    <nav aria-label="Índice de diagramas">{''.join(nav)}</nav>
  </aside>
  <main class="min-w-0 flex-1 px-4 pb-24 pt-6 sm:px-8">
    <p class="text-sm text-slate-600 dark:text-slate-300">Estado: CURRENT · derivado del código de <code>master</code> y del Ledger (2026-10-19). Las etiquetas ISO son un <b>mapeo orientativo</b>, no una certificación (ver <a class="underline" href="#normas">Normas ISO</a>). Reconstruir con <code>python docs/architecture/site/build.py</code>.</p>
    {''.join(sections)}
  </main>
</div>
<script type="module">
import mermaid from '{MERMAID_CDN}';
const ids = {data};
const root = document.documentElement;
const isDark = () => root.classList.contains('dark');
async function renderAll() {{
  mermaid.initialize({{ startOnLoad: false, securityLevel: 'strict', theme: isDark() ? 'dark' : 'default', flowchart: {{ htmlLabels: true, useMaxWidth: false }}, er: {{ useMaxWidth: false }}, sequence: {{ useMaxWidth: false }}, state: {{ useMaxWidth: false }} }});
  for (const id of ids) {{
    const out = document.getElementById('out-' + id);
    const code = document.getElementById('src-' + id).textContent;
    try {{
      const {{ svg }} = await mermaid.render('svg-' + id + '-' + Date.now(), code);
      out.innerHTML = svg;
    }} catch (e) {{
      out.innerHTML = '<pre class="text-sm text-red-600">No se pudo renderizar este diagrama: ' + String(e.message || e).replace(/</g, '&lt;') + '</pre>';
      console.error('Mermaid error en', id, e);
    }}
  }}
}}
function setTheme(t) {{
  root.classList.toggle('dark', t === 'dark');
  try {{ localStorage.setItem('mova-arch-theme', t); }} catch (e) {{}}
  renderAll();
}}
document.getElementById('theme-btn').addEventListener('click', () => setTheme(isDark() ? 'light' : 'dark'));
document.querySelectorAll('.copy-btn').forEach(btn => btn.addEventListener('click', async () => {{
  const code = document.getElementById('src-' + btn.dataset.target).textContent;
  const prev = btn.textContent;
  try {{ await navigator.clipboard.writeText(code); btn.textContent = 'Copiado'; }}
  catch (e) {{ const ta = document.createElement('textarea'); ta.value = code; document.body.appendChild(ta); ta.select(); try {{ document.execCommand('copy'); btn.textContent = 'Copiado'; }} catch (e2) {{ btn.textContent = 'No se pudo copiar'; }} ta.remove(); }}
  setTimeout(() => btn.textContent = prev, 1500);
}}));
const sb = document.getElementById('sidebar'), mb = document.getElementById('menu-btn');
mb.addEventListener('click', () => {{ const open = sb.classList.toggle('hidden') === false; mb.setAttribute('aria-expanded', String(open)); }});
sb.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {{ if (window.innerWidth < 1024) {{ sb.classList.add('hidden'); mb.setAttribute('aria-expanded', 'false'); }} }}));
const links = new Map([...document.querySelectorAll('.navlink')].map(a => [a.getAttribute('href').slice(1), a]));
const io = new IntersectionObserver(es => es.forEach(e => {{ if (e.isIntersecting) {{ links.forEach(l => l.classList.remove('active')); const l = links.get(e.target.id); if (l) l.classList.add('active'); }} }}), {{ rootMargin: '-20% 0px -70% 0px' }});
document.querySelectorAll('.diagram-card').forEach(s => io.observe(s));
await renderAll();
window.__mermaidRendered = true;
</script>
</body>
</html>
"""
    (HERE / "index.html").write_text(page, encoding="utf-8")
    print(f"index.html generado: {len(DIAGRAMS)} diagramas")


if __name__ == "__main__":
    build()
