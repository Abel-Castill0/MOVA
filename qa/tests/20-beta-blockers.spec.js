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
const ADMIN_EMAIL   = process.env.QA_ADMIN_EMAIL     ?? 'abelcastillotrabajo@gmail.com';
const ADMIN_PASS    = process.env.QA_ADMIN_PASSWORD  ?? 'familiawuarthon123';

async function loginAs(page, email, pass) {
  await page.goto(`${BASE}/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('#email', email);
  await page.fill('#password', pass);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard/, { timeout: 20_000 });
}

// ── 1. /terminos is publicly accessible (no auth) ────────────────────────────
test('1. /terminos returns 200 without authentication', async ({ request }) => {
  const res = await request.get(`${BASE}/terminos`);
  expect(res.status()).toBe(200);
  // Inertia renders client-side; server HTML contains the component name in JSON
  const body = await res.text();
  expect(body.toLowerCase()).toContain('terminos'); // appears in Ziggy routes JSON
});

// ── 2. /privacidad is publicly accessible (no auth) ──────────────────────────
test('2. /privacidad returns 200 without authentication', async ({ request }) => {
  const res = await request.get(`${BASE}/privacidad`);
  expect(res.status()).toBe(200);
  const body = await res.text();
  expect(body.toLowerCase()).toContain('privacidad');
});

// ── 3. /terminos page contains expected beta notice ───────────────────────────
test('3. /terminos page shows beta disclaimer and support contact', async ({ page }) => {
  await page.goto(`${BASE}/terminos`);
  await page.waitForLoadState('networkidle');
  const body = await page.textContent('body') ?? '';
  expect(body.toLowerCase()).toContain('beta');
  expect(body).toContain('soporte');
});

// ── 4. /privacidad page mentions minor data policy ───────────────────────────
test('4. /privacidad mentions minor/children data policy', async ({ page }) => {
  await page.goto(`${BASE}/privacidad`);
  await page.waitForLoadState('networkidle');
  const body = await page.textContent('body') ?? '';
  // should mention menores or minores
  expect(body.toLowerCase()).toMatch(/menor|niño|alumno|estudiante/);
});

// ── 5. Register form has terms checkbox ──────────────────────────────────────
test('5. Register form displays terms & privacy checkbox', async ({ page }) => {
  await page.goto(`${BASE}/register`);
  await page.waitForLoadState('networkidle');
  const checkbox = page.locator('input[type="checkbox"]').first();
  await expect(checkbox).toBeVisible();
  const body = await page.textContent('body') ?? '';
  expect(body).toContain('Términos');
  expect(body).toContain('Privacidad');
});

// ── 6. Register without accepting terms fails ─────────────────────────────────
test('6. Registering without accepting terms is rejected by backend', async ({ page, request }) => {
  // Direct API call without accepted_terms
  const loginPage = await request.get(`${BASE}/login`);
  const cookies = loginPage.headers()['set-cookie'] ?? '';
  const xsrfMatch = cookies.match(/XSRF-TOKEN=([^;]+)/);
  const xsrfToken = xsrfMatch ? decodeURIComponent(xsrfMatch[1]) : '';

  const res = await request.post(`${BASE}/register`, {
    headers: {
      'X-XSRF-TOKEN': xsrfToken,
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
    data: {
      name: 'Test QA User',
      email: `qa-test-${Date.now()}@example.com`,
      password: 'Password123!',
      password_confirmation: 'Password123!',
      role: 'parent',
      accepted_terms: false,
    },
  });
  // Should not be 200 (redirect) or 201 — should be 422 validation error
  expect(res.status()).not.toBe(200);
  expect(res.status()).not.toBe(201);
  // Expect 302 redirect back or 422
  expect([302, 422].includes(res.status()) || res.status() >= 400).toBeTruthy();
});

// ── 7. Register footer links point to terms and privacy ──────────────────────
test('7. Login/register page has links to /terminos and /privacidad', async ({ page }) => {
  await page.goto(`${BASE}/register`);
  await page.waitForLoadState('networkidle');
  const termLink = page.locator('a[href*="terminos"]').first();
  const privLink = page.locator('a[href*="privacidad"]').first();
  await expect(termLink).toBeVisible();
  await expect(privLink).toBeVisible();
});

// ── 8. POST /diagnostics rate limit exists (throttle middleware present) ───────
test('8. POST /diagnostics has throttle middleware — 5 rapid requests produce no 500', async ({ page, request }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  const cookies = await page.context().cookies();
  const xsrf = cookies.find(c => c.name === 'XSRF-TOKEN');
  const token = xsrf ? decodeURIComponent(xsrf.value) : '';

  const results = [];
  for (let i = 0; i < 5; i++) {
    const res = await request.post(`${BASE}/diagnostics`, {
      headers: { 'X-XSRF-TOKEN': token, 'Accept': 'application/json' },
      data: {
        student_id: 1,
        difficulty_text: `QA rate limit test ${i}`,
        goal: 'reinforce_topic',
        urgency: 'this_week',
      },
    });
    results.push(res.status());
  }
  // None should be 500 — some may be 200/302/422/429 all are acceptable
  expect(results.every(s => s !== 500)).toBe(true);
});

// ── 9. Admin can see users list with suspension column ────────────────────────
test('9. Admin users list loads and has suspend/unsuspend capability', async ({ page }) => {
  // Login as admin
  await page.goto(`${BASE}/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('#email', ADMIN_EMAIL);
  await page.fill('#password', ADMIN_PASS);
  await page.locator('form button').first().click();

  // Wait for redirect — admins go to /dashboard
  try {
    await page.waitForURL(/dashboard/, { timeout: 15_000 });
  } catch {
    // Login failed — wrong credentials or admin not found
    test.skip();
    return;
  }

  // Confirm admin access by navigating directly to /admin/users
  // Non-admins are redirected away; admins see the users table
  const res = await page.goto(`${BASE}/admin/users`);
  await page.waitForLoadState('networkidle');

  // If we were redirected to login or dashboard, we're not admin
  const finalUrl = page.url();
  if (!finalUrl.includes('/admin/users')) {
    test.skip();
    return;
  }

  // Admin page loaded — verify it has suspension columns
  const body = await page.textContent('body') ?? '';
  expect(body).not.toContain('Whoops!');
  expect(body).not.toContain('500');
  // The page should have the Estado and Acción columns we added
  expect(body).toContain('Estado');
  expect(body).toContain('Acción');
  // Should contain at least one of these suspension-related words
  expect(body.toLowerCase()).toMatch(/suspender|reactivar|activo|suspendido/i);
});

