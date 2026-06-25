<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ClassOfferController;
use App\Http\Controllers\ClassRequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ParentSettingsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentInvitationController;
use App\Http\Controllers\TeacherInvitationController;
use App\Http\Controllers\TeacherProfileController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

// ── Health check (no session, no auth) ──────────────────────────────────────
Route::get('/healthz', fn () => response('OK', 200));

// ── Public pages ────────────────────────────────────────────────────────────
Route::get('/', [WelcomeController::class, 'index'])->name('welcome');
Route::get('/quienes-somos', [AboutController::class, 'index'])->name('about');
Route::get('/invitacion/profesor', [TeacherInvitationController::class, 'index'])->name('landing.teacher');
Route::get('/invitacion/alumno', [StudentInvitationController::class, 'index'])->name('landing.student');
Route::get('/marketplace', [MarketplaceController::class, 'index'])->name('marketplace');

// ── Authenticated routes ─────────────────────────────────────────────────────
Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');

    // ── Parent ──────────────────────────────────────────────────────────────
    Route::middleware('role:parent')->group(function () {
        Route::resource('students', StudentController::class);
        Route::get('/class-requests', [ClassRequestController::class, 'index'])->name('class-requests.index');
        Route::get('/class-requests/create', [ClassRequestController::class, 'create'])->name('class-requests.create');
        Route::post('/class-requests', [ClassRequestController::class, 'store'])->name('class-requests.store');
        Route::post('/class-requests/{classRequest}/approve', [ClassRequestController::class, 'approve'])->name('class-requests.approve');
        Route::post('/class-requests/{classRequest}/reject', [ClassRequestController::class, 'reject'])->name('class-requests.reject');
        Route::get('/my-classes', [LessonController::class, 'parentIndex'])->name('parent.lessons');
        Route::patch('/settings/parental-control', [ParentSettingsController::class, 'update'])->name('parent.settings.update');
    });

    // ── Teacher ─────────────────────────────────────────────────────────────
    Route::middleware('role:teacher')->group(function () {
        Route::get('/teacher/setup', [TeacherProfileController::class, 'setup'])->name('teacher.setup');
        Route::post('/teacher/setup', [TeacherProfileController::class, 'storeSetup'])->name('teacher.setup.store');
        Route::get('/teacher/profile', [TeacherProfileController::class, 'edit'])->name('teacher.profile');
        Route::patch('/teacher/profile', [TeacherProfileController::class, 'update'])->name('teacher.profile.update');

        Route::resource('class-offers', ClassOfferController::class)->except(['show']);
        Route::post('/class-offers/{classOffer}/toggle', [ClassOfferController::class, 'toggleActive'])->name('class-offers.toggle');

        Route::get('/teacher/requests', [ClassRequestController::class, 'teacherIndex'])->name('teacher.requests');
        Route::get('/teacher/requests/{classRequest}/accept', [ClassRequestController::class, 'accept'])->name('teacher.requests.accept');
        Route::post('/lessons', [LessonController::class, 'store'])->name('lessons.store');
        Route::get('/teacher/classes', [LessonController::class, 'teacherIndex'])->name('teacher.lessons');
        Route::post('/lessons/{lesson}/cancel', [LessonController::class, 'cancel'])->name('lessons.cancel');
    });

    // ── Admin ────────────────────────────────────────────────────────────────
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/users', [AdminController::class, 'users'])->name('admin.users');
        Route::get('/pending-teachers', [AdminController::class, 'pendingTeachers'])->name('admin.teachers.pending');
        Route::post('/teachers/{teacher}/verify', [AdminController::class, 'verifyTeacher'])->name('admin.teachers.verify');
        Route::delete('/teachers/{teacher}/reject', [AdminController::class, 'rejectTeacher'])->name('admin.teachers.reject');
    });
});

require __DIR__ . '/auth.php';
