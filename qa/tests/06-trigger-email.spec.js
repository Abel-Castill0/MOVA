// @ts-check
// Triggers a NewClassRequestNotification to verify anti-spam email subjects
import { test, expect } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

dotenv.config({ path: path.resolve(import.meta.dirname, '../.env.qa') });

const BASE    = process.env.BASE_URL;
const EMAIL   = process.env.QA_PARENT_EMAIL;
const PASS    = process.env.QA_PARENT_PASSWORD;

test('parent submits class request to trigger NewClassRequestNotification email', async ({ page }) => {
  // Login as parent
  await page.goto(`${BASE}/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('#email', EMAIL);
  await page.fill('#password', PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email/, { timeout: 20000 });
  console.log('Logged in as parent, URL:', page.url());

  // Go to marketplace to pick an offer
  await page.goto(`${BASE}/marketplace`);
  await page.waitForLoadState('networkidle');

  // Click first "Solicitar clase" button
  const requestBtn = page.locator('a', { hasText: 'Solicitar clase' }).first();
  const btnCount = await requestBtn.count();
  console.log('Request buttons found:', btnCount);

  if (btnCount === 0) {
    console.log('No class offers visible — check marketplace');
    test.skip(true, 'No offers in marketplace');
    return;
  }

  await requestBtn.click();
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/class-request-form.png' });
  console.log('Class request form URL:', page.url());

  // Wait to be on the create form
  await page.waitForURL(/class-requests\/create/, { timeout: 10000 });
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/class-request-form-before.png' });

  // Select student by value (student id=1, the QA parent's child)
  const studentSelect = page.locator('select').first();
  await studentSelect.selectOption({ value: '1' });
  await page.waitForTimeout(300);
  console.log('Student selected, value:', await studentSelect.inputValue());

  // Fill help_needed textarea
  const helpField = page.locator('textarea').first();
  await helpField.fill('QA test - verificando emails anti-spam en MOVA. Necesito apoyo con álgebra.');
  await page.waitForTimeout(200);
  console.log('help_needed filled');

  await page.screenshot({ path: 'reports/class-request-filled.png' });

  // Listen for the POST request to confirm submission
  const [response] = await Promise.all([
    page.waitForResponse(resp => resp.url().includes('/class-requests') && resp.request().method() === 'POST', { timeout: 15000 }),
    page.locator('button[type="submit"]').click(),
  ]);
  console.log('Form POST response status:', response.status());
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/class-request-result.png' });

  const url = page.url();
  const title = await page.locator('title').textContent().catch(() => '');
  console.log('After submit URL:', url);
  console.log('After submit title:', title);
  expect(title).not.toContain('Whoops');

  console.log('Class request submitted — NewClassRequestNotification should fire');
  console.log('Check mova-queue logs and Gmail inbox in ~30s');
});
