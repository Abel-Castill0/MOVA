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
