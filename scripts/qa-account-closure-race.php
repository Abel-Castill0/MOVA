<?php

/**
 * Real MySQL lock races, independent PHP processes. No migrations or providers.
 * Run against an already migrated disposable database, never alongside a suite:
 * APP_ENV=testing DB_CONNECTION=mysql_qa php scripts/qa-account-closure-race.php [scenario]
 * Synthetic fixtures remain in mova_qa for inspection; this script never purges.
 *
 * Each scenario starts a "leader" process that performs one operation and keeps
 * its transaction (and locks) open, then a "follower" that must wait on those
 * real locks. Both orders are exercised for every pair.
 */

use App\Events\ClassConfirmed;
use App\Events\ClassRequestCreated;
use App\Models\AccountDeletionRequest;
use App\Models\ClassRequest;
use App\Models\CreditTransaction;
use App\Models\Lesson;
use App\Models\RechargeRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\AccountLifecycleService;
use App\Services\LessonSchedulingService;
use App\Services\LessonSettlementService;
use App\Services\RechargeApprovalService;
use App\Support\QaDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const CLOSED_MESSAGE = 'Una de las cuentas ya no está disponible para nuevas clases.';

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function barrier(string $path): void
{
    $deadline = microtime(true) + 15;
    while (! is_file($path)) {
        check(microtime(true) < $deadline, 'Worker barrier timeout.');
        usleep(10000);
        clearstatcache(true, $path);
    }
}

/** Synthetic fixture shared by every scenario; optional pieces are keyed by $options. */
function buildFixture(string $run, string $leader, string $follower, array $options): array
{
    return DB::transaction(function () use ($run, $leader, $follower, $options): array {
        foreach (['parent', 'teacher', 'admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $parent = User::factory()->create(['email' => 'closure-parent-'.$run.'@example.test']);
        $parent->assignRole('parent');
        $teacherUser = User::factory()->create(['email' => 'closure-teacher-'.$run.'@example.test']);
        $teacherUser->assignRole('teacher');
        $admin = User::factory()->create(['email' => 'closure-admin-'.$run.'@example.test']);
        $admin->assignRole('admin');
        $teacher = TeacherProfile::create(['user_id' => $teacherUser->id, 'is_verified' => $options['teacher_verified'] ?? true, 'hourly_rate' => 20, 'credits_available' => 10, 'credits_reserved' => 0]);
        CreditTransaction::create(['teacher_profile_id' => $teacher->id, 'idempotency_key' => 'qa:'.$run, 'type' => 'deposit', 'amount' => 10, 'description' => 'QA account closure race']);
        $student = Student::create(['parent_user_id' => $parent->id, 'first_name' => 'QA', 'last_name' => 'Closure race', 'grade_level' => 'primaria']);
        $subject = Subject::firstOrCreate(['name' => 'QA closure race'], ['level' => 'Primaria']);
        $teacher->subjects()->sync([$subject->id]);
        $request = ClassRequest::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'duration_minutes' => 60, 'help_needed' => 'QA synthetic concurrency', 'status' => 'open']);
        $fixture = [
            'parent' => $parent->id, 'teacher_user' => $teacherUser->id, 'admin' => $admin->id, 'teacher' => $teacher->id,
            'student' => $student->id, 'subject' => $subject->id, 'request' => $request->id,
            'referral_code' => $teacher->fresh()->referral_code ?? '',
            'start' => now()->addDays(2)->toIso8601String(), 'leader' => $leader, 'follower' => $follower,
            'real_notifications' => $options['real_notifications'] ?? false,
        ];
        if ($options['recharge'] ?? false) {
            // Manual (non provider-managed) recharge: pending operation at closure time.
            $fixture['recharge'] = RechargeRequest::create(['teacher_profile_id' => $teacher->id, 'package_code' => 'inicio', 'package_name' => 'Inicio', 'credits' => 5, 'amount_pen' => 10, 'payment_method' => 'yape', 'status' => 'pending'])->id;
        }
        if ($options['lesson'] ?? false) {
            Auth::setUser($parent);
            $lesson = app(LessonSchedulingService::class)->schedule($request->id, $teacher->id, $fixture['start'], 60, fn () => null);
            if (! ($options['keep_scheduled'] ?? false)) {
                DB::table('classes')->where('id', $lesson->id)->update(['status' => 'needs_admin_review']);
            }
            $fixture['lesson'] = $lesson->id;
        }

        return $fixture;
    });
}

