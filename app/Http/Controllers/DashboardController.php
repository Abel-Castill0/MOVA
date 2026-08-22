<?php

namespace App\Http\Controllers;

use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\TeacherReview;
use App\Models\User;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();

        if ($user->hasRole('admin')) {
            return Inertia::render('Dashboard/Admin', [
                'stats' => [
                    'users'                   => User::count(),
                    'pending_teachers'         => TeacherProfile::where('is_verified', false)->count(),
                    'incomplete_profiles'      => TeacherProfile::where(function ($q) {
                        $q->whereNull('bio')->orWhere('bio', '')->orWhere('hourly_rate', 0);
                    })->count(),
                    'classes_today'            => Lesson::whereDate('start_time', today())->count(),
                    'open_requests'            => ClassRequest::where('status', 'open')->count(),
                    // C-1 (A-1/Fase 3B §11): antes de C-1 'completed' solo se alcanzaba
                    // TRAS crear el reporte, así que esta condición era imposible por
                    // construcción — contador muerto. El estado real de "debe un
                    // reporte" es 'paid' dentro de la ventana de gracia. Ver
                    // Lesson::scopeAwaitingReportWithinGrace().
                    'completed_without_report' => Lesson::awaitingReportWithinGrace()->count(),
                    'failed_jobs'              => \DB::table('failed_jobs')->count(),
                    'unverified_email'         => User::whereNull('email_verified_at')->count(),
                    'unverified_phone'         => User::whereNull('phone_verified_at')->count(),
                    'subjects'                 => Subject::count(),
                ],
                'recentUsers' => User::with('roles')
                    ->latest()
                    ->take(5)
                    ->get(['id', 'name', 'email', 'created_at'])
                    ->map(fn($u) => array_merge($u->toArray(), ['roles' => $u->getRoleNames()])),
            ]);
        }

        if ($user->hasRole('teacher')) {
            $profile = $user->teacherProfile;
            // Mismo bug A-1 que arriba: 'completed' sin reporte era imposible antes
            // de C-1. 'paid' dentro de la gracia es el estado real.
            $pendingReports = $profile ? Lesson::where('teacher_profile_id', $profile->id)
                ->awaitingReportWithinGrace()
                ->count() : 0;

            $checklist = [];
            $score     = 0;
            if ($profile) {
                $hasSubjects     = $profile->subjects()->exists();
                $hasActiveOffer  = $profile->classOffers()->where('is_active', true)->exists();
                $checks = [
                    'bio'            => !empty($profile->bio),
                    'subjects'       => $hasSubjects,
                    'active_offer'   => $hasActiveOffer,
                    'phone_verified' => !is_null($user->phone_verified_at),
                    'email_verified' => !is_null($user->email_verified_at),
                    'is_verified'    => $profile->is_verified,
                ];
                $score     = (int) round(array_sum($checks) / count($checks) * 100);
                $checklist = $checks;
            }

            return Inertia::render('Dashboard/Teacher', [
                // 'paid' (pago confirmado, reporte pendiente) no tiene filtro de
                // start_time porque ya ocurrió — es la acción "Escribir reporte"
                // que debe verse de inmediato al volver del modal de Jitsi.
                'upcoming' => $profile ? Lesson::where('teacher_profile_id', $profile->id)
                    ->where(function ($q) {
                        $q->where(function ($scheduled) {
                            $scheduled->where('status', 'scheduled')->where('start_time', '>=', now());
                        })->orWhere('status', 'paid');
                    })
                    ->with(['student', 'classRequest.subject'])
                    ->orderBy('start_time')
                    ->take(5)->get() : [],
                'pending_requests' => $profile ? (function () use ($profile) {
                    $offerIds   = $profile->classOffers()->pluck('id');
                    $subjectIds = $profile->subjects()->pluck('subjects.id');
                    return ClassRequest::where('status', 'open')
                        ->where(function ($q) use ($offerIds, $subjectIds) {
                            $q->whereIn('class_offer_id', $offerIds)
                              ->orWhere(function ($inner) use ($subjectIds) {
                                  $inner->whereNull('class_offer_id')
                                        ->whereIn('subject_id', $subjectIds);
                              });
                        })->count();
                })() : 0,
                'pending_reports'    => $pendingReports,
                'profile_score'     => $score,
                'profile_checklist' => $checklist,
                'reviews'           => $profile ? TeacherReview::where('teacher_profile_id', $profile->id)
                    ->where('is_visible', true)
                    ->latest()
                    ->take(5)
                    ->get(['id', 'rating', 'comment', 'created_at']) : [],
                'avg_rating'        => $profile ? $profile->avgRating() : null,
                'review_count'      => $profile ? $profile->reviewCount() : 0,
            ]);
        }

        $studentIds = $user->students()->pluck('id');

        $avgTeacherRating = TeacherReview::where('parent_id', $user->id)->avg('rating');

        return Inertia::render('Dashboard/Parent', [
            'students' => $user->students()->get(),
            // Estados "activos": incluye pending_parent_confirmation (ya ocurrió,
            // espera calificación) además de scheduled/paid, para que el timeline
            // del dashboard pueda ofrecer la acción contextual correcta en cada caso.
            'upcoming' => Lesson::whereIn('student_id', $studentIds)
                ->whereIn('status', ['scheduled', 'paid', 'pending_parent_confirmation'])
                ->with(['teacherProfile.user', 'student', 'classRequest.subject'])
                ->orderBy('start_time')
                ->take(5)->get(),
            'next_lesson' => Lesson::whereIn('student_id', $studentIds)
                ->whereIn('status', ['scheduled', 'paid'])
                ->where('start_time', '>=', now())
                ->with(['teacherProfile.user', 'student', 'classRequest.subject'])
                ->orderBy('start_time')
                ->first(),
            'recent_history' => Lesson::whereIn('student_id', $studentIds)
                ->where('status', 'completed')
                ->with(['teacherProfile.user', 'student', 'classRequest.subject', 'teacherReview'])
                ->orderByDesc('start_time')
                ->take(5)->get(),
            'stats' => [
                'class_requests_total' => ClassRequest::whereIn('student_id', $studentIds)->count(),
                'classes_completed'    => Lesson::whereIn('student_id', $studentIds)->where('status', 'completed')->count(),
                'avg_teacher_rating'   => $avgTeacherRating ? round($avgTeacherRating, 1) : null,
            ],
            'pending_approval' => ClassRequest::whereIn('student_id', $studentIds)
                ->where('status', 'pending_parent_approval')
                ->count(),
            'last_report' => (function () use ($studentIds) {
                $r = LessonReport::whereIn('student_id', $studentIds)
                    ->with(['lesson.classRequest.subject', 'lesson.teacherProfile.user', 'student'])
                    ->latest()->first();
                if (!$r) return null;
                return [
                    'lesson_id'           => $r->lesson_id,
                    'subject'             => $r->lesson?->classRequest?->subject?->name ?? 'Clase',
                    'student_name'        => $r->student?->first_name,
                    'teacher_name'        => $r->lesson?->teacherProfile?->user?->name,
                    'start_time'          => $r->lesson?->start_time,
                    'topic_covered'       => $r->topic_covered,
                    'student_performance' => $r->student_performance,
                    'next_step'           => $r->next_step,
                    'sent_to_parent_at'   => $r->sent_to_parent_at,
                ];
            })(),
        ]);
    }
}
