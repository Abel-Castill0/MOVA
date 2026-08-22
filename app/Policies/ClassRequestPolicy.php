<?php

namespace App\Policies;

use App\Models\ClassRequest;
use App\Models\User;

class ClassRequestPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('admin') ? true : null;
    }

    // Parent approving/rejecting their own child's request (matches routes
    // class-requests.approve / class-requests.reject).
    public function view(User $user, ClassRequest $classRequest): bool
    {
        return $user->students()->whereKey($classRequest->student_id)->exists();
    }

    // Teacher accepting an open request (matches route teacher.requests.accept).
    //
    // SEGURIDAD (hallazgo CRÍTICO de auditoría, 2026-08-22): is_verified NUNCA
    // se comprobaba aquí. MOVA declara que todo profesor pasa por verificación
    // de admin antes de dictar su primera clase (HANDOFF_FINAL.md §1), pero el
    // código no lo exigía — un profesor sin verificar podía aceptar una
    // solicitud, entrar a una videollamada y quedar a solas con un menor.
    // Probado empíricamente antes de este fix (transacción revertida, sin
    // tocar datos reales): ClassRequestPolicy::accept() devolvía true para un
    // perfil con is_verified=false. reject() delega en accept(), así que
    // también queda bloqueado — correcto: un profesor sin verificar no debe
    // interactuar con la cola de solicitudes en absoluto, no solo con aceptar.
    public function accept(User $user, ClassRequest $classRequest): bool
    {
        $profile = $user->teacherProfile;
        if (! $profile || ! $profile->is_verified) {
            return false;
        }

        // Código de referido (Opción A, HANDOFF_FINAL.md §18): si la
        // solicitud ya está vinculada a un profesor específico, es EXCLUSIVA
        // de ese profesor — ni siquiera matchear por materia/oferta debe
        // dar acceso a otro. Corta aquí, no cae al matching de siempre.
        if ($classRequest->teacher_profile_id !== null) {
            return $classRequest->teacher_profile_id === $profile->id;
        }

        $offerIds = $profile->classOffers()->pluck('id');
        $subjectIds = $profile->subjects()->pluck('subjects.id');
        $ownedViaOffer = $classRequest->class_offer_id && $offerIds->contains($classRequest->class_offer_id);
        $ownedViaSubject = ! $classRequest->class_offer_id && $subjectIds->contains($classRequest->subject_id);

        return $ownedViaOffer || $ownedViaSubject;
    }

    // Teacher rejecting an open request (matches route teacher.requests.reject).
    public function reject(User $user, ClassRequest $classRequest): bool
    {
        return $this->accept($user, $classRequest);
    }
}
