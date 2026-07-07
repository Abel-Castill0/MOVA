// @ts-check
import { chromium } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
dotenv.config({ path: path.join(__dirname, '.env.qa') });

const BASE = process.env.BASE_URL;
const ADMIN_EMAIL = process.env.QA_ADMIN_EMAIL;
const ADMIN_PASSWORD = process.env.QA_ADMIN_PASSWORD;
const PARENT_EMAIL = process.env.QA_PARENT_EMAIL;
const PARENT_PASSWORD = process.env.QA_PARENT_PASSWORD;
const TEACHER_EMAIL = process.env.QA_TEACHER_EMAIL;
const TEACHER_PASSWORD = process.env.QA_TEACHER_PASSWORD;

async function saveAuth(browser, email, password, file) {
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto(`${BASE}/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.locator('form button').first().click();
  try {
    await page.waitForURL(/dashboard|verify-email|admin|teacher/, { timeout: 20000 });
    await context.storageState({ path: file });
    console.log(`Auth saved for ${email} → ${file}`);
  } catch (e) {
    console.warn(`Auth failed for ${email}: ${e.message}`);
  }
  await context.close();
}

async function globalSetup() {
  const browser = await chromium.launch({ channel: 'chrome' });
  await saveAuth(browser, ADMIN_EMAIL, ADMIN_PASSWORD, 'auth/admin.json');
  await browser.close();
}

export default globalSetup;
