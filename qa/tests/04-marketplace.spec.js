// @ts-check
import { test, expect } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

dotenv.config({ path: path.resolve(import.meta.dirname, '../.env.qa') });

const BASE = process.env.BASE_URL;
const PARENT_EMAIL = process.env.QA_PARENT_EMAIL;
const PARENT_PASSWORD = process.env.QA_PARENT_PASSWORD;

test.describe('Marketplace', () => {
  test('marketplace is reachable (public or redirects to login)', async ({ request }) => {
    const res = await request.get(`${BASE}/marketplace`, { maxRedirects: 0 });
    const status = res.status();
    console.log('Marketplace status:', status);
    // Either 200 (public) or 302 to login (auth required)
    expect([200, 302]).toContain(status);
  });

  test('marketplace visible while logged in as parent', async ({ page }) => {
    await page.goto(`${BASE}/login`);
    await page.waitForLoadState('networkidle');
    await page.fill('#email', PARENT_EMAIL);
    await page.fill('#password', PARENT_PASSWORD);
    await page.locator('form button').first().click();
    await page.waitForURL(/dashboard|verify-email/, { timeout: 20000 });

    await page.goto(`${BASE}/marketplace`);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: 'reports/marketplace-loggedin.png' });

    // Check real page title (not minified JS which contains "500")
    const title = await page.locator('title').textContent().catch(() => '');
    expect(title).not.toContain('Whoops');
    expect(page.url()).not.toContain('/login');
    console.log('Marketplace loaded for logged-in parent | title:', title);

    // Check for teacher cards or empty state using visible text
    const bodyText = await page.locator('body').innerText().catch(() => '');
    const hasTeachers = bodyText.toLowerCase().includes('profesor') || bodyText.toLowerCase().includes('teacher') || bodyText.includes('oferta');
    const hasEmptyState = bodyText.includes('no hay') || bodyText.includes('No hay') || bodyText.includes('Aún') || bodyText.includes('disponible');
    console.log('Has teacher content:', hasTeachers, '| Has empty state:', hasEmptyState);
  });

  test('class-requests create page accessible', async ({ page }) => {
    await page.goto(`${BASE}/login`);
    await page.waitForLoadState('networkidle');
    await page.fill('#email', PARENT_EMAIL);
    await page.fill('#password', PARENT_PASSWORD);
    await page.locator('form button').first().click();
    await page.waitForURL(/dashboard|verify-email/, { timeout: 20000 });

    await page.goto(`${BASE}/class-requests/create`);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: 'reports/class-request-create.png' });

    const title = await page.locator('title').textContent().catch(() => '');
    expect(title).not.toContain('Whoops');
    expect(page.url()).not.toContain('/login');
    console.log('Class request create page loaded | title:', title);
  });
});
