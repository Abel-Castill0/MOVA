// @ts-check
import { test, expect } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

dotenv.config({ path: path.resolve(import.meta.dirname, '../.env.qa') });

const BASE = process.env.BASE_URL;
const TEACHER_EMAIL = process.env.QA_TEACHER_EMAIL;
const TEACHER_PASSWORD = process.env.QA_TEACHER_PASSWORD;

async function loginTeacher(page) {
  await page.goto(`${BASE}/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('#email', TEACHER_EMAIL);
  await page.fill('#password', TEACHER_PASSWORD);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email|teacher/, { timeout: 20000 });
}

test.describe('Teacher flow', () => {
  test('register teacher QA if not exists', async ({ page }) => {
    // Try login first
    await page.goto(`${BASE}/login`);
    await page.waitForLoadState('networkidle');
    await page.fill('#email', TEACHER_EMAIL);
    await page.fill('#password', TEACHER_PASSWORD);
    await page.locator('form button').first().click();

    // If redirected to dashboard, already registered
    try {
      await page.waitForURL(/dashboard|verify-email|teacher\/setup/, { timeout: 8000 });
      console.log('Teacher already registered, skipping registration');
      return;
    } catch {
      console.log('Login failed — registering teacher QA');
    }

    // Register
    await page.goto(`${BASE}/register`);
    await page.waitForLoadState('networkidle');
    // Select teacher role
    const teacherBtn = page.locator('button', { hasText: 'Soy profesor' });
    if (await teacherBtn.isVisible()) {
      await teacherBtn.click();
    }
    await page.fill('input[name="name"]', 'Profesor QA');
    await page.fill('input[type="email"]', TEACHER_EMAIL);
    // Fill phone if field exists
    const phoneField = page.locator('input[type="tel"], input[name="phone"]');
    if (await phoneField.isVisible()) {
      await phoneField.fill('987654321');
    }
    await page.fill('input[name="password"]', TEACHER_PASSWORD);
    const confirmField = page.locator('input[name="password_confirmation"]');
    if (await confirmField.isVisible()) {
      await confirmField.fill(TEACHER_PASSWORD);
    }
    await page.locator('form button[type="submit"], form button').last().click();
    await page.waitForURL(/dashboard|verify-email|setup/, { timeout: 15000 });
    await page.screenshot({ path: 'reports/teacher-registered.png' });
    console.log('Teacher QA registered successfully');
  });

  test('teacher can see dashboard', async ({ page }) => {
    await loginTeacher(page);
    const url = page.url();
    console.log('Teacher landed at:', url);
    await page.screenshot({ path: 'reports/teacher-dashboard.png' });
    expect(url).not.toContain('/login');
  });

  test('teacher can see requests page', async ({ page }) => {
    await loginTeacher(page);
    await page.goto(`${BASE}/teacher/requests`);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: 'reports/teacher-requests.png' });
    const title = await page.locator('title').textContent().catch(() => '');
    expect(title).not.toContain('Whoops');
    expect(page.url()).not.toContain('/login');
    console.log('Teacher requests page loaded OK');
  });

  test('teacher can see class-offers page', async ({ page }) => {
    await loginTeacher(page);
    await page.goto(`${BASE}/class-offers`);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: 'reports/teacher-class-offers.png' });
    const title = await page.locator('title').textContent().catch(() => '');
    expect(title).not.toContain('Whoops');
    expect(page.url()).not.toContain('/login');
    console.log('Teacher class-offers page loaded OK');
  });
});
