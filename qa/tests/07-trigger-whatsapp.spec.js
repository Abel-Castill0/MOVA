// @ts-check
// Teacher accepts a class request → triggers ClassConfirmedNotification → WhatsApp to parent
import { test } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

dotenv.config({ path: path.resolve(import.meta.dirname, '../.env.qa') });

const BASE  = process.env.BASE_URL;
const EMAIL = process.env.QA_TEACHER_EMAIL;
const PASS  = process.env.QA_TEACHER_PASSWORD;

test('teacher accepts class request to trigger WhatsApp notification', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('#email', EMAIL);
  await page.fill('#password', PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email|teacher/, { timeout: 20000 });
  console.log('Logged in as teacher, URL:', page.url());

  await page.goto(`${BASE}/teacher/requests`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/teacher-requests-before.png' });

  const bodyText = await page.locator('body').innerText().catch(() => '');
  console.log('Has pending requests:', bodyText.toLowerCase().includes('aceptar'));

  const acceptLink = page.locator('a', { hasText: /aceptar/i }).first();
  const acceptCount = await acceptLink.count();
  console.log('Accept links found:', acceptCount);

  if (acceptCount === 0) {
    console.log('No accept buttons found — no pending class requests');
    return;
  }

  const href = await acceptLink.getAttribute('href');
  console.log('Accept href:', href);

  await page.goto(href);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/teacher-accept-form.png' });
  console.log('Accept page URL:', page.url());

  // Build a datetime value that's tomorrow at 10:00 local (not UTC)
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  tomorrow.setHours(10, 0, 0, 0);
  // Format as YYYY-MM-DDTHH:MM using local time (not toISOString which gives UTC)
  const pad = (n) => String(n).padStart(2, '0');
  const dtLocal = `${tomorrow.getFullYear()}-${pad(tomorrow.getMonth() + 1)}-${pad(tomorrow.getDate())}T${pad(tomorrow.getHours())}:${pad(tomorrow.getMinutes())}`;
  console.log('Date to fill:', dtLocal);

  const dateInput = page.locator('input[type="datetime-local"]').first();
  await dateInput.waitFor({ state: 'visible', timeout: 5000 });

  // Fill and explicitly dispatch input + change events so Vue v-model picks up the value
  await dateInput.fill(dtLocal);
  await dateInput.evaluate((el, val) => {
    el.value = val;
    el.dispatchEvent(new Event('input',  { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }, dtLocal);
  await page.waitForTimeout(400);

  // Verify the value was set
  const filledVal = await dateInput.inputValue();
  console.log('Input value after fill:', filledVal);

  // Duration defaults to 60 via the form; buttons are type="button", no input needed

  await page.screenshot({ path: 'reports/teacher-accept-filled.png' });

  // Check if submit button is enabled
  const submitBtn = page.locator('button[type="submit"]').first();
  const isDisabled = await submitBtn.isDisabled();
  console.log('Submit button disabled:', isDisabled);

  if (isDisabled) {
    // Force start_time into the Inertia form via page evaluate
    await page.evaluate((val) => {
      // Find the Vue component instance and update its form directly
      const inputs = document.querySelectorAll('input[type="datetime-local"]');
      inputs.forEach(input => {
        input.value = val;
        input.dispatchEvent(new Event('input',  { bubbles: true, composed: true }));
        input.dispatchEvent(new Event('change', { bubbles: true, composed: true }));
      });
    }, dtLocal);
    await page.waitForTimeout(500);
    const isDisabled2 = await submitBtn.isDisabled();
    console.log('Submit button disabled after force-fill:', isDisabled2);
  }

  await page.screenshot({ path: 'reports/teacher-accept-before-submit.png' });

  // Submit and wait for the Inertia fetch to /lessons
  const [response] = await Promise.all([
    page.waitForResponse(
      resp => resp.url().includes('/lessons') && resp.request().method() === 'POST',
      { timeout: 20000 }
    ).catch(() => null),
    submitBtn.click({ force: true }),
  ]);

  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/teacher-accept-result.png' });
  console.log('After accept URL:', page.url());
  console.log('POST /lessons response status:', response ? response.status() : 'no POST captured');

  if (response) {
    try {
      const body = await response.text();
      const json = JSON.parse(body);
      console.log('Inertia redirect to:', json?.url ?? '(no url in body)');
    } catch (_) {}
  }

  console.log('ClassConfirmedNotification should fire → email + WhatsApp to parent');
});
