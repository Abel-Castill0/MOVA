<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\DiagnosticsController;
use App\Http\Controllers\ClassOfferController;
use App\Http\Controllers\ClassRequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ParentSettingsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentInvitationController;
use App\Http\Controllers\TeacherInvitationController;
use App\Http\Controllers\TeacherProfileController;
use App\Http\Controllers\LessonReportController;
use App\Http\Controllers\AiUsageController;
use App\Http\Controllers\Admin\RechargeController;
use App\Http\Controllers\TeacherPublicController;
use App\Http\Controllers\TeacherReviewController;
use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\Teacher\CreditController;
use Illuminate\Support\Facades\Route;

// ── Health check (no session, no auth) ──────────────────────────────────────
Route::get('/healthz', fn () => response('OK', 200));

// ── Legal pages (public, no auth required) ───────────────────────────────────
Route::get('/terminos', [LegalController::class, 'terms'])->name('legal.terms');
Route::get('/privacidad', [LegalController::class, 'privacy'])->name('legal.privacy');

// ── Public pages ────────────────────────────────────────────────────────────
Route::get('/', [WelcomeController::class, 'index'])->name('welcome');
Route::get('/quienes-somos', [AboutController::class, 'index'])->name('about');
Route::get('/invitacion/profesor', [TeacherInvitationController::class, 'index'])->name('landing.teacher');
Route::get('/invitacion/alumno', [StudentInvitationController::class, 'index'])->name('landing.student');
Route::get('/marketplace', [MarketplaceController::class, 'index'])->name('marketplace');
Route::get('/teachers/{teacherProfile}', [TeacherPublicController::class, 'show'])->name('teachers.show');

// ── Suspended account page (auth only, no suspension check) ──────────────────
Route::middleware('auth')->get('/suspended', fn () => \Inertia\Inertia::render('Suspended'))->name('suspended');

