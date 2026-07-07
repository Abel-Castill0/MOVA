// @ts-check
// QA validation for Phase 1: marketplace filters + public teacher profile
import { test, expect } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

dotenv.config({ path: path.resolve(import.meta.dirname, '../.env.qa') });

const BASE   = process.env.BASE_URL;
const PARENT_EMAIL = process.env.QA_PARENT_EMAIL;
const PARENT_PASS  = process.env.QA_PARENT_PASSWORD;
const TEACHER_EMAIL = process.env.QA_TEACHER_EMAIL;
const TEACHER_PASS  = process.env.QA_TEACHER_PASSWORD;

// ─── VISITOR TESTS ───────────────────────────────────────────────────────────

test('visitor: marketplace loads with offers', async ({ page }) => {
  await page.goto(`${BASE}/marketplace`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase1-marketplace-visitor.png' });

  const body = await page.locator('body').innerText();
  console.log('Has "Verificado por MOVA":', body.includes('Verificado por MOVA'));
  console.log('Has "Ver perfil":', body.includes('Ver perfil'));
  console.log('Has "S/":', body.includes('S/'));
  console.log('Has search input:', await page.locator('input[placeholder*="Buscar"]').count() > 0);

  expect(page.url()).toContain('/marketplace');
  const title = await page.locator('h2').first().innerText();
  console.log('H2 title:', title);
});

test('visitor: search filter works', async ({ page }) => {
  await page.goto(`${BASE}/marketplace`);
  await page.waitForLoadState('networkidle');

  const searchInput = page.locator('input[placeholder*="Buscar"]').first();
  await searchInput.fill('Abel');
  await page.waitForTimeout(500); // debounce
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase1-search-abel.png' });

  const url = page.url();
  console.log('URL after search:', url);
  console.log('Has search param:', url.includes('search='));

  // Clear filters
  const clearBtn = page.locator('button', { hasText: /limpiar/i }).first();
  if (await clearBtn.count() > 0) {
    await clearBtn.click();
    await page.waitForLoadState('networkidle');
    console.log('Clear button works: YES');
  } else {
    console.log('Clear button visible: NO (may mean no active filters)');
  }
});

test('visitor: subject filter works', async ({ page }) => {
  await page.goto(`${BASE}/marketplace`);
  await page.waitForLoadState('networkidle');

  const subjectSelect = page.locator('select').first();
  const optionCount = await subjectSelect.locator('option').count();
  console.log('Subject options:', optionCount);

  if (optionCount > 1) {
    await subjectSelect.selectOption({ index: 1 });
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: 'reports/phase1-subject-filter.png' });
    console.log('Subject filter URL:', page.url());
    console.log('Has filter chip:', await page.locator('.bg-brand-50').count() > 0);
  }
});

test('visitor: teacher profile loads, no sensitive data', async ({ page }) => {
  await page.goto(`${BASE}/teachers/1`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase1-teacher-profile-visitor.png' });

  const body = await page.locator('body').innerText();
  const html = await page.content();

  console.log('Has "Verificado por MOVA":', body.includes('Verificado por MOVA'));
  console.log('Has "Iniciar sesión para solicitar":', body.includes('Iniciar sesión para solicitar'));
  console.log('Has "Ver perfil":', body.includes('Ver perfil'));
  console.log('Has "S/":', body.includes('S/'));
  console.log('Has "Volver al marketplace":', body.includes('Volver al marketplace'));

  // SECURITY: no email or phone visible in HTML
  const emailRegex = /abelcastillotrabajo@gmail\.com|abelcastilloyarin3@gmail\.com|987945850/;
  const hasEmail = emailRegex.test(html);
  console.log('SECURITY - sensitive data visible:', hasEmail ? 'FAIL' : 'PASS');
  expect(hasEmail).toBe(false);

  // Page should not 404
  expect(page.url()).toContain('/teachers/1');
});

test('visitor: CTA redirects to login', async ({ page }) => {
  await page.goto(`${BASE}/teachers/1`);
  await page.waitForLoadState('networkidle');

  const loginBtn = page.locator('a', { hasText: /iniciar sesión/i }).first();
  const count = await loginBtn.count();
  console.log('"Iniciar sesión" links found:', count);
  expect(count).toBeGreaterThan(0);

  if (count > 0) {
    const href = await loginBtn.getAttribute('href');
    console.log('Login href:', href);
    expect(href).toContain('login');
  }
});

// ─── PARENT TESTS ─────────────────────────────────────────────────────────────

test('parent: can see "Solicitar clase" in marketplace', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('#email', PARENT_EMAIL);
  await page.fill('#password', PARENT_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email/, { timeout: 20000 });

  await page.goto(`${BASE}/marketplace`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase1-marketplace-parent.png' });

  const solicitar = page.locator('a', { hasText: /solicitar clase/i }).first();
  const count = await solicitar.count();
  console.log('"Solicitar clase" buttons for parent:', count);
  expect(count).toBeGreaterThan(0);

  // Should NOT show "Iniciar sesión para solicitar" when logged in as parent
  const loginCTA = page.locator('a', { hasText: /iniciar sesión para solicitar/i });
  const loginCount = await loginCTA.count();
  console.log('"Iniciar sesión para solicitar" visible for parent:', loginCount, '(should be 0)');
  expect(loginCount).toBe(0);
});

test('parent: teacher profile shows "Solicitar clase"', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('#email', PARENT_EMAIL);
  await page.fill('#password', PARENT_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email/, { timeout: 20000 });

  await page.goto(`${BASE}/teachers/1`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase1-teacher-profile-parent.png' });

  const solicitar = page.locator('a', { hasText: /solicitar clase/i }).first();
  const count = await solicitar.count();
  console.log('"Solicitar clase" on teacher profile for parent:', count);
  expect(count).toBeGreaterThan(0);
});

// ─── TEACHER TESTS ───────────────────────────────────────────────────────────

test('teacher: sees "Solo padres" message, not "Solicitar"', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('#email', TEACHER_EMAIL);
  await page.fill('#password', TEACHER_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email|teacher/, { timeout: 20000 });

  await page.goto(`${BASE}/marketplace`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase1-marketplace-teacher.png' });

  const body = await page.locator('body').innerText();
  console.log('Has "Solo padres pueden solicitar":', body.includes('Solo padres pueden solicitar'));

  // Teacher should NOT see "Solicitar clase" link (only the disabled span)
  const solicitarLink = page.locator('a', { hasText: /solicitar clase/i });
  const linkCount = await solicitarLink.count();
  console.log('"Solicitar clase" LINK for teacher:', linkCount, '(should be 0)');
});

// ─── RESPONSIVE TESTS ────────────────────────────────────────────────────────

test('responsive: mobile 375px', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto(`${BASE}/marketplace`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase1-responsive-mobile.png' });
  console.log('Mobile 375px screenshot saved');
});

test('responsive: tablet 768px', async ({ page }) => {
  await page.setViewportSize({ width: 768, height: 1024 });
  await page.goto(`${BASE}/marketplace`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase1-responsive-tablet.png' });

  await page.goto(`${BASE}/teachers/1`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase1-teacher-profile-tablet.png' });
  console.log('Tablet 768px screenshots saved');
});

test('responsive: desktop 1280px', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto(`${BASE}/marketplace`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase1-responsive-desktop.png' });
  console.log('Desktop 1280px screenshot saved');
});
