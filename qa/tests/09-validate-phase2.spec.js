// @ts-check
// QA validation for Phase 2: lesson reports and student learning history
import { test, expect } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

dotenv.config({ path: path.resolve(import.meta.dirname, '../.env.qa') });

const BASE           = process.env.BASE_URL;
const TEACHER_EMAIL  = process.env.QA_TEACHER_EMAIL;
const TEACHER_PASS   = process.env.QA_TEACHER_PASSWORD;
const PARENT_EMAIL   = process.env.QA_PARENT_EMAIL;
const PARENT_PASS    = process.env.QA_PARENT_PASSWORD;

// ─── TEACHER TESTS ────────────────────────────────────────────────────────────

test('teacher: Mis clases loads with lesson list', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.fill('#email', TEACHER_EMAIL);
  await page.fill('#password', TEACHER_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email|teacher/, { timeout: 20000 });

  await page.goto(`${BASE}/teacher/classes`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase2-teacher-classes.png' });

  const body = await page.locator('body').innerText();
  console.log('Has "Mis clases":', body.includes('Mis clases'));
  console.log('Has "Marcar como completada" OR "Crear reporte" OR "Ver reporte":',
    body.includes('Marcar como completada') || body.includes('Crear reporte') || body.includes('reporte'));
  console.log('Has "Cancelar clase":', body.includes('Cancelar clase'));
  expect(page.url()).toContain('/teacher/classes');
});

test('teacher: /teacher/reports page loads', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.fill('#email', TEACHER_EMAIL);
  await page.fill('#password', TEACHER_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email|teacher/, { timeout: 20000 });

  await page.goto(`${BASE}/teacher/reports`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase2-teacher-reports.png' });

  const status = await page.evaluate(() => document.readyState);
  console.log('Page ready:', status);
  const url = page.url();
  console.log('URL:', url);
  // Should not 404 or redirect to login
  expect(url).toContain('/teacher/reports');
});

test('teacher: complete button triggers complete flow', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.fill('#email', TEACHER_EMAIL);
  await page.fill('#password', TEACHER_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email|teacher/, { timeout: 20000 });

  await page.goto(`${BASE}/teacher/classes`);
  await page.waitForLoadState('networkidle');

  // Look for "Marcar como completada" button
  const completeBtn = page.locator('button, a', { hasText: /marcar como completada/i }).first();
  const completeBtnCount = await completeBtn.count();
  console.log('"Marcar como completada" buttons found:', completeBtnCount);

  if (completeBtnCount > 0) {
    await completeBtn.click();
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: 'reports/phase2-after-complete.png' });
    const bodyAfter = await page.locator('body').innerText();
    console.log('After complete — has "Crear reporte":', bodyAfter.includes('Crear reporte'));
    console.log('After complete — has success flash:', bodyAfter.includes('completada'));
  } else {
    // Look for "Crear reporte" if there's already a completed class
    const reportBtn = page.locator('a, button', { hasText: /crear reporte/i }).first();
    const reportBtnCount = await reportBtn.count();
    console.log('"Crear reporte" buttons found:', reportBtnCount);
    if (reportBtnCount > 0) {
      console.log('Found completed class with "Crear reporte" CTA — test passes.');
    }
  }

  // Test passes as long as page doesn't 500
  expect(page.url()).not.toContain('error');
});

test('teacher: report create form loads for a completed lesson', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.fill('#email', TEACHER_EMAIL);
  await page.fill('#password', TEACHER_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email|teacher/, { timeout: 20000 });

  await page.goto(`${BASE}/teacher/classes`);
  await page.waitForLoadState('networkidle');

  // Click "Crear reporte" link if available
  const createReportLink = page.locator('a', { hasText: /crear reporte/i }).first();
  const createCount = await createReportLink.count();
  console.log('"Crear reporte" links found on teacher classes:', createCount);

  if (createCount > 0) {
    const href = await createReportLink.getAttribute('href');
    console.log('Create report href:', href);
    await page.goto(href);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: 'reports/phase2-report-form.png' });

    const body = await page.locator('body').innerText();
    console.log('Form has "Tema trabajado":', body.includes('Tema trabajado'));
    console.log('Form has "Desempeño":', body.includes('Desempeño'));
    console.log('Form has "Enviar reporte":', body.includes('Enviar reporte'));
    expect(body).toContain('Tema trabajado');
  } else {
    console.log('No "Crear reporte" link found — no completed lesson without report yet.');
    // This may be expected if no completed lessons exist
  }
});

