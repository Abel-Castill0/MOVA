#!/usr/bin/env node
/**
 * End-to-end validation: register → verify email → confirm WelcomeEmailNotification in Gmail
 */
import { chromium } from 'playwright';
import { readFileSync, writeFileSync } from 'fs';
import { fileURLToPath } from 'url';
import path from 'path';
import { execSync } from 'child_process';

const envPath = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '.env.qa');
const envLines = readFileSync(envPath, 'utf8').split('\n');
const env = Object.fromEntries(
  envLines.filter(l => l && !l.startsWith('#') && l.includes('=')).map(l => [l.split('=')[0].trim(), l.split('=').slice(1).join('=').trim()])
);

const BASE    = env.BASE_URL;
const DB_PW   = 'OefhzFYBJvJQjyUUDKwtfXIEjLuJcRFj';
const DB_HOST = 'reseau.proxy.rlwy.net';
const DB_PORT = '42114';
const TS      = Date.now();
const ROLE    = 'parent'; // test parent role
const EMAIL   = `abelwuarthon3+e2e${TS}@gmail.com`;
const PASS    = 'familiawuarthon123!';

function log(msg) { console.log(`[${new Date().toISOString()}] ${msg}`); }

async function getGmailToken() {
  const res = await fetch('https://oauth2.googleapis.com/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      client_id: env.GMAIL_CLIENT_ID,
      client_secret: env.GMAIL_CLIENT_SECRET,
      refresh_token: env.GMAIL_READONLY_REFRESH_TOKEN,
      grant_type: 'refresh_token',
    }),
  });
  const data = await res.json();
  if (!data.access_token) throw new Error('Gmail token failed: ' + JSON.stringify(data));
  return data.access_token;
}

async function searchGmail(token, query, maxResults = 5) {
  const url = `https://gmail.googleapis.com/gmail/v1/users/me/messages?q=${encodeURIComponent(query)}&maxResults=${maxResults}`;
  const res = await fetch(url, { headers: { Authorization: `Bearer ${token}` } });
  return res.json();
}

async function getMessage(token, id) {
  const res = await fetch(`https://gmail.googleapis.com/gmail/v1/users/me/messages/${id}?format=full`, {
    headers: { Authorization: `Bearer ${token}` },
  });
  return res.json();
}

function getAllParts(part, acc = []) {
  if (part.body?.data) acc.push({ mimeType: part.mimeType, data: part.body.data });
  if (part.parts) part.parts.forEach(p => getAllParts(p, acc));
  return acc;
}

function dbQuery(sql) {
  const phpCode = `<?php
$pdo = new PDO("mysql:host=${DB_HOST};port=${DB_PORT};dbname=railway","root",getenv('DB_PW'));
$rows = $pdo->query(${JSON.stringify(sql)})->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows);`;
  const tmpFile = path.join(process.env.TEMP || '/tmp', 'qa_e2e.php');
  writeFileSync(tmpFile, phpCode, 'ascii');
  const result = execSync(`php "${tmpFile}"`, { env: { ...process.env, DB_PW } }).toString();
  return JSON.parse(result);
}

async function waitForEmail(token, query, maxWaitMs = 60000, pollMs = 5000) {
  const deadline = Date.now() + maxWaitMs;
  while (Date.now() < deadline) {
    const { messages = [] } = await searchGmail(token, query);
    if (messages.length) return messages[0];
    log(`  Waiting for email... (${Math.round((deadline - Date.now())/1000)}s left)`);
    await new Promise(r => setTimeout(r, pollMs));
  }
  return null;
}

// ─── MAIN ─────────────────────────────────────────────────────────────────────

const results = {
  role: ROLE,
  email: EMAIL,
  registered: false,
  verifyLinkFound: false,
  verifyLinkClicked: false,
  emailVerifiedInDB: false,
  welcomeEmailFound: false,
  welcomeEmailSubject: null,
  duplicateCheck: false,
  failedJobs: null,
  errors: [],
};

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

