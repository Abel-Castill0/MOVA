import { test, expect } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

dotenv.config({ path: path.resolve(import.meta.dirname, '.env.qa') });

const BASE = process.env.BASE_URL;
const TEACHER_EMAIL = process.env.QA_TEACHER_EMAIL;
const TEACHER_PASS = process.env.QA_TEACHER_PASSWORD;

test('teacher: complete class 2 and create report', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.fill('#email', TEACHER_EMAIL);
  await page.fill('#password', TEACHER_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email|teacher/, { timeout: 20000 });

  // Go directly to complete class 2
  const completeResp = await page.request.post(`${BASE}/lessons/2/complete`);
  console.log('Complete status:', completeResp.status());

  // Now go to create report for lesson 2
  await page.goto(`${BASE}/lessons/2/report/create`);
  await page.waitForLoadState('networkidle');
  const url = page.url();
  console.log('URL:', url);
  await page.screenshot({ path: 'reports/phase2-report2-form.png' });

  const body = await page.locator('body').innerText();
  if (body.includes('Tema trabajado')) {
    console.log('Form loaded OK');
    await page.locator('textarea').nth(0).fill('Números decimales: suma y resta');
    await page.locator('textarea').nth(1).fill('Excelente participación, comprendió rápidamente el concepto.');
    await page.locator('textarea').nth(2).fill('Alguna dificultad con los decimales negativos.');
    await page.locator('textarea').nth(3).fill('Ejercicios del libro páginas 50-52.');
    await page.locator('textarea').nth(4).fill('Continuar con la práctica diaria, va muy bien.');
    await page.locator('textarea').nth(5).fill('La próxima clase: multiplicación de decimales.');

    const submitBtn = page.locator('button[type="submit"]');
    await submitBtn.click();
    await page.waitForLoadState('networkidle');
    const urlAfter = page.url();
    const bodyAfter = await page.locator('body').innerText();
    console.log('URL after submit:', urlAfter);
    console.log('Reporte enviado?', bodyAfter.includes('Reporte enviado') || urlAfter.includes('/report'));
    await page.screenshot({ path: 'reports/phase2-report2-submitted.png' });
  } else {
    console.log('No form found - body:', body.slice(0, 200));
  }
});
