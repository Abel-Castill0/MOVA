<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\NotificationPreferencesController;
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
use App\Http\Controllers\Admin\MfaController as AdminMfaController;
use App\Http\Controllers\Admin\OperationsController;
use App\Http\Controllers\Admin\RechargeController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TeacherPublicController;
use App\Http\Controllers\TeacherReviewController;
use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\Teacher\CreditController;
use App\Http\Controllers\Teacher\CreditCheckoutController;
use Illuminate\Support\Facades\Route;

// ── Health check (no session, no auth) ──────────────────────────────────────
Route::get('/healthz', fn () => response('OK', 200));

// ── SEO: sitemap + robots dinámicos ──────────────────────────────────────────
// robots.txt vivía como archivo estático en public/ sin referenciar ningún
// sitemap (que tampoco existía). Ambos ahora se generan a partir de APP_URL
// en runtime — si el dominio cambia (dominio propio en vez del subdominio de
// Railway), ninguno de los dos queda desactualizado.
//
// AZ-3F: con seo.indexing_enabled=false (default fuera del cutover a dominio
// final) robots.txt bloquea todo el sitio y no anuncia el sitemap del
// hostname temporal — sea cual sea ese hostname, sin hardcodearlo aquí.
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/robots.txt', function () {
    if (! config('seo.indexing_enabled')) {
        return response("User-agent: *\nDisallow: /\n")
            ->header('Content-Type', 'text/plain');
    }

    return response("User-agent: *\nDisallow:\n\nSitemap: ".route('sitemap')."\n")
        ->header('Content-Type', 'text/plain');
})->name('robots');

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
// P0-C — MFA admin: enrolamiento y challenge quedan FUERA de admin.mfa
// (son el camino para satisfacerlo); todo lo demás autenticado pasa por él.
Route::middleware(['auth', 'role:admin'])->prefix('admin/mfa')->name('admin.mfa.')->group(function () {
    Route::get('/setup', [AdminMfaController::class, 'setup'])->name('setup');
    Route::post('/setup', [AdminMfaController::class, 'confirm'])->middleware('throttle:10,1')->name('confirm');
    Route::get('/challenge', [AdminMfaController::class, 'challenge'])->name('challenge');
    Route::post('/challenge', [AdminMfaController::class, 'verify'])->middleware('throttle:10,1')->name('verify');
    Route::get('/recovery-codes', [AdminMfaController::class, 'recoveryCodes'])->middleware('admin.mfa')->name('recovery-codes');
    Route::post('/recovery-codes', [AdminMfaController::class, 'regenerateRecoveryCodes'])->middleware(['admin.mfa:sensitive', 'throttle:5,1'])->name('recovery-codes.regenerate');
});