/**
 * Cada escenario: líder (mantiene sus locks) y seguidor (debe esperar). `expect`:
 *  closed         identidad que se cierra (parent|teacher_user)
 *  lessons        estados esperados de las clases de la solicitud original, ordenados
 *  available/reserved  saldo final del profesor
 *  ledger         tipos del ledger del profesor, ordenados
 *  request        estado final de la solicitud original
 *  requests       total de solicitudes del alumno
 *  student_erased el alumno queda anonimizado (cierra el padre)
 *  verified       is_verified esperado del profesor
 *
 * @return array<string,array{leader:string,follower:string,options:array,expect:array}>
 */
function scenarios(): array
{
    $base = ['closed' => 'parent', 'lessons' => [], 'available' => 10, 'reserved' => 0, 'ledger' => ['deposit'], 'request' => 'expired', 'requests' => 1, 'student_erased' => true];

    return [
        'duplicate-closure' => ['leader' => 'close', 'follower' => 'close', 'options' => [], 'expect' => ['leader' => 'closed', 'follower' => 'closed'] + $base],
        'closure-wins' => ['leader' => 'close', 'follower' => 'schedule', 'options' => [], 'expect' => ['leader' => 'closed', 'follower' => 'blocked'] + $base],
        // La clase agendada justo antes del cierre se cancela y su reserva vuelve (un asiento reservation + uno refund).
        'scheduling-wins' => ['leader' => 'schedule', 'follower' => 'close', 'options' => [], 'expect' => ['leader' => 'scheduled', 'follower' => 'closed', 'lessons' => ['cancelled'], 'ledger' => ['deposit', 'refund', 'reservation']] + $base],
        'closure-vs-new-request' => ['leader' => 'close', 'follower' => 'store', 'options' => [], 'expect' => ['leader' => 'closed', 'follower' => 'blocked'] + $base],
        'new-request-vs-closure' => ['leader' => 'store', 'follower' => 'close', 'options' => [], 'expect' => ['leader' => 'stored', 'follower' => 'closed', 'requests' => 2] + $base],
        // Profesor: el cierre da de baja el saldo con un asiento y despublica; nunca queda publicado.
        'teacher-closure-vs-admin-approval' => ['leader' => 'close-teacher', 'follower' => 'verify', 'options' => ['teacher_verified' => false], 'expect' => ['leader' => 'closed', 'follower' => 'blocked', 'closed' => 'teacher_user', 'available' => 0, 'ledger' => ['deposit', 'forfeiture'], 'request' => 'open', 'student_erased' => false, 'verified' => false] + $base],
        'admin-approval-vs-teacher-closure' => ['leader' => 'verify', 'follower' => 'close-teacher', 'options' => ['teacher_verified' => false], 'expect' => ['leader' => 'verified', 'follower' => 'closed', 'closed' => 'teacher_user', 'available' => 0, 'ledger' => ['deposit', 'forfeiture'], 'request' => 'open', 'student_erased' => false, 'verified' => false] + $base],
        // Clase del profesor + reserva: cancelada, reserva devuelta y luego dada de baja; la solicitud vuelve a búsqueda.
        'teacher-closure-vs-booking' => ['leader' => 'close-teacher', 'follower' => 'schedule', 'options' => [], 'expect' => ['leader' => 'closed', 'follower' => 'blocked', 'closed' => 'teacher_user', 'available' => 0, 'ledger' => ['deposit', 'forfeiture'], 'request' => 'open', 'student_erased' => false] + $base],
        'booking-vs-teacher-closure' => ['leader' => 'schedule', 'follower' => 'close-teacher', 'options' => [], 'expect' => ['leader' => 'scheduled', 'follower' => 'closed', 'closed' => 'teacher_user', 'lessons' => ['cancelled'], 'available' => 0, 'ledger' => ['deposit', 'forfeiture', 'refund', 'reservation'], 'request' => 'open', 'student_erased' => false] + $base],
        // Webhook/recarga manual que se acredita antes o después del cierre: un solo depósito; el saldo tardío queda fuera de uso.
        'closure-then-pending-recharge-credit' => ['leader' => 'close-teacher', 'follower' => 'credit', 'options' => ['recharge' => true], 'expect' => ['leader' => 'closed', 'follower' => 'credited', 'closed' => 'teacher_user', 'available' => 5, 'ledger' => ['deposit', 'deposit', 'forfeiture'], 'request' => 'open', 'student_erased' => false, 'credited' => true] + $base],
        'pending-recharge-credit-then-closure' => ['leader' => 'credit', 'follower' => 'close-teacher', 'options' => ['recharge' => true], 'expect' => ['leader' => 'credited', 'follower' => 'closed', 'closed' => 'teacher_user', 'available' => 5, 'ledger' => ['deposit', 'deposit', 'forfeiture'], 'request' => 'open', 'student_erased' => false, 'credited' => true] + $base],
        // Liquidación del scheduler de una clase ya terminada: se conserva y se consume una sola vez; el padre cerrado no recibe avisos.
        'closure-then-settlement' => ['leader' => 'close', 'follower' => 'settle', 'options' => ['lesson' => true, 'real_notifications' => true], 'expect' => ['leader' => 'closed', 'follower' => 'settled', 'lessons' => ['completed'], 'available' => 9, 'ledger' => ['consumption', 'deposit', 'reservation'], 'request' => 'accepted', 'settled' => true, 'parent_notifications' => 0] + $base],
        'settlement-then-closure' => ['leader' => 'settle', 'follower' => 'close', 'options' => ['lesson' => true, 'real_notifications' => true], 'expect' => ['leader' => 'settled', 'follower' => 'closed', 'lessons' => ['completed'], 'available' => 9, 'ledger' => ['consumption', 'deposit', 'reservation'], 'request' => 'accepted', 'settled' => true] + $base],
        // Cancelación manual de la clase programada vs cierre: un solo reembolso, sin interbloqueo, en ambos órdenes.
        'closure-then-cancellation' => ['leader' => 'close', 'follower' => 'cancel', 'options' => ['lesson' => true, 'keep_scheduled' => true], 'expect' => ['leader' => 'closed', 'follower' => 'noop', 'lessons' => ['cancelled'], 'ledger' => ['deposit', 'refund', 'reservation'], 'request' => 'expired'] + $base],
        'cancellation-then-closure' => ['leader' => 'cancel', 'follower' => 'close', 'options' => ['lesson' => true, 'keep_scheduled' => true], 'expect' => ['leader' => 'cancelled', 'follower' => 'closed', 'lessons' => ['cancelled'], 'ledger' => ['deposit', 'refund', 'reservation'], 'request' => 'accepted'] + $base],
    ];
}

