import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
const root = path.resolve(import.meta.dirname, '../..');

/**
 * Ejecuta código en tinker y devuelve su salida — FALLANDO EN EL PUNTO REAL si
 * el código lanza.
 *
 * POR QUÉ HACE FALTA EL CENTINELA (verificado, no supuesto):
 *
 *   php artisan tinker --execute="throw new RuntimeException('BOOM');"
 *   → imprime la excepción y sale con EXIT CODE 0.
 *
 * Es decir, execFileSync NO lanza y el TEXTO DE LA EXCEPCIÓN se devuelve como si
 * fuera un dato válido. Eso fue exactamente lo que ocurrió en la Fase 2B: el
 * spec usaba `whereHas('report')` (la relación real de Lesson es
 * `lessonReport()`), tinker lanzó, el mensaje de error se usó como ID de clase,
 * y el fallo se manifestó mucho después como un 404 confuso en
 * `/lessons/<texto del error>/report` — que parecía un bug del producto y no lo
 * era.
 *
 * El centinela solo se imprime si la última instrucción se alcanzó. Una
 * excepción aborta antes, así que su ausencia es prueba de fallo,
 * independientemente del exit code.
 */
const SENTINEL = '__MOVA_TINKER_OK__';
const php = (code) => {
  const out = execFileSync(
    'php',
    ['artisan', 'tinker', `--execute=${code} echo '${SENTINEL}';`],
    { cwd: root, encoding: 'utf8' },
  );

  if (!out.includes(SENTINEL)) {
    throw new Error(
      `Tinker no completó el fixture (probable excepción). Salida:\n${out.trim()}\n\nCódigo:\n${code}`,
    );
  }

  return out.slice(0, out.indexOf(SENTINEL)).trim();
};
async function login(page, email='padre@mova.test') {
  await page.goto('/login');
  const cookie = page.getByRole('button',{name:'Aceptar',exact:true});
  if (await cookie.isVisible()) await cookie.click();
  await page.getByLabel('Correo electrónico').fill(email);
  await page.getByLabel('Contraseña',{exact:true}).fill('password123');
  await page.getByRole('button',{name:'Iniciar sesión',exact:true}).click();
  await page.waitForURL(/\/dashboard/);
}
async function shot(page, info, name) {
  await expect(page.locator('body')).toBeVisible();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
  await page.screenshot({path:info.outputPath(name+'.png'),fullPage:true});
}
// Solo se ejecuta con el runner que fija el target y una SQLite dedicada.
test.beforeAll(()=>{
  if (!process.env.DB_DATABASE?.endsWith('phase2b-e2e.sqlite') || process.env.BASE_URL!=='http://127.0.0.1:8012') throw Error('Use qa/run-stabilization.ps1');
});
for (const variant of [
  {name:'desktop',viewport:{width:1440,height:1000},theme:'light'},
  {name:'mobile',viewport:{width:390,height:844},theme:'light'},
  {name:'dark',viewport:{width:1440,height:1000},theme:'dark'},
]) {
  test.describe(variant.name,()=>{
    test.use({viewport:variant.viewport});
    test.beforeEach(async({page})=>{
      await page.addInitScript(theme=>localStorage.setItem('mova-theme',theme),variant.theme);
      await page.route('**/*',r=>['127.0.0.1','localhost'].includes(new URL(r.request().url()).hostname)?r.continue():r.abort());
    });
    test('GoogleRole: teclado, selección y estado inicial',async({page},info)=>{
      // La pantalla de eleccion de rol SOLO aparece para cuentas NUEVAS: si el
      // correo ya existe, GoogleAuthController inicia sesion y respeta el rol
      // que ya tenia (comportamiento correcto y deliberado, R-19).
      //
      // La variante `dark` crea esa cuenta al final de este mismo test, asi que
      // sin esta limpieza el test dependeria del orden de ejecucion. Se borra
      // aqui en vez de confiar en que `dark` sea siempre la ultima.
      php("App\\Models\\User::where('email','google-phase2b@mova.test')->each(fn($u)=>$u->delete());");

      await page.goto('/auth/google/callback');
      await expect(page.getByRole('heading',{name:'Casi listo'})).toBeVisible();
      const submit=page.getByRole('button',{name:'Crear mi cuenta'});
      await expect(submit).toBeDisabled();
      const teacher=page.getByRole('button',{name:/Soy profesor/});
      await teacher.press('Space');
      await expect(teacher).toHaveClass(/border-brand-600/);
      await page.getByRole('checkbox').check();
      await expect(submit).toBeEnabled();
      await shot(page,info,'google-role');
      if (variant.name==='dark') { await submit.click(); await page.waitForURL(/\/teacher\/setup/); }
    });
    test('padre: teléfono, recomendaciones, expiración y reporte',async({page},info)=>{
      await login(page);
      await page.goto('/profile');
      const phone=page.getByLabel('Celular',{exact:true});
      await phone.fill('12345');
      await phone.locator('xpath=ancestor::form').getByRole('button',{name:'Guardar',exact:true}).click();
      await expect(page.getByText(/teléfono.*válido|celular.*válido|número.*válido/i)).toBeVisible();
      await shot(page,info,'phone-error');
      await phone.fill('987654322');
      await phone.locator('xpath=ancestor::form').getByRole('button',{name:'Guardar',exact:true}).click();
      await expect(phone).toHaveValue('987654322');
      await page.reload();
      await expect(page.getByLabel('Celular',{exact:true})).toHaveValue('987654322');
      const diagnostic=php("$p=App\\Models\\User::where('email','padre@mova.test')->firstOrFail(); $s=$p->students()->first(); $o=App\\Models\\ClassOffer::where('is_active',true)->firstOrFail(); $d=App\\Models\\StudentDiagnostic::create(['parent_user_id'=>$p->id,'student_id'=>$s->id,'subject_id'=>$o->subject_id,'level'=>$s->grade_level,'difficulty_text'=>'Baseline QA ecuaciones','goal'=>'prepare_exam','urgency'=>'this_week','status'=>'completed']); app(App\\Services\\DiagnosticRecommendationService::class)->compute($d); echo $d->id;");
      await page.goto(`/diagnostics/${diagnostic}/results`);
      await expect(page.getByRole('heading',{name:'Profesores que encajan con lo que nos contaste'})).toBeVisible();
      await expect(page.getByRole('link',{name:'Ver perfil'}).first()).toBeVisible();
      await shot(page,info,'recommendations');
      php(`App\\Models\\DiagnosticRecommendation::where('student_diagnostic_id',${diagnostic})->delete();`);
      await page.reload();
      await expect(page.getByText('Todavía no podemos sugerirte profesores concretos.')).toBeVisible();
      await shot(page,info,'recommendations-empty');
      php("$p=App\\Models\\User::where('email','padre@mova.test')->firstOrFail(); App\\Models\\ClassRequest::create(['student_id'=>$p->students()->first()->id,'subject_id'=>App\\Models\\Subject::first()->id,'level'=>'secundaria','help_needed'=>'PHASE2B expiration fixture','status'=>'open'])->forceFill(['created_at'=>now()->subHours(25)])->save(); Illuminate\\Support\\Facades\\Artisan::call('mova:expire-class-requests');");
      await page.goto('/class-requests');
      await expect(page.getByText('Caducada').first()).toBeVisible();
      await shot(page,info,'expired-request');
      // `lessonReport`, no `report`: es el nombre real de la relación en
      // App\Models\Lesson. Con `report` tinker lanzaba y —antes de endurecer
      // php()— el texto del error acababa usado como ID de clase.
      const lesson=php("echo App\\Models\\Lesson::whereHas('student.parent',fn($q)=>$q->where('email','padre@mova.test'))->whereHas('lessonReport')->firstOrFail()->id;");
      await page.goto(`/lessons/${lesson}/report`);
      await expect(page.getByText(/Forbidden|403/)).toHaveCount(0);
      await expect(page.locator('main')).toContainText(/Reporte|reporte/);
      await shot(page,info,'parent-report');
    });
    test('admin: reversión, motivo, foco y cierre por teclado',async({page},info)=>{
      // FIXTURE PROPIO POR EJECUCION, no compartido.
      //
      // La variante `dark` de este mismo test REVIERTE la recarga, asi que una
      // recarga fixture unica y reutilizada solo sirve una vez: en la segunda
      // ejecucion ya esta en estado `reversed` y el boton "Revertir" no existe.
      // Eso hacia que el gate solo pasara "una vez" — justo lo que no se acepta.
      //
      // Cada ejecucion crea su propia recarga aprobada con un numero de
      // operacion unico (hay UNIQUE sobre operation_number_normalized). El
      // listado va ordenado por `latest()`, asi que esta es la primera fila.
      php("$admin=App\\Models\\User::where('email','admin-phase2b@mova.test')->firstOrFail();"
        +"$p=App\\Models\\User::where('email','profesor@mova.test')->firstOrFail()->teacherProfile;"
        +"$op='PHASE2B-QA-REV-'.bin2hex(random_bytes(6));"
        +"$r=App\\Models\\RechargeRequest::create(['teacher_profile_id'=>$p->id,'package_code'=>'inicio','package_name'=>'Baseline QA','credits'=>5,'amount_pen'=>'5.00','payment_method'=>'yape','operation_number'=>$op,'operation_number_normalized'=>$op,'status'=>'pending']);"
        +"app(App\\Services\\RechargeApprovalService::class)->credit($r,$admin->id);");

      await login(page,'admin-phase2b@mova.test');
      await page.goto('/admin/recharges');
      await page.getByRole('button',{name:'Revertir',exact:true}).first().click();
      await expect(page.getByRole('heading',{name:'Revertir recarga aprobada'})).toBeVisible();
      await page.getByRole('button',{name:'Revertir y descontar'}).click();
      await expect(page.getByText('El motivo debe tener al menos 10 caracteres.')).toBeVisible();
      await page.getByLabel('Motivo (obligatorio)').fill('Fixture QA: comprobante duplicado.');
      await page.getByLabel('Motivo (obligatorio)').press('Tab');
      await shot(page,info,'admin-reverse-modal');
      await page.keyboard.press('Escape');
      await expect(page.getByRole('heading',{name:'Revertir recarga aprobada'})).toHaveCount(0);
      if (variant.name==='dark') {
        await page.getByRole('button',{name:'Revertir',exact:true}).first().click();
        await page.getByLabel('Motivo (obligatorio)').fill('Fixture QA: comprobante duplicado.');
        await page.getByRole('button',{name:'Revertir y descontar'}).click();
        await expect(page.getByText('Revertida',{exact:true}).first()).toBeVisible();
      }
    });
  });
}