// ── Authenticated routes ─────────────────────────────────────────────────────
Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->middleware('throttle:10,1')->name('profile.avatar');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');

    // ── Parent ──────────────────────────────────────────────────────────────
    Route::middleware(['role:parent', 'not.suspended'])->group(function () {
        Route::get('/diagnostics/create', [DiagnosticsController::class, 'create'])->name('diagnostics.create');
        Route::post('/diagnostics', [DiagnosticsController::class, 'store'])->middleware('throttle:5,1')->name('diagnostics.store');
        Route::get('/diagnostics/{diagnostic}/results', [DiagnosticsController::class, 'results'])->name('diagnostics.results');
        Route::post('/diagnostics/{diagnostic}/request/{classOffer}', [DiagnosticsController::class, 'requestClass'])->name('diagnostics.request');

        Route::resource('students', StudentController::class)->except(['show']);
        Route::get('/class-requests', [ClassRequestController::class, 'index'])->name('class-requests.index');
        Route::get('/class-requests/create', [ClassRequestController::class, 'create'])->name('class-requests.create');
        Route::post('/class-requests', [ClassRequestController::class, 'store'])->middleware('throttle:10,1')->name('class-requests.store');
        Route::post('/class-requests/{classRequest}/approve', [ClassRequestController::class, 'approve'])->name('class-requests.approve');
        Route::post('/class-requests/{classRequest}/reject', [ClassRequestController::class, 'reject'])->name('class-requests.reject');
        Route::get('/my-classes', [LessonController::class, 'parentIndex'])->name('parent.lessons');
        Route::post('/lessons/{lesson}/confirm-payment', [LessonController::class, 'confirmPayment'])->middleware('throttle:10,1')->name('lessons.confirm-payment');
        Route::get('/lessons/{lesson}/review/create', [TeacherReviewController::class, 'create'])->name('reviews.create');
        Route::post('/lessons/{lesson}/review', [TeacherReviewController::class, 'store'])->middleware('throttle:10,1')->name('reviews.store');
        Route::get('/my-reports', [LessonReportController::class, 'parentIndex'])->name('parent.reports');
        Route::patch('/settings/parental-control', [ParentSettingsController::class, 'update'])->name('parent.settings.update');
    });

    // ── Teacher ─────────────────────────────────────────────────────────────
    Route::middleware(['role:teacher', 'not.suspended'])->group(function () {
        Route::get('/teacher/setup', [TeacherProfileController::class, 'setup'])->name('teacher.setup');
        Route::post('/teacher/setup', [TeacherProfileController::class, 'storeSetup'])->name('teacher.setup.store');
        Route::get('/teacher/profile', [TeacherProfileController::class, 'edit'])->name('teacher.profile');
        Route::patch('/teacher/profile', [TeacherProfileController::class, 'update'])->name('teacher.profile.update');
        Route::get('/teacher/credits', [CreditController::class, 'index'])->name('teacher.credits.index');
        Route::post('/teacher/credits/recharge', [CreditController::class, 'storeRecharge'])->middleware('throttle:10,1')->name('teacher.credits.recharge');

        Route::resource('class-offers', ClassOfferController::class)->except(['show']);
        Route::post('/class-offers/{classOffer}/toggle', [ClassOfferController::class, 'toggleActive'])->name('class-offers.toggle');

        Route::get('/teacher/requests', [ClassRequestController::class, 'teacherIndex'])->name('teacher.requests');
        Route::get('/teacher/requests/{classRequest}/accept', [ClassRequestController::class, 'accept'])->name('teacher.requests.accept');
        Route::post('/teacher/requests/{classRequest}/reject', [ClassRequestController::class, 'teacherReject'])->name('teacher.requests.reject');
        Route::post('/lessons', [LessonController::class, 'store'])->middleware('throttle:10,1')->name('lessons.store');
        Route::get('/teacher/classes', [LessonController::class, 'teacherIndex'])->name('teacher.lessons');
        Route::get('/teacher/reports', [LessonReportController::class, 'teacherIndex'])->name('teacher.reports');
        Route::get('/lessons/{lesson}/report/create', [LessonReportController::class, 'create'])->name('lesson-reports.create');
        Route::post('/lessons/{lesson}/report', [LessonReportController::class, 'store'])->name('lesson-reports.store');
        Route::get('/lessons/{lesson}/report', [LessonReportController::class, 'show'])->name('lesson-reports.show');
    });

    // ── Shared: cancel, reschedule & join (parent, teacher, admin) ────────────
    Route::middleware('not.suspended')->group(function () {
        Route::post('/lessons/{lesson}/cancel', [LessonController::class, 'cancel'])->middleware('throttle:10,1')->name('lessons.cancel');
        Route::post('/lessons/{lesson}/reschedule', [LessonController::class, 'reschedule'])->name('lessons.reschedule');
        Route::get('/lessons/{lesson}/join', [LessonController::class, 'join'])->name('lessons.join');
    });

    // ── Admin ────────────────────────────────────────────────────────────────
    // Deliberadamente SIN 'not.suspended': a diferencia de parent/teacher, un admin
    // suspendido debe conservar acceso. Si se le aplicara la misma restricción, un
    // admin suspendido (por error o por otro admin) quedaría sin forma de
    // revertir su propia suspensión, dejando la plataforma sin administrador activo.
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/users', [AdminController::class, 'users'])->name('admin.users');
        Route::get('/pending-teachers', [AdminController::class, 'pendingTeachers'])->name('admin.teachers.pending');
        Route::post('/teachers/{teacher}/verify', [AdminController::class, 'verifyTeacher'])->name('admin.teachers.verify');
        Route::post('/teachers/{teacher}/reject', [AdminController::class, 'rejectTeacher'])->name('admin.teachers.reject');
        Route::post('/users/{user}/suspend', [AdminController::class, 'suspendUser'])->name('admin.users.suspend');
        Route::post('/users/{user}/unsuspend', [AdminController::class, 'unsuspendUser'])->name('admin.users.unsuspend');
        Route::get('/requests', [AdminController::class, 'requests'])->name('admin.requests');
        Route::get('/lessons', [AdminController::class, 'lessons'])->name('admin.lessons');
        Route::post('/lessons/{lesson}/cancel', [AdminController::class, 'cancelLesson'])->middleware('throttle:10,1')->name('admin.lessons.cancel');
        Route::get('/recharges', [RechargeController::class, 'index'])->name('admin.recharges.index');
        Route::post('/recharges/{recharge}/approve', [RechargeController::class, 'approve'])->middleware('throttle:10,1')->name('admin.recharges.approve');
        Route::post('/recharges/{recharge}/reject', [RechargeController::class, 'reject'])->name('admin.recharges.reject');
        Route::get('/reviews', [TeacherReviewController::class, 'adminIndex'])->name('admin.reviews');
        Route::post('/reviews/{review}/hide', [TeacherReviewController::class, 'hide'])->name('admin.reviews.hide');
        Route::post('/reviews/{review}/show', [TeacherReviewController::class, 'showReview'])->name('admin.reviews.show');
        Route::get('/ai-usage', [AiUsageController::class, 'index'])->name('admin.ai-usage');
    });
});

require __DIR__ . '/auth.php';
