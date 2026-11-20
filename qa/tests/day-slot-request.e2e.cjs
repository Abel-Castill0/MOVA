// E2E aislado (SQLite sintética, cuentas *@mova.test, nunca producción):
//   padre elige DÍA + FRANJA → profesor ve «hora pendiente» → propone hora exacta → padre acepta → el calendario muestra esa hora.
// Se ejecuta con Playwright instalado en el contenedor de QA:  node day-slot-request.e2e.cjs <baseUrl> <outDir> [timezoneId]
const { chromium } = require('playwright')
const fs = require('fs')

const BASE = process.argv[2] || 'http://127.0.0.1:8099'
const OUT = process.argv[3] || '/tmp/shots'
const TZ = process.argv[4] || 'Europe/Madrid' // el navegador NO está en Lima a propósito
const PASS = 'Secret-QA-2026!'
fs.mkdirSync(OUT, { recursive: true })

const limaKey = (offsetDays) => {
  const t = new Date(Date.now() - 5 * 3600 * 1000 + offsetDays * 86400000)
  return t.toISOString().slice(0, 10)
}
let failures = 0
const check = (ok, msg) => { console.log(`${ok ? 'OK  ' : 'FAIL'} ${msg}`); if (!ok) failures++ }

async function login(page, email) {
  await page.goto(`${BASE}/login`)
  await page.fill('input[type=email]', email)
  await page.fill('input[type=password]', PASS)
  await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login')), page.click('button[type=submit]')])
  await page.waitForLoadState('networkidle')
  const ok = page.locator('button:has-text("Entendido")')
  if (await ok.count()) await ok.first().click().catch(() => {}) // banner de cookies fuera de las capturas
}

