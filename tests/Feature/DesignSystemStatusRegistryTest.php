<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Contract test: todo valor de estado de negocio real debe tener una
 * representación registrada en el frontend (utils/statusColors.js,
 * utils/rechargeStatusColors.js) — el mismo tipo de deriva que ya se
 * encontró y corrigió manualmente esta sesión (el 'open' que se pintaba
 * azul en Admin/Requests.vue pero cyan en el resto de la app) puede volver
 * a aparecer si alguien agrega un estado nuevo a un enum de backend y se
 * olvida del frontend. Este test lo detiene en CI, no depende de que un
 * humano se acuerde de revisar ambos lados.
 *
 * Las listas de abajo NO se leen dinámicamente del schema de SQLite a
 * propósito: se intentó (`sqlite_master.sql` + parseo del CHECK
 * constraint) y ese mismo intento encontró un bug real — el CHECK de
 * `class_requests.status` en SQLite nunca se amplió para incluir
 * 'teacher_rejected' (ver docs/MOVA_DESIGN_AUDIT_FINAL.md, sección "BUG
 * REAL... P0") — así que el schema de test no es una fuente confiable del
 * enum real de producción (MySQL) todavía. Las listas aquí están citadas
 * contra los archivos de migración reales, verificados leyendo cada uno
 * antes de escribir este test:
 *
 * - Lesson (classes.status): create_classes_table.php (scheduled) +
 *   add_payment_states_to_classes_table.php (+paid,
 *   pending_parent_confirmation) + add_needs_admin_review_to_classes_
 *   status_enum.php (+needs_admin_review) + remove_in_progress_from_
 *   classes_status_enum.php (-in_progress, ya no es un estado válido).
 * - ClassRequest (class_requests.status): create_class_requests_table.php
 *   (pending_parent_approval, open, accepted, rejected, completed) +
 *   update_class_requests_status_enum.php (+teacher_rejected, rama MySQL).
 * - RechargeRequest (recharge_requests.status):
 *   create_recharge_requests_table.php (pending, approved, rejected) +
 *   add_reversal_state_to_recharge_requests.php (+reversed).
 *
 * Si se agrega un estado nuevo a cualquiera de estos tres dominios, este
 * test debe actualizarse a mano (las dos listas: aquí Y el archivo JS) —
 * el propósito no es evitar la actualización manual, es que ambas fuentes
 * se actualicen JUNTAS en el mismo cambio, no una sin la otra.
 */
class DesignSystemStatusRegistryTest extends TestCase
{
    private const LESSON_STATUSES = [
        'scheduled', 'paid', 'pending_parent_confirmation',
        'completed', 'cancelled', 'needs_admin_review',
    ];

    private const CLASS_REQUEST_STATUSES = [
        'pending_parent_approval', 'open', 'accepted',
        // 'expired' añadido en §14 (add_expired_status_to_class_requests).
        'rejected', 'teacher_rejected', 'completed', 'expired',
    ];

    private const RECHARGE_REQUEST_STATUSES = [
        'pending', 'approved', 'rejected', 'reversed',
    ];

    public function test_every_lesson_status_is_registered_in_status_colors_js(): void
    {
        $registry = file_get_contents(resource_path('js/utils/statusColors.js'));

        foreach (self::LESSON_STATUSES as $status) {
            $this->assertStringContainsString(
                "{$status}:",
                $registry,
                "El estado de Lesson '{$status}' (classes.status) no está en utils/statusColors.js — el frontend no sabría qué color/label mostrar."
            );
        }
    }

    public function test_every_class_request_status_is_registered_in_status_colors_js(): void
    {
        $registry = file_get_contents(resource_path('js/utils/statusColors.js'));

        foreach (self::CLASS_REQUEST_STATUSES as $status) {
            $this->assertStringContainsString(
                "{$status}:",
                $registry,
                "El estado de ClassRequest '{$status}' (class_requests.status) no está en utils/statusColors.js."
            );
        }
    }

    public function test_every_recharge_request_status_is_registered_in_recharge_status_colors_js(): void
    {
        $registry = file_get_contents(resource_path('js/utils/rechargeStatusColors.js'));

        foreach (self::RECHARGE_REQUEST_STATUSES as $status) {
            $this->assertStringContainsString(
                "{$status}:",
                $registry,
                "El estado de RechargeRequest '{$status}' no está en utils/rechargeStatusColors.js."
            );
        }
    }

    /**
     * El hallazgo concreto de esta sesión: cada estado registrado en
     * statusColors.js debe tener tanto `color` (para StatusBadge) como
     * `label` (texto real, nunca solo color) — el canal de accesibilidad
     * que DESIGN.md exige explícitamente.
     */
    public function test_every_registered_status_has_both_a_color_and_a_text_label(): void
    {
        $registry = file_get_contents(resource_path('js/utils/statusColors.js'));

        preg_match('/export const STATUS_STYLES = \{(.*?)\n\}/s', $registry, $matches);
        $this->assertNotEmpty($matches, 'No se pudo localizar el bloque STATUS_STYLES en utils/statusColors.js — revisar este test si el archivo cambió de forma.');

        $body = $matches[1];
        $allStatuses = array_merge(self::LESSON_STATUSES, self::CLASS_REQUEST_STATUSES);

        foreach (array_unique($allStatuses) as $status) {
            if (! preg_match('/\b'.preg_quote($status, '/').':\s*\{([^}]*)\}/', $body, $entryMatch)) {
                $this->fail("No se encontró la entrada completa de '{$status}' en STATUS_STYLES.");
            }

            $entry = $entryMatch[1];
            $this->assertStringContainsString('label:', $entry, "'{$status}' no tiene 'label' (texto) — el color nunca debe ser el único canal.");
            $this->assertStringContainsString('color:', $entry, "'{$status}' no tiene 'color'.");
        }
    }
}
