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
// Import intencional del util real de la app (no un residuo de copy-paste):
// los pasos 7 y 10 necesitan reproducir el orden exacto en que
// ParentIndex.vue agrupa/invierte "Esta semana" para ubicar el botón/card
// correctos en el DOM. Reimplementar esa lógica aparte en el test permitiría
// que ambas copias diverjan en silencio — ver los comentarios en los pasos
// 7 y 10 para el porqué completo.
import { splitByWeek } from '../../resources/js/utils/weekGrouping.js';

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
      // LessonController::store() descuenta Lesson::creditCostForMinutes()
      // (1 crédito por hora o fracción; este flujo agenda 1h = 1 crédito) del
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
      // Su posición en el DOM sigue el mismo balde/orden de ParentIndex.vue
      // que el paso 10 (ver el comentario detallado ahí) — no un
      // orderBy('start_time','desc') plano. Se calcula igual: se trae la
      // lista completa, se reproduce el bucketing real con splitByWeek(), y
      // recién ahí se filtra a las que califican para "Ya pagué" (scheduled
      // + end_time ya pasado, igual que ParentIndex.vue::hasClassEnded).
      // get() completo (no una selección de columnas): Lesson::$appends
      // incluye accesores calculados (credit_cost, end_time, has_jitsi_room)
      // que leen duration_minutes/jitsi_room internamente — toJson() los
      // serializa siempre, así que limitar columnas con get([...]) rompe la
      // serialización con un TypeError en cuanto falta alguna que el
      // accesor necesita.
      const rows = JSON.parse(
        tinker(
          `echo App\\Models\\Lesson::where('student_id', App\\Models\\Student::where('first_name','Mateo')->where('last_name','Prueba')->first()->id)` +
            `->orderBy('start_time','desc')->get()->toJson();`
        )
      );
      const { thisWeek, past } = splitByWeek(rows);
      const domOrder = [...thisWeek].reverse().concat(past);
      const payable = domOrder.filter((l) => {
        if (l.status !== 'scheduled') return false;
        const endTime = new Date(new Date(l.start_time).getTime() + l.duration_minutes * 60_000);
        return Date.now() >= endTime.getTime();
      });
      const index = payable.findIndex((l) => l.id === lessonId);
      expect(index).toBeGreaterThanOrEqual(0);

      await page.goto(`${BASE_URL}/my-classes`);

      // Causa raíz del flake original: se hacía click en .nth(index) justo
      // después de goto(), sin esperar visibilidad — a diferencia de los
      // pasos 2 y 4 de este mismo archivo, que sí confirman
      // expect(card).toBeVisible() antes de interactuar. Sin esa espera, el
      // click podía llegar antes de que Vue montara/hidratara este botón
      // en particular, y el evento se perdía en silencio (sin lanzar error:
      // Playwright encuentra el locator, pero el nodo aún no es interactivo
      // de forma estable). Confirmar visibilidad primero replica el patrón
      // ya probado del resto del archivo.
      const payButton = page.getByRole('button', { name: '✓ Ya pagué' }).nth(index);
      await expect(payButton).toBeVisible();
      await payButton.click();
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
      // expone el MARKER en texto visible, así que ubicamos la card por
      // índice — pero el DOM NO sigue un simple orderBy('start_time','desc')
      // plano: ParentIndex.vue divide en dos baldes ("Esta semana" y "Clases
      // pasadas" vía weekGrouping.js::splitByWeek) y además invierte el
      // orden del balde "Esta semana" (ver ParentIndex.vue: `thisWeek =
      // [...grouped.thisWeek].reverse()`) para mostrar la más próxima
      // primero. Con una sola lección de por medio esa inversión no se
      // notaba, pero apenas hay ≥2 lecciones en la semana actual (algo
      // frecuente en un entorno local que acumula corridas de este mismo
      // archivo, ver nota de idempotencia arriba) el índice calculado con un
      // orderBy plano deja de coincidir con la posición real en el DOM.
      // Se reutiliza el propio splitByWeek() de la app (no una reimplementación
      // aparte) para que este cálculo nunca pueda desincronizarse del
      // comportamiento real del componente. get() completo, no una
      // selección de columnas: ver nota igual en el paso 7 sobre
      // Lesson::$appends y toJson().
      const rows = JSON.parse(
        tinker(
          `echo App\\Models\\Lesson::where('student_id', App\\Models\\Student::where('first_name','Mateo')->where('last_name','Prueba')->first()->id)` +
            `->orderBy('start_time','desc')->get()->toJson();`
        )
      );
      const { thisWeek, past } = splitByWeek(rows);
      const domOrder = [...thisWeek].reverse().concat(past);
      const index = domOrder.findIndex((l) => l.id === lessonId);
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

  // Independiente de los dos tests anteriores (no depende de classRequestId
  // ni lessonId): usa las clases fijas que ya trae LocalTestDataSeeder para
  // el padre de prueba, igual que el test de recargas usa datos fijos del
  // profesor. Vive en este mismo archivo (en vez de uno nuevo) para no
  // duplicar login/logout/BASE_URL — ya establecidos arriba.
  test('Calendario semanal: pestañas, navegación de semana y bloque de clase', async ({ page }) => {
    await test.step('1. Login del padre y llegar a Mis clases', async () => {
      await login(page, PARENT_EMAIL, PARENT_PASSWORD);
      await page.goto(`${BASE_URL}/my-classes`);
      await expect(page.getByRole('tab', { name: 'Lista' })).toBeVisible();
    });

    await test.step('2. Cambiar a la pestaña Calendario', async () => {
      await page.getByRole('tab', { name: 'Calendario' }).click();
      await expect(page.getByRole('tab', { name: 'Calendario' })).toHaveAttribute('aria-selected', 'true');
      await expect(page.getByRole('tabpanel')).toBeVisible();
    });

    await test.step('3. El grid renderiza con al menos un bloque de clase (semana actual)', async () => {
      // La semana actual (17-23 ago 2026) tiene la clase "scheduled" fija del
      // seeder — ver ParentLessonCard ya visible en el test anterior.
      // .first(): corridas repetidas de este archivo van acumulando clases
      // "scheduled" en la semana actual (cada una con su propio bloque
      // "Unirse →" — ver nota de idempotencia al inicio del archivo), así
      // que puede haber más de una coincidencia. Basta con que exista una.
      await expect(page.getByText('LUN')).toBeVisible();
      await expect(page.getByText('Unirse →').first()).toBeVisible();
    });

    await test.step('4. Navegar a la semana anterior muestra "Volver a hoy" y otras clases', async () => {
      await page.getByRole('button', { name: 'Semana anterior' }).click();
      await expect(page.getByText('Volver a hoy')).toBeVisible();
      // 10-16 ago 2026 tiene 2 clases "completed" fijas del seeder.
      await expect(page.getByText('Matemáticas').first()).toBeVisible();
    });

    await test.step('5. "Volver a hoy" regresa a la semana actual', async () => {
      await page.getByText('Volver a hoy').click();
      await expect(page.getByText('Volver a hoy')).not.toBeVisible();
    });

    await test.step('6. Volver a la pestaña Lista conserva la vista de tarjetas', async () => {
      await page.getByRole('tab', { name: 'Lista' }).click();
      await expect(page.getByRole('tab', { name: 'Lista' })).toHaveAttribute('aria-selected', 'true');
      await expect(page.getByText('Esta semana')).toBeVisible();
    });
  });
});
