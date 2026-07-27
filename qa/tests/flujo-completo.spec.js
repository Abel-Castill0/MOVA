// @ts-check
//
// Flujo E2E completo de MOVA: registro/login del padre → solicitud → aceptar
// → agendar → pago → reporte → calificación → verificación de crédito.
//
// IMPORTANTE — este spec corre EXCLUSIVAMENTE contra el entorno local, nunca
// contra producción. A diferencia del resto de qa/tests/*.spec.js (que leen
// BASE_URL desde qa/.env.qa, apuntando por defecto a la Railway de
// producción con cuentas reales), este archivo hardcodea la URL y usa
// exclusivamente los usuarios de LocalTestDataSeeder (padre@mova.test /
// profesor@mova.test), que solo existen en la base de datos local.
//
// Requisitos antes de correr:
//   - MySQL local activo, migraciones + LocalTestDataSeeder ya sembrados
//   - `php artisan serve --port=8000` corriendo
//   - `npm run dev` corriendo (o `npm run build` ya ejecutado)
//
// Correr con la config local (no la compartida playwright.config.js, que
// fuerza channel:'chrome' — el Chrome de sistema, no siempre disponible sin
// privilegios de administrador):
//   cd qa && npx playwright test --config=playwright.local.config.js tests/flujo-completo.spec.js
//
// Idempotencia: cada corrida crea su propia ClassRequest/Lesson con un
// marcador único (timestamp), por lo que no colisiona con corridas previas
// ni con los datos fijos del seeder. No requiere limpiar estado entre
// corridas.

import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import path from 'node:path';

const BASE_URL = 'http://localhost:8000';
if (/railway\.app|mova-production/.test(BASE_URL)) {
  throw new Error('flujo-completo.spec.js nunca debe apuntar a producción.');
}

const PARENT_EMAIL = 'padre@mova.test';
const PARENT_PASSWORD = 'password123';
const TEACHER_EMAIL = 'profesor@mova.test';
const TEACHER_PASSWORD = 'password123';

const PROJECT_ROOT = path.resolve(import.meta.dirname, '../..');
const MARKER = `E2E-PLAYWRIGHT-${Date.now()}`;

/** Ejecuta un comando artisan en la raíz del proyecto Laravel y devuelve stdout. */
function artisan(cmd) {
  return execSync(`php artisan ${cmd}`, { cwd: PROJECT_ROOT, encoding: 'utf-8' }).trim();
}

