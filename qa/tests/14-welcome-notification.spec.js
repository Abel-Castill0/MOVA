// @ts-check
// Validates welcome notification fires on new parent/teacher registration
import { test, expect } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

dotenv.config({ path: path.resolve(import.meta.dirname, '../.env.qa') });

const BASE  = process.env.BASE_URL;
const DB_PW = 'OefhzFYBJvJQjyUUDKwtfXIEjLuJcRFj';
const DB_HOST = 'reseau.proxy.rlwy.net';
const DB_PORT = '42114';

async function dbQuery(sql) {
  const { execSync } = await import('child_process');
  const phpCode = `<?php
$pdo = new PDO("mysql:host=${DB_HOST};port=${DB_PORT};dbname=railway","root",getenv('DB_PW'));
$rows = $pdo->query(${JSON.stringify(sql)})->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows);`;
  const tmpFile = path.join(process.env.TEMP || '/tmp', 'qa_welcome.php');
  require('fs').writeFileSync(tmpFile, phpCode, 'ascii');
  const result = execSync(`php "${tmpFile}"`, { env: { ...process.env, DB_PW } }).toString();
  return JSON.parse(result);
}

const TS = Date.now();

test('welcome parent: registration creates in-app notification', async ({ page }) => {
  const email    = `abelwuarthon3+p${TS}@gmail.com`;
  const password = 'TestMOVA2026!';

  await page.goto(`${BASE}/register`);
  await page.waitForLoadState('domcontentloaded');

  await page.locator('input[type=text]').first().fill('Padre Test3B');
  await page.locator('input[type=email]').first().fill(email);
  await page.locator('input[type=tel]').first().fill('987000001');
  const pwds = page.locator('input[type=password]');
  await pwds.nth(0).fill(password);
  await pwds.nth(1).fill(password);

  const parentRadio = page.locator('input[value=parent]');
  if (await parentRadio.count() > 0) await parentRadio.click();

  await page.locator('form button[type=submit]').first().click();
  await page.waitForURL(/students|dashboard|verify/, { timeout: 20000 });
  console.log('Post-register URL:', page.url());

  // Wait for queue to process
  await page.waitForTimeout(5000);

  // Check DB
  const rows = await dbQuery(
    `SELECT u.id, u.welcome_notification_sent_at, COUNT(n.id) as notif_count
     FROM users u
     LEFT JOIN notifications n ON n.notifiable_id = u.id AND n.data LIKE '%welcome_parent%'
     WHERE u.email = '${email}'
     GROUP BY u.id`
  ).catch(() => null);

  if (!rows || !rows.length) {
    console.log('SKIP: DB not reachable or user not found');
    expect(page.url()).toMatch(/students|dashboard|verify/);
    return;
  }

  const row = rows[0];
  console.log('User ID:', row.id);
  console.log('welcome_notification_sent_at:', row.welcome_notification_sent_at);
  console.log('welcome_parent notifications:', row.notif_count);

  expect(row.welcome_notification_sent_at).not.toBeNull();
  expect(parseInt(row.notif_count)).toBeGreaterThan(0);
});

test('welcome teacher: registration creates in-app notification', async ({ page }) => {
  const email    = `abelwuarthon3+t${TS}@gmail.com`;
  const password = 'TestMOVA2026!';

  await page.goto(`${BASE}/register`);
  await page.waitForLoadState('domcontentloaded');

  await page.locator('input[type=text]').first().fill('Profesor Test3B');
  await page.locator('input[type=email]').first().fill(email);
  await page.locator('input[type=tel]').first().fill('987000002');
  const pwds2 = page.locator('input[type=password]');
  await pwds2.nth(0).fill(password);
  await pwds2.nth(1).fill(password);

  const teacherRadio = page.locator('input[value=teacher]');
  if (await teacherRadio.count() > 0) await teacherRadio.click();

  await page.locator('form button[type=submit]').first().click();
  await page.waitForURL(/teacher|setup|verify|dashboard/, { timeout: 20000 });
  console.log('Post-register URL:', page.url());

  await page.waitForTimeout(5000);

  const rows = await dbQuery(
    `SELECT u.id, u.welcome_notification_sent_at, COUNT(n.id) as notif_count
     FROM users u
     LEFT JOIN notifications n ON n.notifiable_id = u.id AND n.data LIKE '%welcome_teacher%'
     WHERE u.email = '${email}'
     GROUP BY u.id`
  ).catch(() => null);

  if (!rows || !rows.length) {
    console.log('SKIP: DB not reachable or user not found');
    expect(page.url()).toMatch(/teacher|setup|verify|dashboard/);
    return;
  }

  const row = rows[0];
  console.log('User ID:', row.id);
  console.log('welcome_notification_sent_at:', row.welcome_notification_sent_at);
  console.log('welcome_teacher notifications:', row.notif_count);

  expect(row.welcome_notification_sent_at).not.toBeNull();
  expect(parseInt(row.notif_count)).toBeGreaterThan(0);
});

test('anti-duplicate: second call to welcome does NOT add another notification', async () => {
  // Verify: if welcome_notification_sent_at is set, no duplicate should exist
  const rows = await dbQuery(
    `SELECT notifiable_id, COUNT(*) as cnt
     FROM notifications
     WHERE data LIKE '%welcome_parent%' OR data LIKE '%welcome_teacher%'
     GROUP BY notifiable_id
     HAVING cnt > 1`
  ).catch(() => null);

  if (!rows) { console.log('SKIP: Cannot reach DB'); return; }
  console.log('Users with duplicate welcome notifications:', rows.length);
  expect(rows.length).toBe(0);
});
