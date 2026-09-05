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
    /**
     * Ver/consultar el estado de la propia recarga (checkout automático
     * Mercado Pago) — nunca un admin ni otro profesor. Distinta de
     * approve/reject/reverse (exclusivas de admin) y con la regla inversa:
     * aquí el DUEÑO es quien puede, nunca el admin.
     */
    public function view(User $user, RechargeRequest $recharge): bool
    {
        return $user->teacherProfile !== null
            && $recharge->teacher_profile_id === $user->teacherProfile->id;
    }

    /**
     * Enviar un intento de pago (Mercado Pago) contra la propia recarga.
     * Misma regla de ownership que view() — createPaymentAttempt() ya
     * decide server-side si el intento es válido según el estado actual
     * (ver MercadoPagoPaymentProvider::resolveAttemptRow()), así que esta
     * Policy solo protege la frontera "¿es tuya?", nunca el estado de
     * negocio.
     */
    public function pay(User $user, RechargeRequest $recharge): bool
    {
        return $this->view($user, $recharge);
    }

    /**
     * P0 (MOVA Yape Checkout Pre-Card Hardening): una recarga
     * payment_method=mercadopago es "provider-managed" — su verdad
     * financiera la decide EXCLUSIVAMENTE
     * MercadoPagoPaymentReconciliationService (server-to-server), nunca un
     * admin humano. Sin este guard, un admin podía aprobar manualmente una
     * recarga de Mercado Pago todavía pendiente de confirmación (o incluso
     * ya rechazada por el proveedor), abonando créditos que el pago real
     * nunca respaldó. RechargeApprovalService::credit() aplica el mismo
     * guard como choke point real — este es defensa en profundidad para dar
     * un 403 correcto en el límite HTTP, no la única protección.
     */
    public function approve(User $user, RechargeRequest $recharge): bool
    {
        return $user->hasRole('admin') && $recharge->payment_method !== 'mercadopago';
    }

    /**
     * Misma razón que approve(): rechazar manualmente una recarga de
     * Mercado Pago que todavía está pendiente de confirmación del proveedor
     * dejaría RechargeRequest.status='rejected' contradiciendo una posible
     * confirmación 'approved' posterior — y RechargeApprovalService::credit()
     * no reabre una recarga rechazada (abort_if de estado terminal), así que
     * el intento quedaría atascado para siempre en cuanto Mercado Pago
     * confirme el pago.
     */
    public function reject(User $user, RechargeRequest $recharge): bool
    {
        return $user->hasRole('admin') && $recharge->payment_method !== 'mercadopago';
    }

    /**
     * H-02 — Reversión manual de una recarga ya aprobada.
     *
     * Existía como método de policy y como servicio probado, pero NINGUNA ruta
     * lo alcanzaba: un Yape falso aprobado a mano no se podía deshacer desde el
     * producto (docs/MOVA_SYSTEM_MAP.md H-02). Ahora hay camino de producto, y
     * esta policy es su límite.
     *
     * SE EXCLUYE mercadopago, por el mismo criterio que approve()/reject().
     *
     * Una recarga de Mercado Pago está respaldada por un pago real en el
     * proveedor. Revertirla a mano en MOVA descontaría los créditos SIN que el
     * dinero haya vuelto al profesor, dejando a MOVA contradiciendo a Mercado
     * Pago, que sigue siendo la fuente de verdad. El camino correcto para esas
     * es el inverso: emitir el reembolso en Mercado Pago y dejar que
     * MercadoPagoPaymentReconciliationService lo confirme server-to-server y
     * llame a reverse() con la evidencia — que es exactamente lo que ya hace.
     *
     * Consecuencia asumida y explícita: si hiciera falta revertir una recarga de
     * Mercado Pago sin pasar por el proveedor, hoy no se puede desde la interfaz.
     * Es deliberado: preferimos un hueco conocido antes que una vía para
     * desincronizar MOVA del proveedor en silencio.
     */
    public function reverse(User $user, RechargeRequest $recharge): bool
    {
        return $user->hasRole('admin') && $recharge->payment_method !== 'mercadopago';
    }
}
