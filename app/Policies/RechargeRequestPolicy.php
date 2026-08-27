<?php

namespace App\Policies;

use App\Models\RechargeRequest;
use App\Models\User;

/**
 * Aprobar, rechazar y revertir una recarga son acciones EXCLUSIVAS de admin.
 * Ningún otro rol puede ejecutarlas — en particular, un profesor nunca puede
 * aprobar su propia solicitud.
 *
 * F-17 — Nota sobre la forma de esta clase. La versión anterior tenía un
 * before() que concedía todo a admin y, debajo, tres métodos que devolvían
 * `false` con un comentario explicando que el before() ya lo cubría.
 * Funcionaba correctamente (before() cortocircuita y los métodos nunca se
 * evalúan para un admin), pero leído de forma aislada `approve()` parecía
 * denegar la acción a todo el mundo, incluido el admin.
 *
 * Dado el historial de MOVA con Policies —una auditoría encontró que
 * ClassRequestPolicy::accept() no comprobaba is_verified, permitiendo que un
 * profesor sin verificar quedara a solas con un menor— la legibilidad aquí
 * tiene valor defensivo real, no solo estético. Ahora cada método declara su
 * regla explícitamente y el comportamiento no depende de recordar que existe
 * un before().
 */
class RechargeRequestPolicy
{
    public function approve(User $user, RechargeRequest $recharge): bool
    {
        return $user->hasRole('admin');
    }

    public function reject(User $user, RechargeRequest $recharge): bool
    {
        return $user->hasRole('admin');
    }

    public function reverse(User $user, RechargeRequest $recharge): bool
    {
        return $user->hasRole('admin');
    }
}