/** Ejecuta PHP inline (tinker --execute) y devuelve stdout, ya trimeado. */
function tinker(code) {
  const escaped = code.replace(/"/g, '\\"');
  return artisan(`tinker --execute="${escaped}"`);
}

async function login(page, email, password) {
  await page.goto(`${BASE_URL}/login`);
  await page.locator('#email').fill(email);
  await page.locator('#password').fill(password);
  await page.getByRole('button', { name: 'Iniciar sesión' }).click();
  await page.waitForURL(/\/dashboard/, { timeout: 15000 });
}

async function logout(page) {
  await page.getByRole('button', { name: 'Cerrar sesión' }).click();
  await page.waitForURL(BASE_URL + '/', { timeout: 15000 });
}

// ClassRequests/Index.vue (padre) y ClassRequests/TeacherIndex.vue (profesor)
// renderizan cada solicitud como el div raíz de su v-for. Un selector
// genérico `div` con .filter({hasText}) matchea también los descendientes
// anidados (párrafos, wrappers internos), y `.last()` puede terminar
// apuntando a un nodo demasiado profundo que no incluye el botón hermano
// que buscamos — de ahí el timeout que se vio en la primera corrida.
//
// Las dos vistas ya NO comparten la misma clase de card: la pasada de
// consistencia visual del padre (rounded-2xl/border-gray-100) solo tocó
// ClassRequests/Index.vue, no TeacherIndex.vue (fuera de alcance, es una
// vista del profesor) — de ahí los dos selectores separados.
const PARENT_REQUEST_CARD = '.bg-white.rounded-2xl.border.border-gray-100';
const TEACHER_REQUEST_CARD = '.bg-white.rounded-xl.border.border-gray-200.p-5';

// Lessons/ParentIndex.vue usa una clase de card distinta a la de las
// ClassRequest cards (rounded-2xl/border-gray-100 vs rounded-xl/border-gray-200),
// y no renderiza el MARKER en ningún campo visible (ni help_needed ni el tema
// del reporte aparecen en esta vista) — por eso no se puede filtrar por texto
// aquí como en los pasos 2 y 4.
const LESSON_CARD = '.bg-white.rounded-2xl.border.border-gray-100';

test.describe.serial('Flujo completo MOVA (local)', () => {
  /** @type {number} */
  let classRequestId;
  /** @type {number} */
  let lessonId;

  test('flujo de 8 pasos: solicitud → agendar → pago → reporte → calificación', async ({ page }) => {
    await test.step('0. Recargar créditos del profesor de prueba', async () => {
      // LessonController::store() descuenta CLASS_CREDIT_COST_PER_CLASS del
      // credits_available del profesor en cada aceptación y nunca lo
      // reembolsa (consumption es el estado final normal, no una
      // devolución). Sin este top-up, tras un puñado de corridas el
      // profesor de prueba se queda sin crédito y el paso 4 falla con
      // "Créditos insuficientes" — rompiendo el requisito de idempotencia.
      tinker(
        `App\\Models\\TeacherProfile::whereHas('user', fn($q) => $q->where('email','${TEACHER_EMAIL}'))` +
          `->update(['credits_available' => 100000]);`
      );
    });

    await test.step('1. Login del padre', async () => {
      await login(page, PARENT_EMAIL, PARENT_PASSWORD);
      await expect(page.getByText('Bienvenido/a de vuelta')).toBeVisible();
    });

    await test.step('2. Publicar solicitud de clase', async () => {
      await page.goto(`${BASE_URL}/class-requests/create`);
      await page.locator('select').nth(0).selectOption({ label: 'Mateo Prueba' });
      await page.locator('select').nth(1).selectOption({ label: 'Matemáticas' });
      await page.locator('textarea').fill(`${MARKER}: ecuaciones cuadráticas para examen.`);
      await page.getByRole('button', { name: 'Enviar solicitud' }).click();
      await page.waitForURL(/\/class-requests$/, { timeout: 15000 });

      const card = page.locator(PARENT_REQUEST_CARD).filter({ hasText: MARKER }).last();
      await expect(card).toBeVisible();
      await expect(card.getByText('Abierta')).toBeVisible();
    });

    await test.step('3. Logout padre → login profesor', async () => {
      await logout(page);
      await login(page, TEACHER_EMAIL, TEACHER_PASSWORD);
    });

    await test.step('4. Aceptar solicitud y agendar clase', async () => {
      await page.goto(`${BASE_URL}/teacher/requests`);
      const card = page.locator(TEACHER_REQUEST_CARD).filter({ hasText: MARKER }).last();
      await expect(card).toBeVisible();

      const acceptLink = card.getByRole('link', { name: 'Aceptar' });
      const href = await acceptLink.getAttribute('href');
      classRequestId = Number(href.match(/\/teacher\/requests\/(\d+)\/accept/)[1]);
      expect(classRequestId).toBeGreaterThan(0);

      await acceptLink.click();
      await page.waitForURL(/\/teacher\/requests\/\d+\/accept/);

      // Fecha futura arbitraria; el comando de backdate del paso 5 la
      // reescribe de todas formas. Se usa +5 días con un offset aleatorio
      // en minutos para minimizar colisión de horario con las 2 clases que
      // LocalTestDataSeeder ya agenda para este mismo profesor+alumno.
      const randomMinutes = Math.floor(Math.random() * 600);
      const future = new Date(Date.now() + 5 * 24 * 60 * 60 * 1000 + randomMinutes * 60 * 1000);
      const iso = future.toISOString().slice(0, 16);
      await page.locator('input[type="datetime-local"]').fill(iso);
      await page.getByRole('button', { name: '1 h' }).click();
      await page.getByRole('button', { name: 'Confirmar y crear clase' }).click();
      await page.waitForURL(/\/teacher\/classes$/, { timeout: 15000 });

      lessonId = Number(
        tinker(
          `echo App\\Models\\Lesson::where('class_request_id', ${classRequestId})->firstOrFail()->id;`
        )
      );
      expect(lessonId).toBeGreaterThan(0);
    });

    await test.step('5. Backdatear la clase vía shell (mova:testing-backdate-lesson)', async () => {
      const output = artisan(`mova:testing-backdate-lesson ${lessonId}`);
      expect(output).toContain('backdated');

      const status = tinker(`echo App\\Models\\Lesson::find(${lessonId})->status;`);
      expect(status).toBe('scheduled');
    });

    await test.step('6. Logout profesor → login padre', async () => {
      await logout(page);
      await login(page, PARENT_EMAIL, PARENT_PASSWORD);
    });

    await test.step('7. Confirmar pago ("Ya pagué")', async () => {
      // El botón "Ya pagué" no expone el lesson id en el DOM (no tiene href).
      // Replicamos el mismo orderBy('start_time','desc') que usa
      // LessonController::parentIndex() para calcular en qué posición del
      // listado cae nuestra lesson, en vez de adivinar por texto/fecha.
      // El botón "Ya pagué" solo se renderiza para clases cuyo end_time ya
      // pasó (ver ParentIndex.vue::hasClassEnded) — filtramos igual que el
      // frontend, no basta con status='scheduled'.
      const ids = tinker(
        `echo App\\Models\\Lesson::where('student_id', App\\Models\\Student::where('first_name','Mateo')->where('last_name','Prueba')->first()->id)` +
          `->where('status','scheduled')->orderBy('start_time','desc')->get()` +
          `->filter(fn($l) => now()->gte($l->end_time))->pluck('id')->implode(',');`
      )
        .split(',')
        .map(Number);
      const index = ids.indexOf(lessonId);
      expect(index).toBeGreaterThanOrEqual(0);

      await page.goto(`${BASE_URL}/my-classes`);
      await page.getByRole('button', { name: '✓ Ya pagué' }).nth(index).click();
      await page.waitForLoadState('networkidle');

      const status = tinker(`echo App\\Models\\Lesson::find(${lessonId})->status;`);
      expect(status).toBe('paid');
    });

    await test.step('8. Logout padre → login profesor → subir reporte', async () => {
      await logout(page);
      await login(page, TEACHER_EMAIL, TEACHER_PASSWORD);

      await page.goto(`${BASE_URL}/lessons/${lessonId}/report/create`);
      await page.getByPlaceholder('¿Qué tema desarrollaron en esta clase?').fill(
        `${MARKER}: factorización y fórmula general.`
      );
      await page.getByPlaceholder('¿Cómo estuvo el alumno durante la clase?').fill(
        'Participó activamente y resolvió los ejercicios con poca ayuda.'
      );
      await page.getByRole('button', { name: 'Enviar reporte al padre' }).click();
      await page.waitForURL(new RegExp(`/lessons/${lessonId}/report$`), { timeout: 15000 });

      const status = tinker(`echo App\\Models\\Lesson::find(${lessonId})->status;`);
      expect(status).toBe('pending_parent_confirmation');
    });

    await test.step('9. Logout profesor → login padre → calificar con 5 estrellas', async () => {
      await logout(page);
      await login(page, PARENT_EMAIL, PARENT_PASSWORD);

      await page.goto(`${BASE_URL}/lessons/${lessonId}/review/create`);
      const stars = page.locator('button', { hasText: '★' });
      await stars.nth(4).click(); // 5ta estrella
      await page.locator('textarea').fill(`${MARKER}: excelente explicación.`);
      await page.getByRole('button', { name: 'Enviar reseña' }).click();
      await page.waitForURL(/\/my-classes$/, { timeout: 15000 });
    });

    await test.step('10. Verificar clase "Completada" y consumo de crédito', async () => {
      const status = tinker(`echo App\\Models\\Lesson::find(${lessonId})->status;`);
      expect(status).toBe('completed');

      const ledger = tinker(
        `echo App\\Models\\CreditTransaction::where('lesson_id', ${lessonId})->pluck('type')->implode(',');`
      );
      expect(ledger).toBe('reservation,consumption');

      const review = tinker(
        `echo App\\Models\\TeacherReview::where('lesson_id', ${lessonId})->first()->rating;`
      );
      expect(review).toBe('5');

      // Confirmación visual en la UI, no solo en BD. La card de /my-classes no
      // expone el MARKER en texto visible, así que ubicamos la card por índice
      // replicando el mismo orderBy('start_time','desc') que usa
      // LessonController::parentIndex() (sin filtro de status, a diferencia
      // del paso 7).
      const ids = tinker(
        `echo App\\Models\\Lesson::where('student_id', App\\Models\\Student::where('first_name','Mateo')->where('last_name','Prueba')->first()->id)` +
          `->orderBy('start_time','desc')->pluck('id')->implode(',');`
      )
        .split(',')
        .map(Number);
      const index = ids.indexOf(lessonId);
      expect(index).toBeGreaterThanOrEqual(0);

      await page.goto(`${BASE_URL}/my-classes`);
      const card = page.locator(LESSON_CARD).nth(index);
      await expect(card).toBeVisible();
      await expect(card.getByText('Completada').first()).toBeVisible();
    });
  });

  test('UI de recargas: botón visible, página de créditos carga con los 3 paquetes', async ({ page }) => {
    await test.step('1. Login del profesor', async () => {
      await login(page, TEACHER_EMAIL, TEACHER_PASSWORD);
    });

    await test.step('2. El botón de recarga es visible desde el dashboard', async () => {
      const dashboardBtn = page.getByRole('link', { name: /Recargar créditos/ });
      await expect(dashboardBtn).toBeVisible();
      await expect(dashboardBtn).toHaveAttribute('href', /\/teacher\/credits$/);
    });

    await test.step('3-4. Navegar a la página de créditos y verificar los 3 paquetes', async () => {
      await page.goto(`${BASE_URL}/teacher/credits`);
      await expect(page.getByText('Mis creditos MOVA')).toBeVisible();

      // Botón destacado de recarga en la propia página de créditos — siempre
      // visible, apunta a la sección de paquetes.
      const creditsBtn = page.getByRole('link', { name: /Recargar créditos \(Yape\/Plin\)/ });
      await expect(creditsBtn).toBeVisible();
      await expect(creditsBtn).toHaveAttribute('href', '#recargas');

      // RECHARGES_ENABLED decide si Teacher/Credits/Index.vue renderiza los
      // paquetes o el mensaje "próximamente" — nunca tocamos esa variable
      // (la decide el dueño en .env), así que el test verifica ambos casos
      // reales en vez de asumir uno solo. Replica exactamente la condición de
      // CreditController::rechargesEnabled() (enabled Y payment_destination
      // configurado), no solo el flag suelto.
      const rechargesEnabled = tinker(
        `echo (config('credits.recharges.enabled') && filled(config('credits.recharges.payment_destination'))) ? '1' : '0';`
      ) === '1';

      if (rechargesEnabled) {
        for (const name of ['Inicio', 'Impulso', 'Pro']) {
          await expect(page.getByRole('heading', { name })).toBeVisible();
        }
        await expect(page.getByText('5 creditos')).toBeVisible();
        await expect(page.getByText('15 creditos')).toBeVisible();
        await expect(page.getByText('30 creditos')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Comprar' }).first()).toBeVisible();
      } else {
        await expect(page.getByText('Las recargas se habilitarán próximamente.')).toBeVisible();

        // Los paquetes no se renderizan mientras RECHARGES_ENABLED=false, pero
        // el catálogo del backend (fuente de verdad para la UI el día que se
        // habilite) debe tener exactamente los 3 paquetes acordados con el dueño.
        const packages = tinker(
          `echo collect(config('credits.packages'))->map(fn($p) => "{$p['name']}:{$p['credits']}:{$p['amount_pen']}")->implode('|');`
        );
        expect(packages).toBe('Inicio:5:10.00|Impulso:15:30.00|Pro:30:60.00');
      }
    });

    await test.step('5. No se simula ningún pago — solo se verifica que la UI carga sin errores', async () => {
      // El flujo de recarga (registrar número de operación Yape/Plin) es
      // manual y requiere revisión de un admin; este test no lo ejecuta.
      expect(page.url()).toContain('/teacher/credits');
    });
  });
});