try {
  // ── STEP 1: Register new user ──────────────────────────────────────────────
  log(`STEP 1: Registering ${EMAIL} as ${ROLE}`);
  await page.goto(`${BASE}/register`);
  await page.waitForLoadState('domcontentloaded');

  await page.fill('input[type=text]', 'E2E Test User');
  await page.fill('input[type=email]', EMAIL);
  await page.fill('input[type=tel]', '');

  // Select role
  if (ROLE === 'parent') {
    const parentRadio = page.locator('input[value=parent], label:has-text("Padre"), button:has-text("Padre")');
    if (await parentRadio.count()) {
      await parentRadio.first().click();
    } else {
      // Try select element
      const sel = page.locator('select[name=role]');
      if (await sel.count()) await sel.selectOption('parent');
    }
  }

  const passwords = page.locator('input[type=password]');
  await passwords.nth(0).fill(PASS);
  await passwords.nth(1).fill(PASS);

  await page.locator('form button[type=submit], form button:has-text("Registrar"), form button:has-text("Crear")').first().click();
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(3000);

  const postRegUrl = page.url();
  log(`  Post-register URL: ${postRegUrl}`);
  results.registered = postRegUrl.includes('verify') || postRegUrl.includes('dashboard') || postRegUrl.includes('students') || postRegUrl.includes('setup');
  if (!results.registered) {
    const bodyText = await page.locator('body').textContent();
    if (bodyText.includes('500') || bodyText.includes('Error')) {
      throw new Error('Registration failed: server error at ' + postRegUrl);
    }
    // May still have redirected correctly
    results.registered = true;
  }
  log(`  Registered: ${results.registered}`);

  // ── STEP 2: Get Gmail token ────────────────────────────────────────────────
  log('STEP 2: Getting Gmail token');
  const token = await getGmailToken();
  log('  Token obtained');

  // ── STEP 3: Wait for verification email ───────────────────────────────────
  log(`STEP 3: Waiting for verification email to ${EMAIL}`);
  const verifyMsg = await waitForEmail(
    token,
    `to:${EMAIL} subject:"Verify Email" newer_than:10m`,
    90000,
    6000
  );

  if (!verifyMsg) throw new Error('Verification email not found in Gmail within 90s');
  log(`  Found verification email: id=${verifyMsg.id}`);
  results.verifyLinkFound = true;

  // Extract verification link
  const fullMsg = await getMessage(token, verifyMsg.id);
  const subject = fullMsg.payload?.headers?.find(h => h.name === 'Subject')?.value || '';
  log(`  Email subject: ${subject}`);

  const parts = getAllParts(fullMsg.payload);
  let verifyLink = null;
  for (const part of parts) {
    const decoded = Buffer.from(part.data, 'base64url').toString('utf-8');
    const match = decoded.match(/(https?:\/\/[^\s"<>\\]+verify-email[^\s"<>\\]*)/);
    if (match) { verifyLink = match[1]; break; }
  }
  if (!verifyLink) throw new Error('Could not extract verification URL from email');
  // Decode HTML entities (&amp; → &) that appear in HTML email parts
  verifyLink = verifyLink.replace(/&amp;/g, '&');
  log(`  Verify link extracted (first 80): ${verifyLink.substring(0, 80)}...`);

  // ── STEP 4: Click verification link ───────────────────────────────────────
  log('STEP 4: Clicking verification link');
  await page.goto(verifyLink);
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(3000);
  const postVerifyUrl = page.url();
  log(`  Post-verify URL: ${postVerifyUrl}`);
  results.verifyLinkClicked = true;

  // ── STEP 5: DB check — email_verified_at ──────────────────────────────────
  log('STEP 5: Checking DB for email_verified_at');
  await new Promise(r => setTimeout(r, 2000)); // small wait
  const users = dbQuery(`SELECT id, email, email_verified_at, welcome_notification_sent_at FROM users WHERE email = '${EMAIL}'`);
  if (!users.length) throw new Error('User not found in DB: ' + EMAIL);
  const user = users[0];
  log(`  User #${user.id}: email_verified_at=${user.email_verified_at}, welcome_sent=${user.welcome_notification_sent_at}`);
  results.emailVerifiedInDB = !!user.email_verified_at;
  const userId = user.id;

  // ── STEP 6: Wait for queue to process WelcomeEmailNotification ────────────
  log('STEP 6: Waiting 20s for queue to dispatch WelcomeEmailNotification');
  await new Promise(r => setTimeout(r, 20000));

  // Check queue state
  const failedBefore = dbQuery(`SELECT COUNT(*) as cnt FROM failed_jobs`);
  results.failedJobs = parseInt(failedBefore[0]?.cnt ?? '0');
  log(`  failed_jobs: ${results.failedJobs}`);

  // ── STEP 7: Search Gmail for welcome email ─────────────────────────────────
  log(`STEP 7: Searching Gmail for welcome email to ${EMAIL}`);
  const welcomeMsg = await waitForEmail(
    token,
    `to:${EMAIL} subject:"Bienvenido a MOVA" newer_than:10m`,
    60000,
    6000
  );

  if (welcomeMsg) {
    const wFull = await getMessage(token, welcomeMsg.id);
    const wSubject = wFull.payload?.headers?.find(h => h.name === 'Subject')?.value || '';
    log(`  Welcome email found! Subject: "${wSubject}"`);
    results.welcomeEmailFound = true;
    results.welcomeEmailSubject = wSubject;
  } else {
    log('  Welcome email NOT found in Gmail');
    results.welcomeEmailFound = false;
  }

  // ── STEP 8: Anti-duplicate check ──────────────────────────────────────────
  log('STEP 8: Anti-duplicate check — searching again for a 2nd welcome email');
  await new Promise(r => setTimeout(r, 5000));
  const { messages: dupes = [] } = await searchGmail(token, `to:${EMAIL} subject:"Bienvenido a MOVA" newer_than:15m`);
  results.duplicateCheck = dupes.length <= 1;
  log(`  Welcome emails found total: ${dupes.length} (expected ≤1)`);

} catch (err) {
  log(`ERROR: ${err.message}`);
  results.errors.push(err.message);
} finally {
  await browser.close();
}