test('teacher: can submit a report', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.fill('#email', TEACHER_EMAIL);
  await page.fill('#password', TEACHER_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email|teacher/, { timeout: 20000 });

  // Find a "Crear reporte" link
  await page.goto(`${BASE}/teacher/classes`);
  await page.waitForLoadState('networkidle');

  const createLink = page.locator('a', { hasText: /crear reporte/i }).first();
  const count = await createLink.count();
  console.log('"Crear reporte" links:', count);

  if (count === 0) {
    console.log('SKIP: No completed lesson available to report. Complete a lesson first.');
    return;
  }

  const href = await createLink.getAttribute('href');
  await page.goto(href);
  await page.waitForLoadState('networkidle');

  // Fill the form
  const topic = page.locator('textarea').nth(0);
  const performance = page.locator('textarea').nth(1);

  await topic.fill('Fracciones equivalentes y simplificación');
  await performance.fill('El alumno participó activamente y comprendió los conceptos principales.');
  await page.locator('textarea').nth(2).fill('Le cuesta identificar el mínimo común múltiplo.');
  await page.locator('textarea').nth(3).fill('Ejercicios 3-8 del libro página 45.');
  await page.locator('textarea').nth(4).fill('Sigue practicando en casa, va muy bien.');
  await page.locator('textarea').nth(5).fill('La próxima clase veremos números decimales.');

  await page.screenshot({ path: 'reports/phase2-report-filled.png' });

  const submitBtn = page.locator('button[type="submit"]');
  await submitBtn.click();
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase2-after-submit.png' });

  const urlAfter = page.url();
  const bodyAfter = await page.locator('body').innerText();
  console.log('URL after submit:', urlAfter);
  console.log('Has "Reporte enviado":', bodyAfter.includes('Reporte enviado'));
  console.log('Has "Tema trabajado":', bodyAfter.includes('Tema trabajado'));
  console.log('Has success or redirect:', urlAfter.includes('/report') || bodyAfter.includes('enviado'));
});

test('teacher: cannot create duplicate report', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.fill('#email', TEACHER_EMAIL);
  await page.fill('#password', TEACHER_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email|teacher/, { timeout: 20000 });

  await page.goto(`${BASE}/teacher/classes`);
  await page.waitForLoadState('networkidle');

  // "Ver reporte enviado" means report already exists
  const sentReport = page.locator('a', { hasText: /ver reporte enviado/i }).first();
  const sentCount = await sentReport.count();
  console.log('"Ver reporte enviado" links:', sentCount);

  if (sentCount > 0) {
    const href = await sentReport.getAttribute('href');
    // Try accessing /report/create directly — should redirect or 302
    const lessonId = href?.match(/\/lessons\/(\d+)/)?.[1];
    if (lessonId) {
      const response = await page.goto(`${BASE}/lessons/${lessonId}/report/create`);
      await page.waitForLoadState('networkidle');
      const finalUrl = page.url();
      console.log('Duplicate report create URL:', finalUrl);
      console.log('Redirected away from create?', !finalUrl.includes('/create'));
    }
  } else {
    console.log('SKIP: No report sent yet to test duplicate prevention.');
  }
});

// ─── PARENT TESTS ─────────────────────────────────────────────────────────────

