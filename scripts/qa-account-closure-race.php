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

/** @return array<string,array{leader:string,follower:string,options:array,expect:array}> */
function scenarios(): array
{
    return [
        // Existing coverage kept: duplicate closure and closure vs lesson scheduling.
        'duplicate-closure' => ['leader' => 'close', 'follower' => 'close', 'options' => [], 'expect' => ['leader' => 'closed', 'follower' => 'closed', 'closed' => 'parent', 'classes' => 0]],
        'closure-wins' => ['leader' => 'close', 'follower' => 'schedule', 'options' => [], 'expect' => ['leader' => 'closed', 'follower' => 'blocked', 'closed' => 'parent', 'classes' => 0]],
        'scheduling-wins' => ['leader' => 'schedule', 'follower' => 'close', 'options' => [], 'expect' => ['leader' => 'scheduled', 'follower' => 'closed', 'closed' => 'parent', 'classes' => 1]],
        // A) parent closes while a new class request is created.
        'closure-vs-new-request' => ['leader' => 'close', 'follower' => 'store', 'options' => [], 'expect' => ['leader' => 'closed', 'follower' => 'blocked', 'closed' => 'parent', 'classes' => 0, 'requests' => 1]],
        'new-request-vs-closure' => ['leader' => 'store', 'follower' => 'close', 'options' => [], 'expect' => ['leader' => 'stored', 'follower' => 'closed', 'closed' => 'parent', 'classes' => 0, 'requests' => 2]],
        // B) teacher closes while admin approval arrives (both orders).
        'teacher-closure-vs-admin-approval' => ['leader' => 'close-teacher', 'follower' => 'verify', 'options' => ['teacher_verified' => false], 'expect' => ['leader' => 'closed', 'follower' => 'blocked', 'closed' => 'teacher_user', 'classes' => 0, 'verified' => false]],
        'admin-approval-vs-teacher-closure' => ['leader' => 'verify', 'follower' => 'close-teacher', 'options' => ['teacher_verified' => false], 'expect' => ['leader' => 'verified', 'follower' => 'closed', 'closed' => 'teacher_user', 'classes' => 0, 'verified' => false]],
        // C/E) pending financial operation at closure, completed afterwards by its own legitimate process.
        'closure-then-pending-recharge-credit' => ['leader' => 'close-teacher', 'follower' => 'credit', 'options' => ['recharge' => true], 'expect' => ['leader' => 'closed', 'follower' => 'credited', 'closed' => 'teacher_user', 'classes' => 0, 'credited' => true]],
        'pending-recharge-credit-then-closure' => ['leader' => 'credit', 'follower' => 'close-teacher', 'options' => ['recharge' => true], 'expect' => ['leader' => 'credited', 'follower' => 'closed', 'closed' => 'teacher_user', 'classes' => 0, 'credited' => true]],
        // E/F/G) settlement (scheduler path) of a class created before closure, with real notification delivery.
        'closure-then-settlement' => ['leader' => 'close', 'follower' => 'settle', 'options' => ['lesson' => true, 'real_notifications' => true], 'expect' => ['leader' => 'closed', 'follower' => 'settled', 'closed' => 'parent', 'classes' => 1, 'settled' => true, 'parent_notifications' => 0]],
        // E) a class scheduled before closure is cancelled afterwards by its (closed) owner: one refund, in both orders.
        'closure-then-cancellation' => ['leader' => 'close', 'follower' => 'cancel', 'options' => ['lesson' => true, 'keep_scheduled' => true], 'expect' => ['leader' => 'closed', 'follower' => 'cancelled', 'closed' => 'parent', 'classes' => 1, 'cancelled' => true]],
        'cancellation-then-closure' => ['leader' => 'cancel', 'follower' => 'close', 'options' => ['lesson' => true, 'keep_scheduled' => true], 'expect' => ['leader' => 'cancelled', 'follower' => 'closed', 'closed' => 'parent', 'classes' => 1, 'cancelled' => true]],
        'settlement-then-closure' => ['leader' => 'settle', 'follower' => 'close', 'options' => ['lesson' => true, 'real_notifications' => true], 'expect' => ['leader' => 'settled', 'follower' => 'closed', 'closed' => 'parent', 'classes' => 1, 'settled' => true]],
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
                app(AccountLifecycleService::class)->requestClosure(User::findOrFail($target), 'password');

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
                app(\App\Http\Controllers\LessonController::class)->cancel(Lesson::findOrFail($fixture['lesson']));

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
                        check($request->status === 'open', 'Unexpected scheduling request state.');
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
        $ledgerBefore = CreditTransaction::where('teacher_profile_id', $fixture['teacher'])->count();
        $studentBefore = Student::findOrFail($fixture['student'])->only(['first_name', 'last_name', 'parent_user_id']);
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
            check($leader->isSuccessful() && $follower->isSuccessful(), 'Worker failed in '.$scenario.' (inspect scenario fixtures; no provider calls were made).');
            $leaderResult = json_decode(trim($leader->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $followerResult = json_decode(trim($follower->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            check($followerResult['elapsed_ms'] >= 500, 'Follower did not demonstrate waiting for the held lock in '.$scenario.'.');
            check($leaderResult['result'] === $expect['leader'], 'Leader result invalid in '.$scenario.': '.$leaderResult['result']);
            check($followerResult['result'] === $expect['follower'], 'Follower result invalid in '.$scenario.': '.$followerResult['result']);

            // Exactly one closure request and one request event for the closed identity.
            $closedUser = $fixture[$expect['closed']];
            check(AccountDeletionRequest::where('user_id', $closedUser)->count() === 1, 'Expected exactly one closure request.');
            $deletion = AccountDeletionRequest::where('user_id', $closedUser)->sole();
            check(count($deletion->events) === 1 && $deletion->events[0]['action'] === 'requested', 'Expected exactly one request event.');
            check(User::findOrFail($closedUser)->account_closed_at !== null, 'Account must be closed.');
            $otherUser = $expect['closed'] === 'parent' ? $fixture['teacher_user'] : $fixture['parent'];
            check(User::findOrFail($otherUser)->account_closed_at === null, 'Counterparty must not be closed.');
            check(AccountDeletionRequest::where('user_id', $otherUser)->count() === 0, 'Counterparty must have no closure request.');

            // H) Students, classes and their links are preserved, never cancelled or reassigned.
            check(Student::findOrFail($fixture['student'])->only(['first_name', 'last_name', 'parent_user_id']) === $studentBefore, 'Student data must be preserved.');
            $lessons = Lesson::where('class_request_id', $fixture['request'])->get();
            check($lessons->count() === $expect['classes'], 'Unexpected number of retained classes.');
            foreach ($lessons as $lesson) {
                check($lesson->student_id === $fixture['student'] && $lesson->teacher_profile_id === $fixture['teacher'], 'Class participants changed.');
                // Closure never cancels a class; only the owner's explicit cancellation does.
                check(($lesson->status === 'cancelled') === ($expect['cancelled'] ?? false), 'Unexpected class cancellation state.');
            }
            check(ClassRequest::where('student_id', $fixture['student'])->count() === ($expect['requests'] ?? 1), 'Unexpected request history.');
            $profile = TeacherProfile::findOrFail($fixture['teacher']);
            $credited = $expect['credited'] ?? false;
            $settled = $expect['settled'] ?? false;
            $classes = $expect['classes'];
            // Credits and ledger only move through their own legitimate operation, never because of closure.
            $cancelled = $expect['cancelled'] ?? false;
            $expectedAvailable = ($cancelled ? 10 : 10 - $classes) + ($credited ? 5 : 0);
            $expectedReserved = ($settled || $cancelled) ? 0 : $classes;
            check($profile->credits_available === $expectedAvailable && $profile->credits_reserved === $expectedReserved, 'Credit balances diverged.');
            // A settled class had its reservation written during fixture setup (already in $ledgerBefore);
            // otherwise a class scheduled by the race adds one reservation. Settlement adds one consumption.
            $expectedLedger = $ledgerBefore + (($settled || $cancelled) ? 1 : $classes) + ($credited ? 1 : 0);
            if ($cancelled) {
                check(CreditTransaction::where('lesson_id', $fixture['lesson'])->where('type', 'refund')->count() === 1, 'Refund must be single.');
            }
            check(CreditTransaction::where('teacher_profile_id', $profile->id)->count() === $expectedLedger, 'Unexpected ledger mutation.');
            if ($credited) {
                check(RechargeRequest::findOrFail($fixture['recharge'])->status === 'approved', 'Recharge must complete once.');
                check(CreditTransaction::where('idempotency_key', 'recharge:'.$fixture['recharge'].':deposit')->count() === 1, 'Recharge deposit must be single.');
            }
            if ($settled) {
                $lessonRow = Lesson::findOrFail($fixture['lesson']);
                check($lessonRow->status === 'completed' && $lessonRow->credits_settled_at !== null, 'Settlement must finish after closure.');
                check(CreditTransaction::where('lesson_id', $fixture['lesson'])->where('type', 'consumption')->count() === 1, 'Consumption must be single.');
            }
            if (array_key_exists('verified', $expect)) {
                check($profile->is_verified === $expect['verified'], 'Closed teacher must not remain published.');
                check(! $profile->classOffers()->where('is_active', true)->exists(), 'Closed teacher offers must stay inactive.');
            }
            if (array_key_exists('parent_notifications', $expect) && $definition['leader'] === 'close') {
                check(DB::table('notifications')->where('notifiable_id', $fixture['parent'])->count() === $expect['parent_notifications'], 'Closed account received a routine notification.');
            }
            echo json_encode(['scenario' => $scenario, 'passed' => true, 'leader' => $leaderResult['result'], 'follower' => $followerResult['result'], 'follower_wait_ms' => $followerResult['elapsed_ms'], 'retained_classes' => $classes], JSON_THROW_ON_ERROR).PHP_EOL;
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
