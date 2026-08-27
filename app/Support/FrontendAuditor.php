<?php

namespace App\Support;

/**
 * Analizador estático de páginas Vue — herramienta de auditoría (GAP-08).
 *
 * POR QUÉ ESTO TIENE TESTS PROPIOS:
 *
 * La primera versión de este analizador vivía suelta en un script y produjo
 * DIEZ falsos positivos por tres bugs distintos:
 *
 *   1. `loading` no estaba en la lista de guards, así que marcó como
 *      desprotegida una página que sí lo estaba.
 *   2. Contaba un `confirm()` que solo aparecía dentro de un COMENTARIO que
 *      documentaba su eliminación.
 *   3. El regex que quitaba comentarios de bloque había perdido sus escapes
 *      (`/*` es "cero o más barras"), así que vaciaba el archivo entero antes
 *      de analizarlo y toda página parecía carecer de estado vacío.
 *
 * Una herramienta que se usa para afirmar "el frontend está auditado" no puede
 * equivocarse en silencio: si su parser falla, la conclusión falla con él. De
 * ahí las fixtures en tests/fixtures/frontend-audit.
 */
class FrontendAuditor
{
    /**
     * Nombres de refs que el proyecto usa para bloquear el doble envío. La
     * lista es explícita a propósito: añadir uno nuevo obliga a actualizarla,
     * que es preferible a un patrón laxo que dé por bueno cualquier cosa.
     */
    private const GUARD_NAMES = [
        'processing', 'submitting', 'cancelling', 'rescheduling',
        'rejecting', 'deleting', 'moderating', 'payingId', 'isProcessing', 'loading', 'saving',
    ];

    /** @return array<string, mixed> */
    public function analyze(string $source): array
    {
        $code = $this->stripComments($source);

        return [
            'actions' => $this->mutatingActions($code),
            'guard' => $this->hasGuard($code),
            'disabled' => str_contains($code, ':disabled'),
            'empty_state' => $this->hasEmptyState($code),
            'error_handling' => $this->hasErrorHandling($code),
            'modal' => str_contains($code, 'Modal'),
            'native_dialog' => $this->usesNativeDialog($code),
        ];
    }

    /**
     * Quita comentarios HTML y de bloque. Mencionar `confirm()` en un
     * comentario que explica por qué se eliminó NO es usarlo.
     *
     * Los escapes de `/\*` y `\*​/` son obligatorios: sin ellos el patrón
     * significa "cero o más barras" y devora el archivo completo.
     */
    private function stripComments(string $source): string
    {
        $source = preg_replace('/<!--.*?-->/s', '', $source);

        return preg_replace('#/\*.*?\*/#s', '', $source);
    }

    /** @return string[] */
    private function mutatingActions(string $code): array
    {
        preg_match_all(
            '/(?:router|form)\.(post|put|patch|delete)\s*\(\s*(?:route\(\s*[\'"]([^\'"]+)[\'"])?/',
            $code,
            $matches,
            PREG_SET_ORDER
        );

        $actions = [];

        foreach ($matches as $match) {
            $actions[] = strtoupper($match[1]).' '.($match[2] ?? '(dinámica)');
        }

        return array_values(array_unique($actions));
    }

    private function hasGuard(string $code): bool
    {
        $pattern = '/\b('.implode('|', self::GUARD_NAMES).')\b/';

        return (bool) preg_match($pattern, $code);
    }

    /**
     * Las páginas usan redacciones distintas para el mismo concepto ("No
     * tienes...", "Sin solicitudes", "Aún no..."). Un patrón que solo
     * reconociera una de ellas produciría falsos positivos en cadena.
     */
    private function hasEmptyState(string $code): bool
    {
        return (bool) preg_match(
            '/v-if="!\s*\w|No tienes|No hay|Sin |Aún no|Aun no|todavía no|empty|length === 0/iu',
            $code
        );
    }

    private function hasErrorHandling(string $code): bool
    {
        return (bool) preg_match('/InputError|onError|form\.errors|errors\./', $code);
    }

    /**
     * `confirmPayment` y `confirmLabel` contienen la subcadena "confirm" pero
     * no son diálogos nativos: se excluyen explícitamente.
     */
    private function usesNativeDialog(string $code): bool
    {
        if (preg_match('/confirmPayment|confirmClass|confirmLabel|confirm-payment/', $code)) {
            $code = preg_replace('/confirmPayment|confirmClass|confirmLabel|confirm-payment/', '', $code);
        }

        return (bool) preg_match('/\bwindow\.confirm\s*\(|\bwindow\.prompt\s*\(|(?<![.\w])confirm\s*\(|(?<![.\w])prompt\s*\(/', $code);
    }
}