Route::middleware(['auth', 'verified', 'admin.mfa'])->group(function () {

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('throttle:20,1')->name('profile.update');

    // Consentimiento de notificaciones por WhatsApp. Fuera del grupo por rol:
    // padres, profesores y admins reciben avisos y todos deben poder darse de
    // baja. No afecta al OTP de verificación.
    Route::patch('/profile/notifications', [NotificationPreferencesController::class, 'update'])
        ->middleware('throttle:20,1')->name('profile.notifications.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->middleware('throttle:10,1')->name('profile.destroy');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->middleware('throttle:10,1')->name('profile.avatar');
    Route::delete('/profile/avatar', [ProfileController::class, 'removeAvatar'])->middleware('throttle:10,1')->name('profile.avatar.remove');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->middleware('throttle:60,1')->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->middleware('throttle:20,1')->name('notifications.readAll');

    // ── Parent ──────────────────────────────────────────────────────────────
    Route::middleware(['role:parent', 'not.suspended'])->group(function () {
        Route::get('/diagnostics/create', [DiagnosticsController::class, 'create'])->name('diagnostics.create');
        Route::post('/diagnostics', [DiagnosticsController::class, 'store'])->middleware('throttle:5,1')->name('diagnostics.store');
        Route::get('/diagnostics/{diagnostic}/results', [DiagnosticsController::class, 'results'])->name('diagnostics.results');
        Route::post('/diagnostics/{diagnostic}/request/{classOffer}', [DiagnosticsController::class, 'requestClass'])->middleware('throttle:20,1')->name('diagnostics.request');

        Route::resource('students', StudentController::class)->except(['show'])->middleware('throttle:20,1');
        Route::get('/class-requests', [ClassRequestController::class, 'index'])->name('class-requests.index');
        Route::get('/class-requests/create', [ClassRequestController::class, 'create'])->name('class-requests.create');
        Route::get('/class-requests/lookup-code', [ClassRequestController::class, 'lookupTeacherByCode'])->middleware('throttle:30,1')->name('class-requests.lookup-code');
        Route::post('/class-requests', [ClassRequestController::class, 'store'])->middleware('throttle:10,1')->name('class-requests.store');
        Route::post('/class-requests/{classRequest}/approve', [ClassRequestController::class, 'approve'])->middleware('throttle:20,1')->name('class-requests.approve');
        Route::post('/class-requests/{classRequest}/reject', [ClassRequestController::class, 'reject'])->middleware('throttle:20,1')->name('class-requests.reject');
        Route::get('/my-classes', [LessonController::class, 'parentIndex'])->name('parent.lessons');
        Route::post('/lessons/{lesson}/confirm-payment', [LessonController::class, 'confirmPayment'])->middleware('throttle:10,1')->name('lessons.confirm-payment');
        Route::get('/lessons/{lesson}/review/create', [TeacherReviewController::class, 'create'])->name('reviews.create');
        Route::post('/lessons/{lesson}/review', [TeacherReviewController::class, 'store'])->middleware('throttle:10,1')->name('reviews.store');
        Route::get('/my-reports', [LessonReportController::class, 'parentIndex'])->name('parent.reports');
        Route::patch('/settings/parental-control', [ParentSettingsController::class, 'update'])->middleware('throttle:20,1')->name('parent.settings.update');
    });

    // ── Teacher ─────────────────────────────────────────────────────────────
    Route::middleware(['role:teacher', 'not.suspended'])->group(function () {
        Route::get('/teacher/setup', [TeacherProfileController::class, 'setup'])->name('teacher.setup');
        Route::post('/teacher/setup', [TeacherProfileController::class, 'storeSetup'])->middleware('throttle:10,1')->name('teacher.setup.store');
        Route::get('/teacher/profile', [TeacherProfileController::class, 'edit'])->name('teacher.profile');
        Route::patch('/teacher/profile', [TeacherProfileController::class, 'update'])->middleware('throttle:20,1')->name('teacher.profile.update');
        Route::get('/teacher/credits', [CreditController::class, 'index'])->name('teacher.credits.index');
        Route::post('/teacher/credits/recharge', [CreditController::class, 'storeRecharge'])->middleware('throttle:10,1')->name('teacher.credits.recharge');

        // Checkout automático (Mercado Pago Payments API — Yape). Vive
        // aparte de /teacher/credits/recharge (flujo manual con revisión de
        // admin): ambos crean RechargeRequest, pero solo este camino tiene
        // PaymentOrder asociados y acredita sin intervención humana (ver
        // App\Http\Controllers\Teacher\CreditCheckoutController).
        Route::post('/teacher/credits/checkout', [CreditCheckoutController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('teacher.credits.checkout.store');
        Route::get('/teacher/credits/checkout/{recharge}', [CreditCheckoutController::class, 'show'])
            ->name('teacher.credits.checkout.show');
        Route::post('/teacher/credits/checkout/{recharge}/pay', [CreditCheckoutController::class, 'pay'])
            ->middleware('throttle:10,1')
            ->name('teacher.credits.checkout.pay');
        Route::get('/teacher/credits/checkout/{recharge}/status', [CreditCheckoutController::class, 'status'])
            ->middleware('throttle:60,1')
            ->name('teacher.credits.checkout.status');
        // STATUS SEMANTICS (MOVA Yape Final Pre-Card Gate): refresh() es el
        // único que reconcilia contra Mercado Pago (POST, explícito) —
        // status() (GET) es lectura pura. Mismo límite que status() porque
        // es el endpoint que el polling del frontend llama de verdad.
        Route::post('/teacher/credits/checkout/{recharge}/refresh', [CreditCheckoutController::class, 'refresh'])
            ->middleware('throttle:60,1')
            ->name('teacher.credits.checkout.refresh');

        // 'create'/'store' deliberadamente excluidos: el profesor ya no
        // publica anuncios (marketplace informativo, HANDOFF_FINAL.md §21) —
        // "el profe elige a quién enseñar aceptando solicitudes abiertas",
        // no publicando ofertas. index/edit/update/destroy/toggle se dejan
        // vivos para que un profesor con ofertas ya creadas antes de este
        // cambio pueda seguir gestionándolas (desactivar, ajustar tarifa
        // específica, borrar) — solo se cierra la puerta a crear nuevas.
        // ClassOffer y DiagnosticRecommendationService NO se tocan: siguen
        // leyendo is_active=true de lo que ya existe.
        Route::resource('class-offers', ClassOfferController::class)->except(['show', 'create', 'store'])->middleware('throttle:20,1');
        Route::post('/class-offers/{classOffer}/toggle', [ClassOfferController::class, 'toggleActive'])->middleware('throttle:20,1')->name('class-offers.toggle');

        Route::get('/teacher/requests', [ClassRequestController::class, 'teacherIndex'])->name('teacher.requests');
        Route::get('/teacher/requests/{classRequest}/accept', [ClassRequestController::class, 'accept'])->name('teacher.requests.accept');
        Route::post('/teacher/requests/{classRequest}/reject', [ClassRequestController::class, 'teacherReject'])->middleware('throttle:20,1')->name('teacher.requests.reject');
        Route::post('/lessons', [LessonController::class, 'store'])->middleware('throttle:10,1')->name('lessons.store');
        Route::get('/teacher/classes', [LessonController::class, 'teacherIndex'])->name('teacher.lessons');
        Route::get('/teacher/reports', [LessonReportController::class, 'teacherIndex'])->name('teacher.reports');
        Route::get('/lessons/{lesson}/report/create', [LessonReportController::class, 'create'])->name('lesson-reports.create');
        Route::post('/lessons/{lesson}/report', [LessonReportController::class, 'store'])->middleware('throttle:20,1')->name('lesson-reports.store');
    });

    // ── Shared: cancel, reschedule & join (parent, teacher, admin) ────────────
    Route::middleware('not.suspended')->group(function () {
        Route::post('/lessons/{lesson}/cancel', [LessonController::class, 'cancel'])->middleware('throttle:10,1')->name('lessons.cancel');
        Route::post('/lessons/{lesson}/reschedule', [LessonController::class, 'reschedule'])->middleware('throttle:20,1')->name('lessons.reschedule');
        Route::get('/lessons/{lesson}/join', [LessonController::class, 'join'])->name('lessons.join');

        // H-06 — Movida desde el grupo `role:teacher`.
        //
        // La ruta era MÁS restrictiva que su propia autorización:
        // LessonReportController::show() llama a authorize('view', $lesson), y
        // LessonPolicy::view() autoriza al profesor dueño, al PADRE del alumno y
        // al admin. Pero el middleware de rol daba 403 al padre antes de que la
        // policy llegara a opinar (docs/MOVA_SYSTEM_MAP.md H-06, C-1).
        //
        // El padre ya veía exactamente estos datos en /my-reports, así que no se
        // expone nada nuevo: se elimina una incoherencia por la que un enlace
        // directo al reporte de su propio hijo fallaba. Quien decide sigue
        // siendo la policy, que es donde vive la regla de ownership.
        Route::get('/lessons/{lesson}/report', [LessonReportController::class, 'show'])->name('lesson-reports.show');
    });

    // ── Admin ────────────────────────────────────────────────────────────────
    // Deliberadamente SIN 'not.suspended': a diferencia de parent/teacher, un admin
    // suspendido debe conservar acceso. Si se le aplicara la misma restricción, un
    // admin suspendido (por error o por otro admin) quedaría sin forma de
    // revertir su propia suspensión, dejando la plataforma sin administrador activo.
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/users', [AdminController::class, 'users'])->name('admin.users');
        Route::get('/pending-teachers', [AdminController::class, 'pendingTeachers'])->name('admin.teachers.pending');
        Route::post('/teachers/{teacher}/verify', [AdminController::class, 'verifyTeacher'])->middleware(['admin.mfa:sensitive', 'throttle:20,1'])->name('admin.teachers.verify');
        Route::post('/teachers/{teacher}/reject', [AdminController::class, 'rejectTeacher'])->middleware(['admin.mfa:sensitive', 'throttle:20,1'])->name('admin.teachers.reject');
        Route::post('/users/{user}/suspend', [AdminController::class, 'suspendUser'])->middleware(['admin.mfa:sensitive', 'throttle:20,1'])->name('admin.users.suspend');
        Route::post('/users/{user}/unsuspend', [AdminController::class, 'unsuspendUser'])->middleware(['admin.mfa:sensitive', 'throttle:20,1'])->name('admin.users.unsuspend');
        Route::get('/requests', [AdminController::class, 'requests'])->name('admin.requests');
        Route::get('/lessons', [AdminController::class, 'lessons'])->name('admin.lessons');
        Route::post('/lessons/{lesson}/cancel', [AdminController::class, 'cancelLesson'])->middleware(['admin.mfa:sensitive', 'throttle:10,1'])->name('admin.lessons.cancel');
        Route::post('/lessons/{lesson}/force-complete', [AdminController::class, 'forceCompleteLesson'])->middleware(['admin.mfa:sensitive', 'throttle:10,1'])->name('admin.lessons.force-complete');
        Route::post('/lessons/{lesson}/force-refund', [AdminController::class, 'forceRefundLesson'])->middleware(['admin.mfa:sensitive', 'throttle:10,1'])->name('admin.lessons.force-refund');
        // Fase 3A — Centro de Operaciones. Solo lectura + cierre manual de la
        // INCIDENCIA (no del recurso); ninguna acción financiera vive aquí
        // (ver el docblock de OperationsController).
        Route::get('/operations', [OperationsController::class, 'index'])->name('admin.operations');
        Route::post('/operations/{alert}/close', [OperationsController::class, 'close'])
            ->middleware('throttle:20,1')
            ->name('admin.operations.close');

        Route::get('/recharges', [RechargeController::class, 'index'])->name('admin.recharges.index');
        Route::post('/recharges/{recharge}/approve', [RechargeController::class, 'approve'])->middleware(['admin.mfa:sensitive', 'throttle:10,1'])->name('admin.recharges.approve');
        Route::post('/recharges/{recharge}/reject', [RechargeController::class, 'reject'])->middleware(['admin.mfa:sensitive', 'throttle:20,1'])->name('admin.recharges.reject');
        // H-02: la reversión existía como servicio probado pero sin ninguna ruta
        // que la alcanzara. throttle:10,1 igual que approve — es una operación
        // financiera, no una consulta.
        Route::post('/recharges/{recharge}/reverse', [RechargeController::class, 'reverse'])->middleware(['admin.mfa:sensitive', 'throttle:10,1'])->name('admin.recharges.reverse');
        Route::get('/reviews', [TeacherReviewController::class, 'adminIndex'])->name('admin.reviews');
        Route::post('/reviews/{review}/hide', [TeacherReviewController::class, 'hide'])->middleware('throttle:20,1')->name('admin.reviews.hide');
        Route::post('/reviews/{review}/show', [TeacherReviewController::class, 'showReview'])->middleware('throttle:20,1')->name('admin.reviews.show');
        Route::get('/ai-usage', [AiUsageController::class, 'index'])->name('admin.ai-usage');
    });
});

require __DIR__ . '/auth.php';