try {
    check(app()->environment('testing'), 'Requires APP_ENV=testing.');
    check(config('database.default') === 'mysql_qa', 'Requires DB_CONNECTION=mysql_qa.');
    QaDatabaseGuard::assertDatabase('mysql_qa', 'mova_qa');
    check(DB::connection()->getDriverName() === 'mysql', 'Requires MySQL.');
    check(DB::selectOne('SELECT DATABASE() AS db')->db === 'mova_qa', 'Actual database must be mova_qa.');
    DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
    config(['mail.default' => 'array', 'broadcasting.default' => 'null', 'payments.enabled' => false, 'session.driver' => 'array']);
    Event::fake([ClassConfirmed::class, ClassRequestCreated::class]);
    Http::preventStrayRequests();
    $isWorker = ($argv[1] ?? '') === '--worker';
    if (! $isWorker) {
        Notification::fake();
        Queue::fake();
    }

    if ($isWorker) {
        $directory = $argv[2];
        $position = $argv[3];
        $fixture = json_decode(file_get_contents($directory.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
        $operation = $position === 'leader' ? $fixture['leader'] : $fixture['follower'];
        if (! $fixture['real_notifications']) {
            Notification::fake();
            Queue::fake();
        } else {
            // Exercise the real NotificationSending listener, not a fake.
            config(['queue.default' => 'sync']);
        }
        $started = microtime(true);
        $perform = function () use ($operation, $fixture): string {
            if ($operation === 'close' || $operation === 'close-teacher') {
                $target = $operation === 'close' ? $fixture['parent'] : $fixture['teacher_user'];
                app(AccountLifecycleService::class)->closeAccount(User::findOrFail($target), 'password', null, true);

                return 'closed';
            }
            if ($operation === 'verify') {
                try {
                    app(\App\Http\Controllers\AdminController::class)->verifyTeacher(TeacherProfile::findOrFail($fixture['teacher']));

                    return 'verified';
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                    check($exception->getStatusCode() === 409, 'Unexpected verification rejection.');

                    return 'blocked';
                }
            }
            if ($operation === 'store') {
                Auth::setUser(User::findOrFail($fixture['parent']));
                $http = Request::create('/class-requests', 'POST', [
                    'student_id' => $fixture['student'], 'subject_id' => $fixture['subject'],
                    'help_needed' => 'QA synthetic concurrency booking',
                ]);
                $http->setLaravelSession(app('session')->driver());
                $http->setUserResolver(fn () => Auth::user());
                try {
                    app(\App\Http\Controllers\ClassRequestController::class)->store($http);

                    return 'stored';
                } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                    // El alumno ya fue anonimizado y retirado de la cuenta cerrada.
                    return 'blocked';
                } catch (ValidationException $exception) {
                    check(in_array(CLOSED_MESSAGE, array_merge(...array_values($exception->errors())), true), 'Unexpected booking validation.');

                    return 'blocked';
                }
            }
            if ($operation === 'credit') {
                $result = app(RechargeApprovalService::class)->credit(RechargeRequest::findOrFail($fixture['recharge']), $fixture['admin']);

                return $result['changed'] ? 'credited' : 'already-credited';
            }
            if ($operation === 'cancel') {
                // Same entry point as the route: the closed parent cancelling a class scheduled before closure.
                Auth::setUser(User::findOrFail($fixture['parent']));
                $http = Request::create('/lessons/'.$fixture['lesson'].'/cancel', 'POST', ['reason' => 'QA closed owner']);
                $http->setLaravelSession(app('session')->driver());
                $http->setUserResolver(fn () => Auth::user());
                app()->instance('request', $http);
                try {
                    app(\App\Http\Controllers\LessonController::class)->cancel(Lesson::findOrFail($fixture['lesson']));
                } catch (ValidationException|\Illuminate\Auth\Access\AuthorizationException $exception) {
                    // El cierre ya canceló la clase (o retiró el rol): no hay segundo reembolso.
                    check(! ($exception instanceof ValidationException) || isset($exception->errors()['cancel']), 'Unexpected cancellation validation.');

                    return 'noop';
                }

                return 'cancelled';
            }
            if ($operation === 'settle') {
                $lesson = app(LessonSettlementService::class)->consume(Lesson::findOrFail($fixture['lesson']), null, null, 'class_settled', true);

                return $lesson->status === 'completed' ? 'settled' : 'unexpected';
            }
            try {
                app(LessonSchedulingService::class)->schedule(
                    $fixture['request'], $fixture['teacher'], $fixture['start'], 60,
                    function (ClassRequest $request): void {
                        // Mismo efecto que el guard real de la política: una solicitud ya cerrada no se puede aceptar.
                        if ($request->status !== 'open') {
                            throw ValidationException::withMessages(['accept' => CLOSED_MESSAGE]);
                        }
                    }
                );

                return 'scheduled';
            } catch (ValidationException $exception) {
                // Only the account-closure guard is an expected race rejection.
                check(($exception->errors()['accept'][0] ?? '') === CLOSED_MESSAGE, 'Unexpected scheduling validation.');

                return 'blocked';
            }
        };
        if ($position === 'leader') {
            $result = DB::transaction(function () use ($perform, $directory): string {
                $result = $perform();
                // Service work is complete but its locks are still held by the
                // outer transaction. The second process must wait on real locks.
                touch($directory.'/leader-ready');
                barrier($directory.'/follower-started');
                usleep(750000);

                return $result;
            });
        } else {
            barrier($directory.'/leader-ready');
            touch($directory.'/follower-started');
            $started = microtime(true);
            $result = $perform();
        }
        echo json_encode(['result' => $result, 'elapsed_ms' => round((microtime(true) - $started) * 1000)], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }

    $only = $argv[1] ?? null;
    foreach (scenarios() as $scenario => $definition) {
        if ($only !== null && $only !== $scenario) {
            continue;
        }
        $expect = $definition['expect'];
        $run = (string) Str::uuid();
        $fixture = buildFixture($run, $definition['leader'], $definition['follower'], $definition['options']);
        $directory = sys_get_temp_dir().'/mova-closure-race-'.$run;
        check(mkdir($directory, 0700), 'Cannot create worker directory.');
        file_put_contents($directory.'/fixture.json', json_encode($fixture, JSON_THROW_ON_ERROR));
        $leader = new Process([PHP_BINARY, __FILE__, '--worker', $directory, 'leader'], dirname(__DIR__), timeout: 30);
        $follower = new Process([PHP_BINARY, __FILE__, '--worker', $directory, 'follower'], dirname(__DIR__), timeout: 30);
        try {
            $leader->start();
            barrier($directory.'/leader-ready');
            $follower->start();
            $leader->wait();
            $follower->wait();
            check($leader->isSuccessful() && $follower->isSuccessful(), 'Worker failed in '.$scenario.' (inspect scenario fixtures; no provider calls were made): '.substr(trim($leader->getErrorOutput().' | '.$follower->getErrorOutput()), 0, 400));
            $leaderResult = json_decode(trim($leader->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $followerResult = json_decode(trim($follower->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            check($followerResult['elapsed_ms'] >= 500, 'Follower did not demonstrate waiting for the held lock in '.$scenario.'.');
            check($leaderResult['result'] === $expect['leader'], 'Leader result invalid in '.$scenario.': '.$leaderResult['result']);
            check($followerResult['result'] === $expect['follower'], 'Follower result invalid in '.$scenario.': '.$followerResult['result']);

            // Una sola solicitud completada y exactamente dos eventos (requested, erased) para la identidad cerrada.
            $closedUser = $fixture[$expect['closed']];
            check(AccountDeletionRequest::where('user_id', $closedUser)->count() === 1, 'Expected exactly one closure request.');
            $deletion = AccountDeletionRequest::where('user_id', $closedUser)->sole();
            check($deletion->status === 'completed' && array_column($deletion->events, 'action') === ['requested', 'erased'], 'Closure must complete once.');
            $erased = User::findOrFail($closedUser);
            check($erased->account_closed_at !== null && $erased->account_erased_at !== null, 'Account must be closed and erased.');
            check($erased->email === "deleted-{$closedUser}@mova.invalid" && $erased->phone === null && $erased->getRoleNames()->isEmpty(), 'Identity must be released.');
            $otherUser = $expect['closed'] === 'parent' ? $fixture['teacher_user'] : $fixture['parent'];
            check(User::findOrFail($otherUser)->account_closed_at === null, 'Counterparty must not be closed.');
            check(AccountDeletionRequest::where('user_id', $otherUser)->count() === 0, 'Counterparty must have no closure request.');

            // Alumno: anonimizado solo si cierra el padre. Las clases siguen vinculadas a sus participantes.
            $student = Student::withTrashed()->findOrFail($fixture['student']);
            check(($student->first_name === 'Estudiante') === $expect['student_erased'], 'Student anonymization mismatch.');
            check($student->parent_user_id === $fixture['parent'], 'Student must stay linked to its original parent row.');
            $lessons = Lesson::where('class_request_id', $fixture['request'])->orderBy('id')->get();
            check($lessons->pluck('status')->sort()->values()->all() === $expect['lessons'], 'Unexpected class states: '.json_encode($lessons->pluck('status')));
            foreach ($lessons as $lesson) {
                check($lesson->student_id === $fixture['student'] && $lesson->teacher_profile_id === $fixture['teacher'], 'Class participants changed.');
            }
            check(ClassRequest::where('student_id', $fixture['student'])->count() === $expect['requests'], 'Unexpected request history.');
            check(ClassRequest::findOrFail($fixture['request'])->status === $expect['request'], 'Unexpected request status: '.ClassRequest::findOrFail($fixture['request'])->status);

            // Saldo y ledger: solo asientos legítimos (nunca un UPDATE a cero) y balance derivado == balance real.
            $profile = TeacherProfile::findOrFail($fixture['teacher']);
            check($profile->credits_available === $expect['available'] && $profile->credits_reserved === $expect['reserved'], 'Credit balances diverged: '.$profile->credits_available.'/'.$profile->credits_reserved);
            check(CreditTransaction::where('teacher_profile_id', $profile->id)->pluck('type')->sort()->values()->all() === $expect['ledger'], 'Unexpected ledger: '.json_encode(CreditTransaction::where('teacher_profile_id', $profile->id)->pluck('type')->sort()->values()));
            check(app(\App\Support\LedgerReconciliation::class)->derivedAvailableFor($profile->id) === $profile->credits_available, 'Ledger derivation diverged from the balance.');
            check(CreditTransaction::where('type', 'forfeiture')->whereIn('teacher_profile_id', [$profile->id])->count() <= 1, 'Forfeiture must be single.');
            check(CreditTransaction::where('teacher_profile_id', $profile->id)->where('type', 'refund')->count() <= 1, 'Refund must be single.');
            if ($expect['credited'] ?? false) {
                check(RechargeRequest::findOrFail($fixture['recharge'])->status === 'approved', 'Recharge must complete once.');
                check(CreditTransaction::where('idempotency_key', 'recharge:'.$fixture['recharge'].':deposit')->count() === 1, 'Recharge deposit must be single.');
            }
            if ($expect['settled'] ?? false) {
                $lessonRow = Lesson::findOrFail($fixture['lesson']);
                check($lessonRow->status === 'completed' && $lessonRow->credits_settled_at !== null, 'Settlement must finish after closure.');
                check(CreditTransaction::where('lesson_id', $fixture['lesson'])->where('type', 'consumption')->count() === 1, 'Consumption must be single.');
            }
            if (array_key_exists('verified', $expect)) {
                check((bool) $profile->is_verified === $expect['verified'], 'Closed teacher must not remain published.');
                check(! $profile->classOffers()->where('is_active', true)->exists(), 'Closed teacher offers must stay inactive.');
            }
            if (array_key_exists('parent_notifications', $expect) && $definition['leader'] === 'close') {
                check(DB::table('notifications')->where('notifiable_id', $fixture['parent'])->count() === $expect['parent_notifications'], 'Closed account received a routine notification.');
            }
            echo json_encode(['scenario' => $scenario, 'passed' => true, 'leader' => $leaderResult['result'], 'follower' => $followerResult['result'], 'follower_wait_ms' => $followerResult['elapsed_ms'], 'lessons' => $lessons->pluck('status')->all(), 'available' => $profile->credits_available], JSON_THROW_ON_ERROR).PHP_EOL;
        } finally {
            $leader->stop(1);
            $follower->stop(1);
            foreach (['fixture.json', 'leader-ready', 'follower-started'] as $file) {
                if (is_file($directory.'/'.$file)) {
                    unlink($directory.'/'.$file);
                }
            }
            rmdir($directory);
        }
    }
} catch (Throwable $exception) {
    // Avoid exposing SQL bindings, connection strings or credentials in failures.
    fwrite(STDERR, 'Account closure race failed: '.get_class($exception).PHP_EOL);
    if ($exception instanceof RuntimeException && ! $exception instanceof \Illuminate\Database\QueryException) {
        fwrite(STDERR, $exception->getMessage().PHP_EOL);
    }
    exit(1);
}