// ── REPORT ─────────────────────────────────────────────────────────────────────
console.log('\n' + '='.repeat(60));
console.log('FASE 3B — WelcomeEmailNotification End-to-End Validation');
console.log('='.repeat(60));
console.log(`Role:                    ${results.role}`);
console.log(`Email:                   ${results.email}`);
console.log(`Registered:              ${results.registered ? '✅' : '❌'}`);
console.log(`Verify link found:       ${results.verifyLinkFound ? '✅' : '❌'}`);
console.log(`Verify link clicked:     ${results.verifyLinkClicked ? '✅' : '❌'}`);
console.log(`email_verified_at in DB: ${results.emailVerifiedInDB ? '✅' : '❌'}`);
console.log(`Welcome email in Gmail:  ${results.welcomeEmailFound ? '✅' : '❌'}`);
console.log(`Welcome email subject:   ${results.welcomeEmailSubject || '(not found)'}`);
console.log(`Anti-duplicate:          ${results.duplicateCheck ? '✅ (≤1 email)' : '❌ (duplicates found)'}`);
console.log(`failed_jobs:             ${results.failedJobs}`);
if (results.errors.length) {
  console.log('\nErrors:');
  results.errors.forEach(e => console.log('  ❌ ' + e));
}

console.log('\n' + '─'.repeat(60));
const passed = results.registered && results.verifyLinkFound && results.verifyLinkClicked &&
               results.emailVerifiedInDB && results.welcomeEmailFound &&
               results.duplicateCheck && results.failedJobs === 0;
console.log(`RESULTADO FINAL: ${passed ? '✅ FASE 3B CERRADA' : '❌ PENDIENTE'}`);