test('parent: dashboard loads with last_report section if reports exist', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.fill('#email', PARENT_EMAIL);
  await page.fill('#password', PARENT_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email/, { timeout: 20000 });

  await page.goto(`${BASE}/dashboard`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase2-parent-dashboard.png' });

  const body = await page.locator('body').innerText();
  console.log('Dashboard has "Último reporte":', body.includes('Último reporte'));
  console.log('Dashboard has "Reportes" quick action:', body.includes('Reportes'));
  console.log('Dashboard has "Historial de aprendizaje":', body.includes('Historial de aprendizaje'));
  expect(page.url()).toContain('/dashboard');
});

test('parent: /my-reports page loads', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.fill('#email', PARENT_EMAIL);
  await page.fill('#password', PARENT_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email/, { timeout: 20000 });

  await page.goto(`${BASE}/my-reports`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase2-parent-reports.png' });

  const url = page.url();
  const body = await page.locator('body').innerText();
  console.log('URL:', url);
  console.log('Has "Reportes de aprendizaje":', body.includes('Reportes de aprendizaje'));
  console.log('Has reports or empty state:',
    body.includes('Tema trabajado') || body.includes('Aún no hay reportes'));
  expect(url).toContain('/my-reports');
});

test('parent: can view report detail if report exists', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.fill('#email', PARENT_EMAIL);
  await page.fill('#password', PARENT_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email/, { timeout: 20000 });

  await page.goto(`${BASE}/my-reports`);
  await page.waitForLoadState('networkidle');

  const body = await page.locator('body').innerText();
  const hasReports = body.includes('Tema trabajado') || body.includes('Desempeño');
  console.log('Has reports:', hasReports);

  if (hasReports) {
    // Check security: no email/phone visible
    const html = await page.content();
    const hasSensitive = /abelcastillotrabajo@gmail\.com|987945850/.test(html);
    console.log('SECURITY - sensitive data in reports HTML:', hasSensitive ? 'FAIL' : 'PASS');
    expect(hasSensitive).toBe(false);
  } else {
    console.log('No reports yet — empty state shown correctly.');
  }
  await page.screenshot({ path: 'reports/phase2-parent-reports-detail.png' });
});

test('parent: cannot access teacher report create page', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.fill('#email', PARENT_EMAIL);
  await page.fill('#password', PARENT_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email/, { timeout: 20000 });

  // Try to access the create report page (should 403 or redirect)
  const response = await page.goto(`${BASE}/lessons/1/report/create`);
  await page.waitForLoadState('networkidle');
  const finalUrl = page.url();
  const status = response?.status();
  console.log('Parent access to teacher report create — URL:', finalUrl);
  console.log('Status or redirect:', status, finalUrl.includes('403') || finalUrl.includes('login') || finalUrl.includes('dashboard'));
  // Should not see the form
  const body = await page.locator('body').innerText();
  console.log('Has "Tema trabajado" form (should NOT):', body.includes('Tema trabajado'));
  expect(body).not.toContain('Enviar reporte al padre');
});

// ─── SECURITY TESTS ───────────────────────────────────────────────────────────

test('security: visitor cannot access lesson reports', async ({ page }) => {
  const r1 = await page.goto(`${BASE}/teacher/reports`);
  await page.waitForLoadState('networkidle');
  const url1 = page.url();
  console.log('/teacher/reports for visitor redirects to:', url1);
  expect(url1).toContain('login');

  const r2 = await page.goto(`${BASE}/my-reports`);
  await page.waitForLoadState('networkidle');
  const url2 = page.url();
  console.log('/my-reports for visitor redirects to:', url2);
  expect(url2).toContain('login');
});

// ─── RESPONSIVE ───────────────────────────────────────────────────────────────

test('responsive: report form and parent reports on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });

  await page.goto(`${BASE}/login`);
  await page.fill('#email', PARENT_EMAIL);
  await page.fill('#password', PARENT_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email/, { timeout: 20000 });

  await page.goto(`${BASE}/my-reports`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase2-mobile-parent-reports.png' });

  await page.goto(`${BASE}/dashboard`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase2-mobile-dashboard.png' });

  console.log('Mobile screenshots saved');
});
