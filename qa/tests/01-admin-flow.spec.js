// @ts-check
import { test, expect } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';
import fs from 'fs';

dotenv.config({ path: path.resolve(import.meta.dirname, '../.env.qa') });

const BASE = process.env.BASE_URL;
const ADMIN_EMAIL = process.env.QA_ADMIN_EMAIL;
const ADMIN_PASSWORD = process.env.QA_ADMIN_PASSWORD;
const AUTH_FILE = path.resolve(import.meta.dirname, '../auth/admin.json');

// Shared login helper — fills the form and waits for redirect
async function performLogin(page, email, password) {
  await page.goto(`${BASE}/login`);
  await page.waitForLoadState('networkidle');
  // Extra wait for Vue hydration
  await page.waitForTimeout(800);
  await page.locator('#email').fill(email);
  await page.locator('#password').fill(password);
  // Submit by pressing Enter (bypasses button-click issues)
  await page.locator('#password').press('Enter');
  await page.waitForURL(/dashboard|verify-email|admin|teacher/, { timeout: 25000 });
}

test.describe('Admin flow', () => {
  let adminAuthSaved = false;

  // Login once, save storage state, reuse for remaining tests
  test.beforeAll(async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
      await performLogin(page, ADMIN_EMAIL, ADMIN_PASSWORD);
      await ctx.storageState({ path: AUTH_FILE });
      adminAuthSaved = true;
      console.log('Admin auth saved to', AUTH_FILE);
    } catch (e) {
      console.warn('Admin auth setup failed:', e.message);
    } finally {
      await ctx.close();
    }
  });

  test('healthz endpoint responds 200', async ({ request }) => {
    const res = await request.get(`${BASE}/healthz`);
    expect(res.status()).toBe(200);
  });

  test('login page loads (unauthenticated)', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(`${BASE}/login`);
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#email')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
    await page.screenshot({ path: 'reports/login-page.png' });
    console.log('Login page loaded OK');
    await ctx.close();
  });

  test('admin can login and reach dashboard', async ({ browser }) => {
    // Use saved auth state if available
    const ctx = adminAuthSaved
      ? await browser.newContext({ storageState: AUTH_FILE })
      : await browser.newContext();
    const page = await ctx.newPage();
    if (!adminAuthSaved) {
      await performLogin(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    } else {
      await page.goto(`${BASE}/dashboard`);
      await page.waitForLoadState('networkidle');
    }
    const url = page.url();
    console.log('Admin URL:', url);
    expect(url).not.toContain('/login');
    await page.screenshot({ path: 'reports/admin-dashboard.png' });
    await ctx.close();
  });

  test('admin can see pending teachers', async ({ browser }) => {
    const ctx = adminAuthSaved
      ? await browser.newContext({ storageState: AUTH_FILE })
      : await browser.newContext();
    const page = await ctx.newPage();
    if (!adminAuthSaved) {
      await performLogin(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    }
    await page.goto(`${BASE}/admin/pending-teachers`);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: 'reports/admin-pending-teachers.png' });
    const url = page.url();
    expect(url).not.toContain('/login');
    // Check for Laravel/Ignition error page (not minified JS)
    await expect(page.locator('title')).not.toContainText('500');
    await expect(page.locator('title')).not.toContainText('Whoops');
    // Page should not redirect back to login
    const bodyText = await page.locator('body').innerText().catch(() => '');
    const hasError = bodyText.includes('Whoops! Something went wrong') || bodyText.includes('Server Error');
    expect(hasError).toBe(false);
    const hasPending = bodyText.includes('verif') || bodyText.includes('pendiente') || bodyText.includes('Pendiente') || bodyText.includes('profesor');
    const hasEmpty = bodyText.includes('no hay') || bodyText.includes('No hay') || bodyText.includes('sin profesores');
    console.log('Admin pending-teachers OK | Has pending teachers:', hasPending, '| Has empty state:', hasEmpty);
    await ctx.close();
  });
});
