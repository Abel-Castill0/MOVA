// @ts-check
import { test, expect } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

dotenv.config({ path: path.resolve(import.meta.dirname, '../.env.qa') });

const BASE = process.env.BASE_URL;
const PARENT_EMAIL = process.env.QA_PARENT_EMAIL;
const PARENT_PASSWORD = process.env.QA_PARENT_PASSWORD;

async function loginParent(page) {
  await page.goto(`${BASE}/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('#email', PARENT_EMAIL);
  await page.fill('#password', PARENT_PASSWORD);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email/, { timeout: 20000 });
}

test.describe('Parent flow', () => {
  test('register parent QA if not exists', async ({ page }) => {
    await page.goto(`${BASE}/login`);
    await page.waitForLoadState('networkidle');
    await page.fill('#email', PARENT_EMAIL);
    await page.fill('#password', PARENT_PASSWORD);
    await page.locator('form button').first().click();

    try {
      await page.waitForURL(/dashboard|verify-email/, { timeout: 8000 });
      console.log('Parent already registered, skipping registration');
      return;
    } catch {
      console.log('Parent not registered — registering now');
    }

    await page.goto(`${BASE}/register`);
    await page.waitForLoadState('networkidle');
    const parentBtn = page.locator('button', { hasText: 'Soy padre' });
    if (await parentBtn.isVisible()) {
      await parentBtn.click();
    }
    await page.fill('input[name="name"]', 'Padre QA');
    await page.fill('input[type="email"]', PARENT_EMAIL);
    await page.fill('input[name="password"]', PARENT_PASSWORD);
    const confirmField = page.locator('input[name="password_confirmation"]');
    if (await confirmField.isVisible()) {
      await confirmField.fill(PARENT_PASSWORD);
    }
    await page.locator('form button[type="submit"], form button').last().click();
    await page.waitForURL(/dashboard|verify-email/, { timeout: 15000 });
    await page.screenshot({ path: 'reports/parent-registered.png' });
    console.log('Parent QA registered');
  });

  test('parent can see dashboard', async ({ page }) => {
    await loginParent(page);
    const url = page.url();
    console.log('Parent landed at:', url);
    await page.screenshot({ path: 'reports/parent-dashboard.png' });
    expect(url).not.toContain('/login');
  });

  test('parent can see students page', async ({ page }) => {
    await loginParent(page);
    await page.goto(`${BASE}/students`);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: 'reports/parent-students.png' });
    const title = await page.locator('title').textContent().catch(() => '');
    expect(title).not.toContain('Whoops');
    expect(page.url()).not.toContain('/login');
    console.log('Parent students page loaded OK');
  });

  test('parent can see my-classes page', async ({ page }) => {
    await loginParent(page);
    await page.goto(`${BASE}/my-classes`);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: 'reports/parent-my-classes.png' });
    const title = await page.locator('title').textContent().catch(() => '');
    expect(title).not.toContain('Whoops');
    expect(page.url()).not.toContain('/login');
    console.log('Parent my-classes page loaded OK');
  });
});