;(async () => {
  const browser = await chromium.launch()
  const day = limaKey(1) // mañana en Lima
  const ctxOpts = (vw, vh, mobile) => ({ viewport: { width: vw, height: vh }, timezoneId: TZ, locale: 'es-PE', isMobile: mobile, hasTouch: mobile })

  // ── PADRE (móvil) ────────────────────────────────────────────────────
  const parentCtx = await browser.newContext(ctxOpts(390, 844, true))
  const parent = await parentCtx.newPage()
  const errors = []
  parent.on('pageerror', (e) => errors.push(String(e)))
  parent.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()) })
  await login(parent, 'padre@mova.test')
  await parent.goto(`${BASE}/class-requests/create`)
  await parent.waitForLoadState('networkidle')
  const student = parent.locator('button:has-text("Alumno")').first()
  if (await student.count()) await student.click().catch(() => {})
  await parent.locator('button:has-text("Álgebra")').first().click()
  await parent.locator('button:has-text("Siguiente")').first().click()
  await parent.locator('button:has-text("Omitir código")').click()
  await parent.locator('button:has-text("Clase puntual")').first().click()
  await parent.waitForSelector('[data-testid="cal-month"]')
  await parent.fill('#cr-help-needed', 'Necesita repasar ecuaciones de primer grado.')

  check(await parent.locator('text=¿Cuándo necesitas la clase?').count() > 0, 'título «¿Cuándo necesitas la clase?»')
  check(await parent.locator('text=El profesor propondrá la hora exacta').count() > 0, 'subtítulo visible')
  check(await parent.locator('[data-testid="slot-afternoon"]').isDisabled(), 'las franjas están deshabilitadas hasta elegir el día')
  const next = parent.locator('button:has-text("Ver resumen")')
  check(await next.isDisabled(), '«Ver resumen» deshabilitado sin día ni franja')
  await parent.locator('[data-testid="cal-month"]').scrollIntoViewIfNeeded()
  await parent.screenshot({ path: `${OUT}/01_parent_step4_mobile_empty.png`, fullPage: false })

  await parent.locator(`[data-testid="day-${day}"]`).click()
  check(await parent.locator('[data-testid="slot-afternoon"]').isEnabled(), 'elegir el día habilita las franjas')
  await parent.locator('[data-testid="slot-morning"]').click()
  await parent.locator('[data-testid="slot-afternoon"]').click() // cambio de selección: solo una franja
  check((await parent.locator('[role=radio][aria-checked=true]').count()) === 1, 'una sola franja seleccionada tras cambiar')
  check(await parent.locator('[data-testid="slot-afternoon"]').getAttribute('aria-checked') === 'true', 'la tarde queda marcada')
  const summaryLine = await parent.locator('[data-testid="day-slot-summary"]').innerText()
  check(/por la tarde/.test(summaryLine), `resumen de la selección: ${summaryLine.slice(0, 60)}…`)
  const horiz = await parent.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  check(horiz <= 0, 'sin desborde horizontal en móvil (390 px)')
  await parent.locator('[data-testid="day-slot-summary"]').scrollIntoViewIfNeeded()
  await parent.screenshot({ path: `${OUT}/02_parent_step4_mobile_selected.png`, fullPage: false })

  // Hoy: sin días pasados
  const pastKey = limaKey(-1)
  const pastBtn = parent.locator(`[data-testid="day-${pastKey}"]`)
  check((await pastBtn.count()) === 0 || (await pastBtn.isDisabled()), 'un día pasado no se puede elegir')

  await next.click()
  const summaryRow = await parent.locator('[data-testid="summary-preference"]').innerText()
  check(/tarde/.test(summaryRow), `resumen final muestra «${summaryRow.replace(/\n/g, ' ')}»`)
  await parent.screenshot({ path: `${OUT}/03_parent_summary_mobile.png`, fullPage: false })
  const submitBtn = parent.locator('button:has-text("Enviar solicitud")')
  await submitBtn.dblclick() // doble clic: debe crear una sola solicitud
  await parent.waitForURL(/class-requests$/)
  await parent.waitForLoadState('networkidle')
  const cards = await parent.locator('article').count()
  check(cards === 1, `doble clic → ${cards} solicitud en «Mis solicitudes»`)
  const deadline = await parent.locator('[data-testid^="respond-by-"]').first().innerText()
  check(/Recibe propuestas hasta el .*18:00|Recibe propuestas hasta el .*06:00 p/.test(deadline) && /quedan/.test(deadline) && !/24 horas/.test(deadline), `padre ve la fecha límite real: «${deadline}»`)
  await parent.screenshot({ path: `${OUT}/04_parent_requests_after_send.png`, fullPage: false })

  // ── PROFESOR (escritorio) ────────────────────────────────────────────
  const teacherCtx = await browser.newContext(ctxOpts(1366, 900, false))
  const teacher = await teacherCtx.newPage()
  teacher.on('pageerror', (e) => errors.push(String(e)))
  await login(teacher, 'profe@mova.test')
  await teacher.goto(`${BASE}/teacher/requests`)
  await teacher.waitForLoadState('networkidle')
  await teacher.locator('[data-testid^="request-card-"]').first().click()
  const pref = teacher.locator('[data-testid="request-preference"]')
  await pref.waitFor()
  const prefText = await pref.innerText()
  check(/Fecha solicitada/i.test(prefText) && /Tarde/.test(prefText) && /Pendiente de confirmar/.test(prefText), 'profesor: fecha solicitada, preferencia «Tarde» y hora exacta pendiente')
  const tDeadline = await teacher.locator('[data-testid="request-respond-by"]').innerText()
  check(/quedan/.test(tDeadline), `profesor ve hasta cuándo puede proponer: «${tDeadline.replace(/\s+/g, ' ')}»`)
  check(await teacher.locator('[data-testid="accept-request"]').count() === 0, 'profesor: no hay «Aceptar» directo para una solicitud con día + franja')
  await teacher.screenshot({ path: `${OUT}/05_teacher_request_desktop.png` })

  await teacher.locator('[data-testid="propose-time"]').click()
  await teacher.waitForTimeout(600) // termina la animación del diálogo antes de capturar
  const inputVal = await teacher.inputValue('#counteroffer-time')
  check(inputVal === `${day}T12:00`, `propuesta precargada el día pedido, al inicio de la franja (${inputVal})`)
  await teacher.fill('#counteroffer-time', `${day}T16:00`)
  check(await teacher.locator('[data-testid="outside-slot-warning"]').count() === 0, 'las 16:00 caen dentro de la franja: sin aviso')
  await teacher.screenshot({ path: `${OUT}/06_teacher_proposal_modal_desktop.png` })
  // fuera de franja: aviso (sin enviar)
  await teacher.fill('#counteroffer-time', `${day}T19:00`)
  await teacher.locator('[data-testid="outside-slot-warning"]').waitFor()
  check(true, 'las 19:00 muestran el aviso de alternativa fuera de la franja')
  await teacher.screenshot({ path: `${OUT}/07_teacher_outside_slot_warning.png` })
  await teacher.fill('#counteroffer-time', `${day}T16:00`)
  await teacher.locator('button:has-text("Enviar propuesta")').click()
  await teacher.waitForSelector('[data-testid="tab-counteroffered"]')
  await teacher.waitForFunction(() => document.querySelector('[data-testid="tab-counteroffered"] .text-2xl')?.textContent.trim() === '1')
  check(true, 'profesor: propuesta enviada (contraofertas = 1)')

  // ── PADRE acepta ─────────────────────────────────────────────────────
  await parent.reload()
  await parent.waitForLoadState('networkidle')
  const dl = await parent.locator('dl').first().innerText()
  check(/16:00|04:00/.test(dl), `padre ve la propuesta: «${dl.replace(/\n/g, ' ')}»`)
  const pBy = await parent.locator('[data-testid^="proposal-by-"]').first().innerText()
  check(/Responde antes del/.test(pBy) && /no se agenda nada/.test(pBy), `padre ve hasta cuándo responder a la propuesta: «${pBy.slice(0, 70)}…»`)
  check(await parent.locator('[data-testid="outside-preference"]').count() === 0, 'propuesta dentro de la franja: sin casilla de aceptación expresa')
  await parent.screenshot({ path: `${OUT}/08_parent_sees_proposal.png`, fullPage: false })
  await parent.locator('button:has-text("Aceptar")').first().click()
  await parent.waitForURL(/my-classes/)
  await parent.waitForLoadState('networkidle')
  const calTab = parent.locator('#tab-calendar-parent')
  if (await calTab.count()) await calTab.click()
  await parent.waitForTimeout(500)
  const block = parent.locator('text=/16:00 - 17:00/').first()
  await block.waitFor({ timeout: 10000 })
  check((await block.count()) > 0, 'el calendario del padre muestra el bloque 16:00 - 17:00 (hora de Perú) con el navegador en ' + TZ)
  await block.scrollIntoViewIfNeeded()
  await parent.screenshot({ path: `${OUT}/09_parent_calendar_mobile.png`, fullPage: false })

  check(errors.length === 0, `sin errores de consola/página (${errors.length}) ${errors.slice(0, 2).join(' | ')}`)
  await browser.close()
  console.log(failures === 0 ? 'E2E OK' : `E2E FALLÓ (${failures})`)
  process.exit(failures === 0 ? 0 : 1)
})().catch((e) => { console.error('ERROR', e); process.exit(2) })
