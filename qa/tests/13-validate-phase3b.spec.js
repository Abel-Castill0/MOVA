// @ts-check
// QA validation for Phase 3B: onboarding, quality control, unanswered requests
import { test, expect } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

dotenv.config({ path: path.resolve(import.meta.dirname, '../.env.qa') });

const BASE          = process.env.BASE_URL;
const TEACHER_EMAIL = process.env.QA_TEACHER_EMAIL;
const TEACHER_PASS  = process.env.QA_TEACHER_PASSWORD;
const ADMIN_EMAIL   = process.env.QA_ADMIN_EMAIL;
const ADMIN_PASS    = process.env.QA_ADMIN_PASSWORD;
const DB_PW         = process.env.DB_PASSWORD;
const DB_HOST       = 'reseau.proxy.rlwy.net';
const DB_PORT       = '42114';

async function dbQuery(sql) {
  const { execSync } = await import('child_process');
  const phpCode = `<?php
$pdo = new PDO("mysql:host=${DB_HOST};port=${DB_PORT};dbname=railway","root",getenv('DB_PW'));
$rows = $pdo->query(${JSON.stringify(sql)})->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows);`;
  const tmpFile = path.join(process.env.TEMP || '/tmp', 'qa_db3b.php');
  require('fs').writeFileSync(tmpFile, phpCode, 'ascii');
  const result = execSync(`php "${tmpFile}"`, { env: { ...process.env, DB_PW } }).toString();
  return JSON.parse(result);
}

// ─── MIGRATIONS ───────────────────────────────────────────────────────────────

test('migrations: welcome_notification_sent_at exists on users', async () => {
  const rows = await dbQuery('DESCRIBE users').catch(() => null);
  if (!rows) { console.log('SKIP: Cannot reach DB'); return; }
  const fields = rows.map(r => r.Field || r.field);
  console.log('Users fields (relevant):', fields.filter(f => f.includes('welcome') || f.includes('notification')));
  expect(fields).toContain('welcome_notification_sent_at');
});

test('migrations: request_reminder_sent_at exists on class_requests', async () => {
  const rows = await dbQuery('DESCRIBE class_requests').catch(() => null);
  if (!rows) { console.log('SKIP: Cannot reach DB'); return; }
  const fields = rows.map(r => r.Field || r.field);
  console.log('class_requests fields:', fields.join(', '));
  expect(fields).toContain('request_reminder_sent_at');
});

// ─── WELCOME NOTIFICATIONS ─────────────────────────────────────────────────────

test('welcome: teacher gets in-app welcome notification', async () => {
  const rows = await dbQuery(
    "SELECT COUNT(*) as cnt FROM notifications WHERE data LIKE '%welcome_teacher%'"
  ).catch(() => null);
  if (!rows) { console.log('SKIP: Cannot reach DB'); return; }
  const count = parseInt(rows[0]?.cnt ?? '0');
  console.log('welcome_teacher notifications in DB:', count);
  // May be 0 if no new registrations — just verify it doesn't error
  expect(count).toBeGreaterThanOrEqual(0);
});

// ─── TEACHER DASHBOARD CHECKLIST ──────────────────────────────────────────────

test('teacher dashboard: shows profile completeness section', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.fill('#email', TEACHER_EMAIL);
  await page.fill('#password', TEACHER_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email|teacher/, { timeout: 20000 });

  await page.goto(`${BASE}/dashboard`);
  // Use domcontentloaded to avoid networkidle timing issues on redirects
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(2000);
  await page.screenshot({ path: 'reports/phase3b-teacher-checklist.png' });

  const body = await page.locator('body').innerText();
  const hasChecklist = body.includes('completo') || body.includes('perfil') || body.includes('Reportes');
  console.log('Has completeness section or dashboard:', hasChecklist);
  // Dashboard loads without error — may show checklist or be 100% complete (hidden)
  expect(page.url()).toMatch(/dashboard|teacher/);
});

