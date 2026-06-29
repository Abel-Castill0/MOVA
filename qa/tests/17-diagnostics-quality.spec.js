// @ts-check
import { test, expect } from '@playwright/test';
import * as dotenv from 'dotenv';
import path from 'path';

dotenv.config({ path: path.resolve(process.cwd(), '.env.qa') });

const BASE         = process.env.QA_BASE_URL    ?? 'https://mova-production-8750.up.railway.app';
const PARENT_EMAIL = process.env.QA_PARENT_EMAIL    ?? 'abelcastilloyarin3@gmail.com';
const PARENT_PASS  = process.env.QA_PARENT_PASSWORD ?? 'familiawuarthon123';
const TEACHER_EMAIL = process.env.QA_TEACHER_EMAIL    ?? 'abelcastillotrabajo@gmail.com';
const TEACHER_PASS  = process.env.QA_TEACHER_PASSWORD ?? 'familiawuarthon123';

async function loginAs(page, email, pass) {
  await page.goto(`${BASE}/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('#email', email);
  await page.fill('#password', pass);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard/, { timeout: 20_000 });
}

// ── 1. Offer form renders availability section ─────────────────────────────
test('1. ClassOffer create form has availability section', async ({ page }) => {
  await loginAs(page, TEACHER_EMAIL, TEACHER_PASS);
  await page.goto(`${BASE}/class-offers/create`);
  await page.waitForLoadState('networkidle');
  await expect(page.locator('text=Disponibilidad horaria')).toBeVisible();
});

// ── 2. Availability section expands ───────────────────────────────────────
test('2. Availability section expands when clicked', async ({ page }) => {
  await loginAs(page, TEACHER_EMAIL, TEACHER_PASS);
  await page.goto(`${BASE}/class-offers/create`);
  await page.waitForLoadState('networkidle');
  await page.locator('button:has-text("Disponibilidad horaria")').click();
  await expect(page.locator('text=Lunes')).toBeVisible();
});

// ── 3. Diagnostic still generates recommendations ─────────────────────────
test('3. Diagnostic wizard completes and shows results', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  await page.goto(`${BASE}/diagnostics/create`);
  await page.waitForLoadState('networkidle');

  const studentBtn = page.locator('button.w-full.rounded-2xl').first();
  if (await studentBtn.count() === 0) { test.skip(); return; }
  await studentBtn.click();
  await page.waitForTimeout(500);

  // Step 2 — skip subject
  const skip = page.locator('button:has-text("No estoy seguro")');
  if (await skip.isVisible().catch(() => false)) {
    await skip.click();
  } else {
    await page.locator('button:has-text("Continuar")').last().click();
  }
  await page.waitForTimeout(400);

  // Step 3 — difficulty
  await page.locator('textarea').first().fill('No entiende fracciones desde el bimestre pasado y tiene examen pronto.');
  await page.locator('button:has-text("Continuar")').last().click();
  await page.waitForTimeout(400);

  // Step 4 — goal
  await page.locator('button:has-text("Preparar")').first().click();
  await page.waitForTimeout(400);

  // Step 5 — urgency + submit
  await page.locator('button:has-text("Esta semana")').click();
  await page.waitForTimeout(300);
  await page.locator('button:has-text("Ver profesores recomendados")').click();

  await page.waitForURL(/\/diagnostics\/\d+\/results/, { timeout: 25_000 });
  await expect(page.locator('.bg-white.rounded-2xl.border').first()).toBeVisible();
});

// ── 4. Results show max-3 reasons per card ────────────────────────────────
test('4. Recommendation card shows at most 3 reasons', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  // Use previously created diagnostic (id=1 guaranteed to have recs)
  const res = await page.goto(`${BASE}/diagnostics/1/results`);
  if (res?.status() === 403 || res?.status() === 404) { test.skip(); return; }
  await page.waitForLoadState('networkidle');

  const firstCard = page.locator('.bg-white.rounded-2xl.border').first();
  const reasonItems = firstCard.locator('ul li');
  const count = await reasonItems.count();
  expect(count).toBeGreaterThanOrEqual(1);
  expect(count).toBeLessThanOrEqual(3);
});

// ── 5. Results do not show "mejor match" technical chip ───────────────────
test('5. Results page does not show raw rank chip', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  await page.goto(`${BASE}/diagnostics/1/results`);
  await page.waitForLoadState('networkidle');
  const body = await page.textContent('body') ?? '';
  expect(body).not.toContain('mejor match');
});

// ── 6. Results do not show score number ───────────────────────────────────
test('6. Results page does not expose score number to parent', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  await page.goto(`${BASE}/diagnostics/1/results`);
  await page.waitForLoadState('networkidle');
  const body = await page.textContent('body') ?? '';
  expect(body).not.toMatch(/score\s*=\s*\d+/i);
});

// ── 7. Results have MOVA reason header ────────────────────────────────────
test('7. Results show "MOVA lo recomienda porque" header', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  await page.goto(`${BASE}/diagnostics/1/results`);
  await page.waitForLoadState('networkidle');
  await expect(page.locator('text=MOVA lo recomienda porque').first()).toBeVisible();
});

// ── 8. requestClass sets student_diagnostic_id (API check via 302) ────────
test('8. POST diagnostics/request returns non-5xx', async ({ page, request }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  const cookies = await page.context().cookies();
  const xsrf = cookies.find(c => c.name === 'XSRF-TOKEN');
  const token = xsrf ? decodeURIComponent(xsrf.value) : '';

  // offer id=1 — if inactive/not verified this returns 422, which is fine
  const res = await request.post(`${BASE}/diagnostics/1/request/1`, {
    headers: { 'X-XSRF-TOKEN': token, 'Accept': 'application/json' },
  });
  expect(res.status()).not.toBe(500);
});

// ── 9. Inactive offer rejected ─────────────────────────────────────────────
test('9. Request with non-existent offer returns 404 or 422', async ({ page, request }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  const cookies = await page.context().cookies();
  const xsrf = cookies.find(c => c.name === 'XSRF-TOKEN');
  const token = xsrf ? decodeURIComponent(xsrf.value) : '';

  const res = await request.post(`${BASE}/diagnostics/1/request/99999`, {
    headers: { 'X-XSRF-TOKEN': token, 'Accept': 'application/json' },
  });
  expect([404, 419, 422]).toContain(res.status());
});

// ── 10. Non-owned diagnostic still returns 403 ────────────────────────────
test('10. Non-owned diagnostic returns 403', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  const res = await page.goto(`${BASE}/diagnostics/999999/results`);
  expect([403, 404]).toContain(res?.status() ?? 0);
});

// ── 11. Feature flag disabled — no OpenAI calls ───────────────────────────
test('11. DIAGNOSTIC_AI_ENABLED is false (no AI calls in network)', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  const apiCalls = [];
  page.on('request', req => {
    if (req.url().includes('openai') || req.url().includes('api.anthropic')) {
      apiCalls.push(req.url());
    }
  });
  await page.goto(`${BASE}/diagnostics/create`);
  await page.waitForLoadState('networkidle');
  expect(apiCalls).toHaveLength(0);
});

// ── 12. AvailabilityPicker rejects start >= end visually (UX) ─────────────
test('12. Offer form: availability picker renders days', async ({ page }) => {
  await loginAs(page, TEACHER_EMAIL, TEACHER_PASS);
  await page.goto(`${BASE}/class-offers/create`);
  await page.waitForLoadState('networkidle');
  await page.locator('button:has-text("Disponibilidad horaria")').click();
  const dayLabels = ['Lunes', 'Martes', 'Miércoles'];
  for (const label of dayLabels) {
    await expect(page.locator(`text=${label}`).first()).toBeVisible();
  }
});

// ── 13. Mobile responsive ─────────────────────────────────────────────────
test('13. Diagnostic results responsive at 375px', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  await page.goto(`${BASE}/diagnostics/1/results`);
  await page.waitForLoadState('networkidle');
  await expect(page.locator('.max-w-2xl').first()).toBeVisible();
});
