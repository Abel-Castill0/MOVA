<?php

namespace App\Http\Controllers;

use App\Models\AccountDeletionRequest;
use App\Notifications\AccountClosureCode;
use App\Services\AccountLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AccountLifecycleController extends Controller
{
    private function proofKey(Request $request): string
    {
        if (! $request->session()->has('account_closure_nonce')) {
            $request->session()->put('account_closure_nonce', \Illuminate\Support\Str::random(48));
        }
        return 'account-closure:'.$request->user()->id.':'.hash('sha256', $request->session()->get('account_closure_nonce'));
    }

    public function sendCode(Request $request, AccountLifecycleService $lifecycle)
    {
        $lifecycle->authorize($request->user());
        abort_unless($request->user()->hasVerifiedEmail(), 403);
        if (config('mail.default') === 'log' || (config('mail.default') === 'array' && ! app()->runningUnitTests())
            || ! \App\Support\MailAllowlist::allows($request->user()->email)) {
            throw ValidationException::withMessages(['code' => 'El envío de códigos no está disponible. Confirma con tu contraseña o contacta con soporte.']);
        }
        $code = (string) random_int(100000, 999999);
        Cache::put($this->proofKey($request), ['hash' => Hash::make($code), 'email' => $request->user()->email], now()->addMinutes(10));
        try {
            $request->user()->notify(new AccountClosureCode($code));
        } catch (\Throwable $exception) {
            Cache::forget($this->proofKey($request));
            // Provider exceptions may contain message contents; never log this proof.
            throw ValidationException::withMessages(['code' => 'No pudimos enviar el código. Inténtalo más tarde o confirma con tu contraseña.']);
        }
        return back()->with('status', 'closure-code-sent');
    }

    public function store(Request $request, AccountLifecycleService $lifecycle)
    {
        $lifecycle->authorize($request->user());
        $data = $request->validate([
            'confirmation' => ['required', 'accepted'],
            'scope' => ['required', 'in:account'],
            'password' => ['nullable', 'string', 'max:512'],
            'code' => ['nullable', 'digits:6'],
        ]);
        $method = 'password';
        if (! filled($data['password'] ?? null) || ! Hash::check($data['password'], $request->user()->password)) {
            $proof = Cache::pull($this->proofKey($request));
            if (! $request->user()->hasVerifiedEmail() || ! $proof || $proof['email'] !== $request->user()->email
                || ! Hash::check($data['code'] ?? '', $proof['hash'])) {
                throw ValidationException::withMessages(['password' => 'Confirma con tu contraseña actual o con un código válido. Si el código falló, solicita uno nuevo.']);
            }
            $method = 'email_code';
        }
        $identity = $request->user()->only(['password', 'email', 'email_verified_at']);
        $deletion = $lifecycle->requestClosure($request->user(), $method, function ($locked) use ($identity, $method) {
            $valid = $method === 'password'
                ? hash_equals($identity['password'], $locked->password)
                : $identity['email'] === $locked->email && $locked->hasVerifiedEmail();
            if (! $valid) {
                throw ValidationException::withMessages(['password' => 'Tu acceso cambió. Confirma de nuevo tu identidad antes de continuar.']);
            }
        });
        Cache::forget($this->proofKey($request));
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('closure_reference', $deletion->reference);
        // A shared device must not show the receipt to a later visitor indefinitely.
        $request->session()->put('closure_reference_until', now()->addMinutes(30)->timestamp);
        return redirect()->route('account.closure.status');
    }

    public function status(Request $request)
    {
        $receipt = $request->user()
            ? AccountDeletionRequest::where('user_id', $request->user()->id)->first()?->receipt()
            : AccountDeletionRequest::where('reference', $request->session()->get('closure_reference_until', 0) >= now()->timestamp ? $request->session()->get('closure_reference', '') : '')->first()?->receipt();
        abort_unless($receipt, 404);
        return Inertia::render('Profile/ClosureStatus', [
            'receipt' => $receipt,
            'cancellableLessons' => $request->user() ? $this->cancellableLessons($request->user()) : [],
        ]);
    }

    /** Classes scheduled before closure that the owner may still cancel (no student data exposed). */
    private function cancellableLessons(\App\Models\User $user): array
    {
        $profileId = $user->teacherProfile?->id;
        $students = $user->students()->withTrashed()->pluck('id');

        return \App\Models\Lesson::query()->where('status', 'scheduled')
            ->where(fn ($query) => $query->whereIn('student_id', $students)->when($profileId, fn ($q) => $q->orWhere('teacher_profile_id', $profileId)))
            ->orderBy('start_time')->limit(50)->get(['id', 'start_time', 'duration_minutes'])
            ->map(fn ($lesson) => ['id' => $lesson->id, 'start_time' => $lesson->start_time->toIso8601String(), 'duration_minutes' => $lesson->duration_minutes])
            ->all();
    }
}
