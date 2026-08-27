<?php

namespace Tests\Feature;

use App\Support\FrontendAuditor;
use Tests\TestCase;

/**
 * Tests del ANALIZADOR de frontend, no del frontend.
 *
 * Motivo: la primera versión de esta herramienta produjo 10 falsos positivos
 * por tres bugs de regex, y se usó para afirmar cosas sobre el estado del
 * frontend. Una herramienta de auditoría que se equivoca en silencio es peor
 * que no tenerla: da confianza injustificada.
 *
 * Cada fixture reproduce uno de los engaños que la versión anterior no supo
 * resolver.
 */
class FrontendAuditorTest extends TestCase
{
    private function analyze(string $fixture): array
    {
        $path = base_path("tests/fixtures/frontend-audit/{$fixture}.vue");
        $this->assertFileExists($path, "Falta la fixture {$fixture}.vue");

        return (new FrontendAuditor())->analyze(file_get_contents($path));
    }

    public function test_a_correct_page_is_reported_as_correct(): void
    {
        $result = $this->analyze('clean');

        $this->assertSame(['POST items.store'], $result['actions']);
        $this->assertTrue($result['guard']);
        $this->assertTrue($result['disabled']);
        $this->assertTrue($result['empty_state']);
        $this->assertTrue($result['error_handling']);
        $this->assertFalse($result['native_dialog']);
    }

    public function test_a_missing_guard_is_actually_detected(): void
    {
        // El caso que la herramienta debe encontrar de verdad: sin esto, no
        // sirve para nada.
        $result = $this->analyze('missing-guard');

        $this->assertFalse($result['guard']);
        $this->assertFalse($result['error_handling']);
        $this->assertSame(['POST thing.store'], $result['actions']);
    }

    // ── Los tres engaños que la versión anterior no superó ───────────────

    public function test_a_confirm_mentioned_only_in_a_comment_is_not_a_native_dialog(): void
    {
        // Bug real: contaba como diálogo nativo un `confirm()` que solo
        // aparecía en el comentario que documentaba su eliminación.
        $result = $this->analyze('comment-trap');

        $this->assertFalse(
            $result['native_dialog'],
            'Mencionar confirm() en un comentario no es usarlo.'
        );
    }

    public function test_block_comments_do_not_swallow_the_whole_file(): void
    {
        // Bug real: el regex de comentarios de bloque había perdido sus
        // escapes, así que `/*` significaba "cero o más barras" y vaciaba el
        // archivo. Toda página parecía carecer de estado vacío.
        $result = $this->analyze('comment-trap');

        $this->assertTrue($result['empty_state'], 'El contenido tras un comentario de bloque debe seguir analizándose.');
        $this->assertSame(['POST thing.store'], $result['actions']);
    }

    public function test_loading_counts_as_a_submit_guard(): void
    {
        // Bug real: `loading` no estaba en la lista, así que Diagnostics/Create
        // se reportó como desprotegida cuando sí lo estaba.
        $result = (new FrontendAuditor())->analyze(
            '<script setup>const loading = ref(false); function go() { router.post(route("x")) }</script>'
        );

        $this->assertTrue($result['guard']);
    }

    public function test_a_real_native_dialog_is_still_detected(): void
    {
        // Contrapartida: al arreglar los falsos positivos no debe perderse la
        // capacidad de detectar el caso verdadero.
        $result = $this->analyze('native-dialog');

        $this->assertTrue($result['native_dialog']);
    }

    public function test_confirm_payment_is_not_mistaken_for_a_native_dialog(): void
    {
        $result = (new FrontendAuditor())->analyze(
            '<script setup>function confirmPayment(l) { router.post(route("lessons.confirm-payment", l.id)) }</script>'
        );

        $this->assertFalse($result['native_dialog']);
    }

    public function test_a_read_only_page_reports_no_actions(): void
    {
        $result = (new FrontendAuditor())->analyze('<template><p>Solo texto</p></template>');

        $this->assertSame([], $result['actions']);
    }
}
