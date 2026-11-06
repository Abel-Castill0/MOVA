import { test, expect } from '@playwright/test'
import { passAdminMfaIfPrompted } from '../lib/totp.mjs'

// Nunca ejecutar un test E2E que podría mutar cuentas en un entorno real.
test.beforeAll(() => {
  if (!process.env.DB_DATABASE?.endsWith('phase2b-e2e.sqlite') || process.env.BASE_URL !== 'http://127.0.0.1:8012') {
    throw new Error('Este spec solo acepta el fixture SQLite sintético de qa-e2e-serve.sh')
  }
})

test('menú móvil: botón de cierre, Escape, foco y scroll restaurados', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/')
  const cookie = page.getByRole('button', { name: 'Entendido', exact: true })
  if (await cookie.isVisible()) await cookie.click()

  const opener = page.getByRole('button', { name: 'Menú', exact: true })
  await opener.click()
  const dialog = page.getByRole('dialog', { name: 'Menú de navegación' })
  await expect(dialog).toBeVisible()
  await dialog.getByRole('button', { name: 'Cerrar menú' }).click()
  await expect(dialog).toHaveCount(0)
  await expect(opener).toBeFocused()
  expect(await page.evaluate(() => document.body.style.overflow)).not.toBe('hidden')

  await opener.click()
  await expect(dialog.getByRole('button', { name: 'Cerrar menú' })).toBeFocused()
  await page.keyboard.press('Escape')
  await expect(dialog).toHaveCount(0)
  await expect(opener).toBeFocused()
  expect(await page.evaluate(() => document.body.style.overflow)).not.toBe('hidden')
})

test('registro: Enter intermedio avanza sin lanzar POST de registro', async ({ page }) => {
  const registerPosts = []
  page.on('request', (r) => {
    if (r.method() === 'POST' && new URL(r.url()).pathname === '/register') registerPosts.push(r.url())
  })
  await page.goto('/register')
  await page.getByRole('button', { name: 'Continuar' }).click()
  await expect(page.getByText('PASO 2 DE 5')).toBeVisible()
  await page.locator('#name').fill('Madre QA sin creación')
  await page.locator('#name').press('Enter')
  await expect(page.getByText('PASO 3 DE 5')).toBeVisible()
  expect(registerPosts).toHaveLength(0)
})

test('admin: abrir/cancelar suspensión y reactivación no emite POST', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Correo electrónico').fill('admin-phase2b@mova.test')
  await page.getByLabel('Contraseña', { exact: true }).fill('password123')
  await page.getByRole('button', { name: 'Iniciar sesión', exact: true }).click()
  await passAdminMfaIfPrompted(page)
  await page.goto('/admin/users')

  const accountMutations = []
  page.on('request', (r) => {
    if (r.method() === 'POST' && /\/admin\/users\/\d+\/(suspend|unsuspend)/.test(new URL(r.url()).pathname)) {
      accountMutations.push(r.url())
    }
  })

  await page.getByRole('button', { name: 'Suspender', exact: true }).first().click()
  const dialog = page.getByRole('dialog', { name: 'Suspender cuenta' })
  await expect(dialog).toBeVisible()
  expect(accountMutations).toHaveLength(0)
  await dialog.getByRole('button', { name: 'Cancelar' }).click()
  await expect(dialog).toBeHidden()
  expect(accountMutations).toHaveLength(0)

  await page.getByRole('button', { name: 'Suspender', exact: true }).first().click()
  await page.keyboard.press('Escape')
  await expect(dialog).toBeHidden()
  expect(accountMutations).toHaveLength(0)
})

test('campana: región accesible y cierre con Escape sin marcar avisos', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Correo electrónico').fill('admin-phase2b@mova.test')
  await page.getByLabel('Contraseña', { exact: true }).fill('password123')
  await page.getByRole('button', { name: 'Iniciar sesión', exact: true }).click()
  await passAdminMfaIfPrompted(page)
  await page.goto('/dashboard')

  const bell = page.getByRole('button', { name: 'Notificaciones', exact: true }).first()
  await bell.click()
  await expect(bell).toHaveAttribute('aria-expanded', 'true')
  const panel = page.getByRole('region', { name: 'Notificaciones recientes' })
  await expect(panel).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(panel).toHaveCount(0)
  await expect(bell).toBeFocused()
  await expect(bell).toHaveAttribute('aria-expanded', 'false')
})