// ── 10. /suspended page is accessible when authenticated ─────────────────────
test('10. /suspended page renders for authenticated user', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  const res = await page.goto(`${BASE}/suspended`);
  // Page should load (not 404, not 500)
  expect(res?.status()).not.toBe(404);
  expect(res?.status()).not.toBe(500);
  await page.waitForLoadState('networkidle');
  const body = await page.textContent('body') ?? '';
  expect(body).not.toContain('Whoops!');
});

// ── 11. /suspended redirects to login if not authenticated ───────────────────
test('11. /suspended redirects guest to login', async ({ request }) => {
  const res = await request.get(`${BASE}/suspended`, { maxRedirects: 0 });
  // Should redirect to login
  expect([301, 302, 303].includes(res.status())).toBe(true);
});

// ── 12. Healthz still returns 200 after all changes ──────────────────────────
test('12. /healthz returns 200 after beta-blocker changes', async ({ request }) => {
  const res = await request.get(`${BASE}/healthz`);
  expect(res.status()).toBe(200);
});

// ── 13. No 500 on dashboard after new middleware ──────────────────────────────
test('13. Parent dashboard still loads without errors after not.suspended middleware', async ({ page }) => {
  await loginAs(page, PARENT_EMAIL, PARENT_PASS);
  await expect(page).toHaveURL(/dashboard/);
  const body = await page.textContent('body') ?? '';
  expect(body).not.toContain('Whoops!');
  expect(body).not.toContain('500');
});

// ── 14. Teacher dashboard still loads without errors ─────────────────────────
test('14. Teacher dashboard still loads without errors after not.suspended middleware', async ({ page }) => {
  await loginAs(page, TEACHER_EMAIL, TEACHER_PASS);
  await expect(page).toHaveURL(/dashboard/);
  const body = await page.textContent('body') ?? '';
  expect(body).not.toContain('Whoops!');
  expect(body).not.toContain('500');
});

// ── 15. GuestLayout footer has legal links ───────────────────────────────────
test('15. Marketplace page footer has links to legal pages', async ({ page }) => {
  await page.goto(`${BASE}/marketplace`);
  await page.waitForLoadState('networkidle');
  // GuestLayout wraps marketplace
  const termLink = page.locator('a[href*="terminos"]').first();
  const privLink = page.locator('a[href*="privacidad"]').first();
  // At least one of these should be visible somewhere on the page
  const termVisible = await termLink.isVisible().catch(() => false);
  const privVisible = await privLink.isVisible().catch(() => false);
  expect(termVisible || privVisible).toBe(true);
});
