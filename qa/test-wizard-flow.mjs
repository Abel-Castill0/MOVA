import { chromium } from 'playwright';
import { createConnection } from 'mysql2/promise';

const BASE  = 'https://mova-production-8750.up.railway.app';
const EMAIL = 'abelcastilloyarin3@gmail.com';
const PASS  = 'familiawuarthon123';

const DB = {
  host: 'reseau.proxy.rlwy.net', port: 42114,
  database: 'railway', user: 'root',
  password: 'OefhzFYBJvJQjyUUDKwtfXIEjLuJcRFj',
};

async function dbQuery(sql) {
  const conn = await createConnection(DB);
  const [rows] = await conn.execute(sql);
  await conn.end();
  return rows;
}

const browser = await chromium.launch({ headless: true });
const page    = await browser.newPage();

try {
  console.log('1. Login...');
  await page.goto(`${BASE}/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('#email', EMAIL);
  await page.fill('#password', PASS);
  await page.locator('form button').first().click();
  await page.waitForURL(/dashboard/, { timeout: 20_000 });
  console.log('   OK Logged in');

  const cta = await page.locator('text=¿No sabes qué profesor elegir?').isVisible().catch(() => false);
  console.log(`2. Dashboard diagnostic CTA: ${cta ? 'OK visible' : 'FAIL not found'}`);

  await page.goto(`${BASE}/diagnostics/create`);
  await page.waitForLoadState('networkidle');
  const step1 = await page.locator('text=Paso 1 de 5').isVisible({ timeout: 8_000 }).catch(() => false);
  console.log(`3. Wizard step 1 rendered: ${step1 ? 'OK' : 'FAIL'}`);

  const studentBtns = page.locator('button.w-full.rounded-2xl');
  const btnCount    = await studentBtns.count();
  console.log(`4. Student cards found: ${btnCount}`);

  if (btnCount > 0) {
    await studentBtns.first().click();
    await page.waitForTimeout(600);

    const skipBtn = page.locator('button:has-text("No estoy seguro")');
    if (await skipBtn.isVisible().catch(() => false)) {
      await skipBtn.click();
    } else {
      await page.locator('button:has-text("Continuar")').last().click();
    }
    await page.waitForTimeout(500);
    console.log('5. OK Step 2 (subject) passed');

    const ta = page.locator('textarea').first();
    await ta.waitFor({ timeout: 5_000 });
    await ta.fill('Mi hijo tiene problemas con algebra y fracciones. El profesor dice que va atrasado en el curriculo escolar.');
    await page.locator('button:has-text("Continuar")').last().click();
    await page.waitForTimeout(500);
    console.log('6. OK Step 3 (difficulty) filled');

    const goalBtn = page.locator('button:has-text("Reforzar un tema")');
    await goalBtn.waitFor({ timeout: 5_000 });
    await goalBtn.click();
    await page.waitForTimeout(500);
    console.log('7. OK Step 4 (goal) selected');

    const urgBtn = page.locator('button:has-text("Esta semana")');
    await urgBtn.waitFor({ timeout: 5_000 });
    await urgBtn.click();
    await page.waitForTimeout(300);

    const submitBtn = page.locator('button:has-text("Ver profesores recomendados")');
    await submitBtn.waitFor({ timeout: 5_000 });
    await submitBtn.click();
    console.log('8. Submitted form...');

    await page.waitForURL(/\/diagnostics\/\d+\/results/, { timeout: 25_000 });
    const url    = page.url();
    const diagId = url.match(/\/diagnostics\/(\d+)\/results/)?.[1];
    console.log(`   Redirected to: ${url}`);

    await page.waitForLoadState('networkidle');
    const h1      = await page.locator('h1, [class*="font-black"]').first().textContent().catch(() => '');
    const cards   = await page.locator('.bg-white.rounded-2xl.border').count();
    const badges  = await page.locator('text=Verificado').count();
    const solBtns = await page.locator('button:has-text("Solicitar clase")').count();
    console.log(`9. Results: "${h1?.trim()}" | cards=${cards} | verified=${badges} | solicitar=${solBtns}`);

    if (diagId) {
      const [diag] = await dbQuery(`SELECT id, goal, urgency, status FROM student_diagnostics WHERE id=${diagId}`);
      console.log(`10. DB: goal=${diag?.goal} urgency=${diag?.urgency} status=${diag?.status}`);

      const recs = await dbQuery(`SELECT \`rank\`, score, reasons FROM diagnostic_recommendations WHERE student_diagnostic_id=${diagId} ORDER BY \`rank\``);
      console.log(`    Recs in DB: ${recs.length}`);
      for (const r of recs) {
        const reasons = Array.isArray(r.reasons) ? r.reasons : JSON.parse(r.reasons);
        console.log(`    #${r.rank} score=${r.score} | ${reasons.slice(0, 2).join(' | ')}`);
      }

      const html  = await page.content();
      const leaks = ['difficulty_text', 'school_feedback'].filter(k => html.includes(k));
      console.log(`11. Privacy: ${leaks.length === 0 ? 'OK no leaks' : 'FAIL leaked: ' + leaks.join(', ')}`);

      await page.setViewportSize({ width: 375, height: 812 });
      const mobileOk = await page.locator('.bg-white.rounded-2xl.border').first().isVisible().catch(() => false);
      console.log(`12. Mobile responsive (375px): ${mobileOk ? 'OK' : 'FAIL'}`);
    }
  } else {
    console.log('   No student cards — check if students exist or step rendering');
    const pageText = await page.textContent('body').catch(() => '');
    console.log('   Body snippet:', pageText?.slice(0, 200));
  }
} catch (err) {
  console.error('FLOW ERROR:', err.message);
} finally {
  await browser.close();
}
console.log('\nDone.');
