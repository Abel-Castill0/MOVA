// @ts-check
import { test, expect } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

dotenv.config({ path: path.resolve(import.meta.dirname, '../.env.qa') });

const BASE = process.env.BASE_URL;
const ADMIN_EMAIL = process.env.QA_ADMIN_EMAIL;
const ADMIN_PASSWORD = process.env.QA_ADMIN_PASSWORD;
const TEACHER_EMAIL = process.env.QA_TEACHER_EMAIL;

// This test checks if admin can verify a pending teacher and triggers TeacherVerifiedNotification
// which sends a real Gmail API email. Run this to confirm email delivery.
test.describe('Admin verify teacher (email trigger)', () => {
  test('admin logs in and sees pending teachers panel', async ({ page }) => {
    await page.goto(`${BASE}/login`);
    await page.waitForLoadState('networkidle');
    await page.fill('#email', ADMIN_EMAIL);
    await page.fill('#password', ADMIN_PASSWORD);
    await page.locator('form button').first().click();
    await page.waitForURL(/dashboard/, { timeout: 20000 });

    await page.goto(`${BASE}/admin/pending-teachers`);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: 'reports/admin-pending-teachers-before.png' });

    const content = await page.content();
    expect(content).not.toContain('Whoops!');

    // Look for verify button (form with POST to admin/teachers/{id}/verify)
    const verifyForms = await page.locator('form[action*="verify"]').count();
    const verifyButtons = await page.locator('button', { hasText: /verif/i }).count();
    console.log('Verify forms found:', verifyForms, '| Verify buttons:', verifyButtons);

    if (verifyForms > 0 || verifyButtons > 0) {
      console.log('PENDING TEACHERS FOUND — ready to trigger email by clicking verify');
    } else {
      console.log('No pending teachers at this time');
    }
  });

  test('admin verifies first pending teacher (triggers email)', async ({ page }) => {
    await page.goto(`${BASE}/login`);
    await page.waitForLoadState('networkidle');
    await page.fill('#email', ADMIN_EMAIL);
    await page.fill('#password', ADMIN_PASSWORD);
    await page.locator('form button').first().click();
    await page.waitForURL(/dashboard/, { timeout: 20000 });

    await page.goto(`${BASE}/admin/pending-teachers`);
    await page.waitForLoadState('networkidle');

    // Find and click first verify button
    const verifyBtn = page.locator('button', { hasText: /verif/i }).first();
    const verifyBtnCount = await verifyBtn.count();

    if (verifyBtnCount === 0) {
      console.log('SKIP: No pending teachers to verify');
      test.skip(true, 'No pending teachers');
      return;
    }

    console.log('Clicking verify for first pending teacher — this will trigger Gmail API email');
    await verifyBtn.click();
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: 'reports/admin-verify-result.png' });

    const content = await page.content();
    expect(content).not.toContain('500');
    console.log('Verify action completed — check mova-queue logs for [Gmail] entries');
    console.log('Expected email subject: "Tu perfil docente fue verificado en MOVA"');
    console.log('Expected recipient:', TEACHER_EMAIL);
  });
});
