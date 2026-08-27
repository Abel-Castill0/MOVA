<?php

namespace App\WhatsApp;

/**
 * Motivo estructurado de un mensaje `skipped`.
 *
 * Semántica formal de este enum, para que su alcance no se difumine con el
 * tiempo: cada caso representa una decisión INTERNA de MOVA que impide el
 * envío ANTES de llegar al proveedor — Meta nunca se entera de que existió
 * un intento. Por eso, si en el futuro se necesita distinguir un motivo que
 * SÍ implique que se llegó a invocar al proveedor (p. ej. una plantilla no
 * aprobada, credenciales ausentes), ese caso pertenece a `failed` o
 * `unknown`, nunca a este enum — mezclarlos rompería la garantía de que
 * `skipped` es, sin excepción, "MOVA decidió no intentarlo".
 *
 * Reemplaza la decisión previa de reutilizar `error` (texto libre) para
 * esto — esa decisión se documentó explícitamente como válida "mientras
 * exista un solo motivo de skip auditado" (docs/MOVA_PRODUCTION_READINESS.md,
 * ronda anterior). F-22 introdujo un segundo motivo real (cuenta suspendida,
 * semánticamente distinto de "sin consentimiento"), así que la condición que
 * sostenía esa decisión dejó de cumplirse: ahora sí hay más de un valor
 * posible, y una columna estructurada evita que alguien consultando
 * `whatsapp_messages` tenga que hacer `LIKE '%suspendida%'` sobre texto
 * libre para distinguirlos.
 *
 * `error` queda reservado exclusivamente para fallos REALES del proveedor
 * (status=failed) — un mensaje `skipped` nunca llega a Meta, así que nunca
 * debería tener un error de Meta; en un skip, `error` es siempre null y
 * `skip_reason` es quien explica la decisión.
 *
 * Candidatos futuros ya identificados pero NO añadidos ahora (documentar la
 * intención evita reinventar la decisión de diseño cuando aparezcan):
 * `phone_not_verified`, `feature_disabled`, `user_deleted`,
 * `provider_not_configured`. Hoy esos casos ni siquiera llegan a escribir
 * una fila de auditoría (ver los comentarios en WhatsAppChannel::send()
 * sobre por qué "no hay un `to` válido que registrar" para esos gates) —
 * si eso cambia, es el momento de añadir el case correspondiente aquí, no
 * antes.
 */
enum WhatsAppSkipReason: string
{
    case OptOut = 'opt_out';
    case Suspended = 'suspended';

    /**
     * Etiqueta legible para logs/soporte — no para lógica de negocio (para
     * eso está el propio caso del enum).
     */
    public function label(): string
    {
        return match ($this) {
            self::OptOut => 'sin consentimiento (opt-out o nunca opt-in)',
            self::Suspended => 'cuenta suspendida',
        };
    }
}
