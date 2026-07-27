<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use App\Models\ClassOffer;
use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\RechargeRequest;
use App\Models\Student;
use App\Models\StudentDiagnostic;
use App\Models\TeacherReview;
use App\Policies\ClassOfferPolicy;
use App\Policies\ClassRequestPolicy;
use App\Policies\LessonPolicy;
use App\Policies\RechargeRequestPolicy;
use App\Policies\StudentDiagnosticPolicy;
use App\Policies\StudentPolicy;
use App\Policies\TeacherReviewPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Lesson::class => LessonPolicy::class,
        ClassRequest::class => ClassRequestPolicy::class,
        TeacherReview::class => TeacherReviewPolicy::class,
        RechargeRequest::class => RechargeRequestPolicy::class,
        ClassOffer::class => ClassOfferPolicy::class,
        Student::class => StudentPolicy::class,
        StudentDiagnostic::class => StudentDiagnosticPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