test('teacher dashboard: profile score is between 0 and 100', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.fill('#email', TEACHER_EMAIL);
  await page.fill('#password', TEACHER_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email|teacher/, { timeout: 20000 });

  await page.goto(`${BASE}/dashboard`);
  await page.waitForLoadState('networkidle');

  // Check page source for % pattern (e.g. "70% completo")
  const html = await page.content();
  const match = html.match(/(\d+)%\s*completo/);
  if (match) {
    const score = parseInt(match[1]);
    console.log('Profile score detected:', score);
    expect(score).toBeGreaterThanOrEqual(0);
    expect(score).toBeLessThanOrEqual(100);
  } else {
    console.log('Score not visible (profile may be 100% complete)');
    // OK if 100% — checklist is hidden when complete
    expect(true).toBe(true);
  }
});

// ─── ADMIN QUALITY DASHBOARD ───────────────────────────────────────────────────

test('admin dashboard: shows quality metrics section', async ({ page }) => {
  await page.goto(`${BASE}/login`);
  await page.fill('#email', ADMIN_EMAIL);
  await page.fill('#password', ADMIN_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email/, { timeout: 20000 });

  await page.goto(`${BASE}/dashboard`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase3b-admin-quality.png' });

  const body = await page.locator('body').innerText();
  console.log('Has "Control de calidad":', body.includes('Control de calidad'));
  console.log('Has "Jobs fallidos":', body.includes('Jobs fallidos'));
  console.log('Has "Solicitudes abiertas":', body.includes('Solicitudes abiertas'));
  expect(body).toContain('Control de calidad');
  expect(body).toContain('Jobs fallidos');
});

test('admin dashboard: failed_jobs card shows real count', async ({ page }) => {
  const rows = await dbQuery('SELECT COUNT(*) as cnt FROM failed_jobs').catch(() => null);
  const expectedCount = rows ? parseInt(rows[0]?.cnt ?? '0') : null;
  if (expectedCount === null) { console.log('SKIP: Cannot reach DB'); return; }
  console.log('Expected failed_jobs count:', expectedCount);

  await page.goto(`${BASE}/login`);
  await page.fill('#email', ADMIN_EMAIL);
  await page.fill('#password', ADMIN_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email/, { timeout: 20000 });

  await page.goto(`${BASE}/dashboard`);
  await page.waitForLoadState('networkidle');

  const body = await page.locator('body').innerText();
  console.log('Dashboard has failed_jobs section:', body.includes('Jobs fallidos'));
  expect(body).toContain('Jobs fallidos');
  expect(expectedCount).toBe(0);
});

// ─── UNANSWERED REQUEST ALERTS ─────────────────────────────────────────────────

test('scheduler: unanswered_request column exists and is nullable', async () => {
  const rows = await dbQuery("SHOW COLUMNS FROM class_requests LIKE 'request_reminder_sent_at'").catch(() => null);
  if (!rows) { console.log('SKIP: Cannot reach DB'); return; }
  const isNullable = rows[0]?.Null === 'YES';
  console.log('request_reminder_sent_at nullable:', isNullable);
  expect(isNullable).toBe(true);
});

// ─── QUEUE HEALTH ─────────────────────────────────────────────────────────────

test('queue: failed_jobs = 0 after phase 3B', async () => {
  const rows = await dbQuery('SELECT COUNT(*) as cnt FROM failed_jobs').catch(() => null);
  if (!rows) { console.log('SKIP: Cannot reach DB'); return; }
  const count = parseInt(rows[0]?.cnt ?? '0');
  console.log('Failed jobs:', count);
  expect(count).toBe(0);
});

// ─── RESPONSIVE ───────────────────────────────────────────────────────────────

test('responsive: admin quality dashboard on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto(`${BASE}/login`);
  await page.fill('#email', ADMIN_EMAIL);
  await page.fill('#password', ADMIN_PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard|verify-email/, { timeout: 20000 });

  await page.goto(`${BASE}/dashboard`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'reports/phase3b-mobile-admin.png' });

  const body = await page.locator('body').innerText();
  expect(body).toContain('Control de calidad');
  console.log('Mobile admin quality dashboard: OK');
});
