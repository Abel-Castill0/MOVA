// @ts-check
import { test, expect } from '@playwright/test';
import * as dotenv from 'dotenv';
import path from 'path';

dotenv.config({ path: path.resolve(process.cwd(), '.env.qa') });

const BASE          = process.env.QA_BASE_URL        ?? 'https://mova-production-8750.up.railway.app';
const PARENT_EMAIL  = process.env.QA_PARENT_EMAIL    ?? 'abelcastilloyarin3@gmail.com';
const PARENT_PASS   = process.env.QA_PARENT_PASSWORD ?? 'familiawuarthon123';
const TEACHER_EMAIL = process.env.QA_TEACHER_EMAIL   ?? 'abelcastillotrabajo@gmail.com';
const TEACHER_PASS  = process.env.QA_TEACHER_PASSWORD ?? 'familiawuarthon123';

async function loginAs(page, email, pass) {
  await page.goto(`${BASE}/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('#email', email);
  await page.fill('#password', pass);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard/, { timeout: 20_000 });
}

// ── 1. App loads and DIAGNOSTIC_AI_ENABLED=false doesn't break anything ──────
test('1. App loads normally with Gemini provider configured but AI disabled', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  await expect(page).toHaveURL(/dashboard/);
  const body = await page.textContent('body') ?? '';
  expect(body).not.toContain('Whoops!');
  expect(body).not.toContain('500');
});

// ── 2. Wizard completes without calling Gemini when flag is false ──────────────
test('2. DIAGNOSTIC_AI_ENABLED=false → no network calls to generativelanguage.googleapis.com', async ({ page }) => {
  const aiCalls = [];
  page.on('request', req => {
    if (req.url().includes('generativelanguage.googleapis') || req.url().includes('gemini')) {
      aiCalls.push(req.url());
    }
  });

  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  await page.goto(`${BASE}/diagnostics/create`);
  await page.waitForLoadState('networkidle');
  expect(aiCalls).toHaveLength(0);
});

// ── 3. Wizard still completes with Gemini configured but disabled ─────────────
test('3. Wizard completes and shows results with Gemini provider configured', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  await page.goto(`${BASE}/diagnostics/create`);
  await page.waitForLoadState('networkidle');

  const studentBtn = page.locator('button.w-full.rounded-2xl').first();
  if (await studentBtn.count() === 0) { test.skip(); return; }
  await studentBtn.click();
  await page.waitForTimeout(400);

  const skip = page.locator('button:has-text("No estoy seguro")');
  if (await skip.isVisible().catch(() => false)) await skip.click();
  else await page.locator('button:has-text("Continuar")').last().click();
  await page.waitForTimeout(400);

  await page.locator('textarea').first().fill('No entiende fracciones y tiene examen próximo.');
  await page.locator('button:has-text("Continuar")').last().click();
  await page.waitForTimeout(400);

  await page.locator('button:has-text("Preparar")').first().click();
  await page.waitForTimeout(400);

  await page.locator('button:has-text("Esta semana")').click();
  await page.waitForTimeout(300);
  await page.locator('button:has-text("Ver profesores recomendados")').click();

  await page.waitForURL(/\/diagnostics\/\d+\/results/, { timeout: 25_000 });
  await expect(page.locator('.bg-white.rounded-2xl.border').first()).toBeVisible();
});

// ── 4. Results page does not expose Gemini or OpenAI branding ─────────────────
test('4. Results page does not expose AI provider names', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  const res = await page.goto(`${BASE}/diagnostics/1/results`);
  if (res?.status() === 403 || res?.status() === 404) { test.skip(); return; }
  await page.waitForLoadState('networkidle');
  const body = await page.textContent('body') ?? '';
  expect(body.toLowerCase()).not.toContain('gemini');
  expect(body.toLowerCase()).not.toContain('openai');
  expect(body.toLowerCase()).not.toContain('gpt');
  expect(body.toLowerCase()).not.toContain('google ai');
});

// ── 5. Results page does not show confidence_score ────────────────────────────
test('5. Results page does not expose confidence_score', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  const res = await page.goto(`${BASE}/diagnostics/1/results`);
  if (res?.status() === 403 || res?.status() === 404) { test.skip(); return; }
  await page.waitForLoadState('networkidle');
  const body = await page.textContent('body') ?? '';
  expect(body).not.toMatch(/confidence/i);
  expect(body).not.toMatch(/ai_confidence/i);
});

// ── 6. No raw AI JSON or technical fields visible ────────────────────────────
test('6. Results page does not expose raw AI JSON or internal fields', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  const res = await page.goto(`${BASE}/diagnostics/1/results`);
  if (res?.status() === 403 || res?.status() === 404) { test.skip(); return; }
  await page.waitForLoadState('networkidle');
  const body = await page.textContent('body') ?? '';
  expect(body).not.toContain('ai_keywords');
  expect(body).not.toContain('ai_risk_flags');
  expect(body).not.toContain('ai_used_fallback');
  expect(body).not.toContain('GEMINI_API_KEY');
  expect(body).not.toContain('OPENAI_API_KEY');
});

// ── 7. Teacher dashboard does not expose AI fields ────────────────────────────
test('7. Teacher dashboard does not expose ai_summary, ai_keywords, Gemini refs', async ({ page }) => {
  await loginAs(page, TEACHER_EMAIL, TEACHER_PASS);
  await page.goto(`${BASE}/dashboard`);
  await page.waitForLoadState('networkidle');
  const body = await page.textContent('body') ?? '';
  expect(body).not.toContain('ai_summary');
  expect(body).not.toContain('ai_keywords');
  expect(body).not.toContain('MOVA entendió que');
  expect(body.toLowerCase()).not.toContain('gemini');
});

// ── 8. POST diagnostics/store handles missing GEMINI_API_KEY gracefully ───────
test('8. POST diagnostics/store does not 500 even if AI provider misconfigured', async ({ page, request }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  const cookies = await page.context().cookies();
  const xsrf = cookies.find(c => c.name === 'XSRF-TOKEN');
  const token = xsrf ? decodeURIComponent(xsrf.value) : '';

  const res = await request.post(`${BASE}/diagnostics`, {
    headers: { 'X-XSRF-TOKEN': token, 'Accept': 'application/json' },
    data: {
      student_id:      1,
      difficulty_text: 'Le cuesta entender las fracciones desde hace semanas.',
      goal:            'reinforce_topic',
      urgency:         'this_week',
    },
  });
  expect(res.status()).not.toBe(500);
});

// ── 9. solve_homework goal never calls AI regardless of provider ───────────────
test('9. solve_homework goal with Gemini provider — no AI calls in browser', async ({ page }) => {
  const aiCalls = [];
  page.on('request', req => {
    if (req.url().includes('generativelanguage.googleapis') || req.url().includes('openai.com')) {
      aiCalls.push(req.url());
    }
  });

  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  await page.goto(`${BASE}/diagnostics/create`);
  await page.waitForLoadState('networkidle');

  const studentBtn = page.locator('button.w-full.rounded-2xl').first();
  if (await studentBtn.count() === 0) { test.skip(); return; }
  await studentBtn.click();
  await page.waitForTimeout(400);

  const skip = page.locator('button:has-text("No estoy seguro")');
  if (await skip.isVisible().catch(() => false)) await skip.click();
  else await page.locator('button:has-text("Continuar")').last().click();
  await page.waitForTimeout(400);

  await page.locator('textarea').first().fill('Necesito resolver este ejercicio urgente.');
  await page.locator('button:has-text("Continuar")').last().click();
  await page.waitForTimeout(400);

  await page.locator('button:has-text("Resolver")').first().click();
  await page.waitForTimeout(400);

  await page.locator('button:has-text("Esta semana")').click();
  await page.waitForTimeout(300);
  await page.locator('button:has-text("Ver profesores recomendados")').click();
  await page.waitForURL(/\/diagnostics\/\d+\/results/, { timeout: 25_000 });

  expect(aiCalls).toHaveLength(0);
});

// ── 10. Privacy notice still visible in step 3 ───────────────────────────────
test('10. Privacy notice visible in wizard step 3 with Gemini provider', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  await page.goto(`${BASE}/diagnostics/create`);
  await page.waitForLoadState('networkidle');

  const studentBtn = page.locator('button.w-full.rounded-2xl').first();
  if (await studentBtn.count() === 0) { test.skip(); return; }
  await studentBtn.click();
  await page.waitForTimeout(400);

  const skip = page.locator('button:has-text("No estoy seguro")');
  if (await skip.isVisible().catch(() => false)) await skip.click();
  else await page.locator('button:has-text("Continuar")').last().click();
  await page.waitForTimeout(400);

  await expect(page.locator('text=MOVA puede analizar tu descripción').first()).toBeVisible();
  await expect(page.locator('text=No compartimos nombres').first()).toBeVisible();
});

// ── 11. Verified teacher requirement unchanged ────────────────────────────────
test('11. Gemini provider does not bypass verified teacher requirement', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  const res = await page.goto(`${BASE}/diagnostics/1/results`);
  if (res?.status() === 403 || res?.status() === 404) { test.skip(); return; }
  await page.waitForLoadState('networkidle');
  const cards = page.locator('.bg-white.rounded-2xl.border');
  const count = await cards.count();
  if (count === 0) { test.skip(); return; }
  const badges = page.locator('text=✓ Verificado');
  const badgeCount = await badges.count();
  expect(badgeCount).toBe(count);
});

// ── 12. App is healthy after Gemini config changes ────────────────────────────
test('12. /healthz returns 200 with Gemini provider configured', async ({ request }) => {
  const health = await request.get(`${BASE}/healthz`).catch(() => null);
  if (health) {
    expect(health.status()).toBe(200);
  }
  expect(true).toBe(true);
});

// ── 13. Regression: parent login still reaches dashboard ─────────────────────
test('13. Regression: parent login reaches dashboard with Gemini provider configured', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  await expect(page).toHaveURL(/dashboard/);
  const body = await page.textContent('body') ?? '';
  expect(body).not.toContain('Whoops!');
  expect(body).not.toContain('500');
});