test('admin con MFA y profesor mantienen sesiones separadas en contextos de navegador', async ({ browser }) => {
  // Dos pestañas del mismo perfil comparten la cookie de sesión de Laravel.
  // Dos BrowserContext equivalen a perfiles aislados: iniciar sesión como
  // profesor nunca debe sustituir el MFA ni el rol del administrador.
  const adminContext = await browser.newContext({ baseURL: process.env.BASE_URL })
  const teacherContext = await browser.newContext({ baseURL: process.env.BASE_URL })
  try {
    const adminPage = await adminContext.newPage()
    await adminPage.goto('/login')
    await adminPage.getByLabel('Correo electrónico').fill('admin-phase2b@mova.test')
    await adminPage.getByLabel('Contraseña', { exact: true }).fill('password123')
    await adminPage.getByRole('button', { name: 'Iniciar sesión', exact: true }).click()
    await passAdminMfaIfPrompted(adminPage)
    await adminPage.goto('/admin/requests')
    await expect(adminPage.getByRole('heading', { name: 'Solicitudes — Admin' })).toBeVisible()

    const teacherPage = await teacherContext.newPage()
    await teacherPage.goto('/login')
    await teacherPage.getByLabel('Correo electrónico').fill('profesor@mova.test')
    await teacherPage.getByLabel('Contraseña', { exact: true }).fill('password123')
    await teacherPage.getByRole('button', { name: 'Iniciar sesión', exact: true }).click()
    await expect(teacherPage).toHaveURL(/\/dashboard/)
    await expect(teacherPage.getByRole('heading', { name: 'Tu panel de clases' })).toBeVisible()

    // Forzar una nueva petición en cada contexto: no basta con que la pestaña
    // antigua conserve el HTML de un usuario que ya no está autenticado.
    await adminPage.reload()
    await expect(adminPage.getByRole('heading', { name: 'Solicitudes — Admin' })).toBeVisible()
    await teacherPage.reload()
    await expect(teacherPage.getByRole('heading', { name: 'Tu panel de clases' })).toBeVisible()
  } finally {
    await teacherContext.close()
    await adminContext.close()
  }
})

test('profesor: perfil, disponibilidad temporal y cancelación segura del cierre de cuenta', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Correo electrónico').fill('profesor@mova.test')
  await page.getByLabel('Contraseña', { exact: true }).fill('password123')
  await page.getByRole('button', { name: 'Iniciar sesión', exact: true }).click()
  await expect(page).toHaveURL(/\/dashboard/)
  await expect(page.getByRole('heading', { name: 'Tu panel de clases' })).toBeVisible()

  // Cambiar de pestaña no equivale a guardar datos ni salir de la sesión.
  await page.goto('/profile')
  await page.getByRole('tab', { name: 'Seguridad' }).click()
  await expect(page.getByRole('tabpanel', { name: 'Seguridad' })).toBeVisible()
  await page.getByRole('tab', { name: 'Privacidad y cuenta' }).click()
  await page.getByRole('button', { name: 'Eliminar mi cuenta' }).click()
  const closure = page.getByRole('dialog', { name: 'Antes de eliminar tu cuenta' })
  await expect(closure).toBeVisible()
  await expect(closure.getByRole('button', { name: 'Eliminar mi cuenta' })).toBeDisabled()
  await page.keyboard.press('Escape')
  await expect(closure).toHaveCount(0)

  const mutations = []
  page.on('request', (r) => {
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(r.method()) &&
      /\/teacher\/(availability|profile)|\/account\/closure/.test(new URL(r.url()).pathname)) {
      mutations.push(`${r.method()} ${new URL(r.url()).pathname}`)
    }
  })
  await page.goto('/teacher/profile')
  const slots = page.getByRole('form', { name: 'Mi disponibilidad semanal' })
  const initialCount = await slots.getByRole('button', { name: /Quitar franja/ }).count()
  await slots.getByRole('button', { name: '+ Agregar franja' }).first().click()
  await expect(slots.getByRole('textbox', { name: 'Inicio, Lunes' }).last()).toHaveValue('15:00')
  await slots.getByRole('button', { name: 'Quitar franja de Lunes' }).last().click()
  await expect(slots.getByRole('button', { name: /Quitar franja/ })).toHaveCount(initialCount)
  expect(mutations).toHaveLength(0)
  await expect(page.getByRole('heading', { name: 'Mi perfil', exact: true }).last()).toBeVisible()
})
