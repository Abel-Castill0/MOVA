<?php

namespace Tests\Feature;

use App\Support\TextRedactor;
use Tests\TestCase;

/**
 * F-05 / GAP-06 — Tests adversariales de la redacción previa al envío a un
 * proveedor de IA externo. El campo redactado es texto libre escrito por un
 * padre sobre un menor de edad, así que cada caso que se escapa es una fuga
 * de PII de un niño a un tercero.
 *
 * Los casos marcados como LÍMITE CONOCIDO documentan lo que esta técnica NO
 * puede resolver. Están aquí a propósito: un límite documentado y probado es
 * honesto; un límite silencioso es el bug que teníamos.
 */
class TextRedactionTest extends TestCase
{
    /** @dataProvider identifierProvider */
    public function test_identifiers_are_redacted(string $input, string $mustNotContain, string $why): void
    {
        $redacted = TextRedactor::redact($input);

        $this->assertStringNotContainsStringIgnoringCase(
            $mustNotContain,
            $redacted,
            "{$why}\nEntrada:  {$input}\nRedactado: {$redacted}"
        );
    }

    public static function identifierProvider(): array
    {
        return [
            // ── Los casos que el regex anterior NO capturaba ──────────────
            'nombre de pila solo al inicio' => [
                'Juan no entiende fracciones',
                'Juan',
                'El regex anterior exigía DOS palabras capitalizadas Y un lookbehind: fallaba doblemente aquí.',
            ],
            'nombre completo al inicio del texto' => [
                'Juan Pérez no entiende las fracciones',
                'Juan',
                'El lookbehind anterior exigía un carácter previo, así que al inicio del string no coincidía.',
            ],
            'apellido en nombre completo al inicio' => [
                'Juan Pérez no entiende las fracciones',
                'Pérez',
                'Mismo caso, verificando el apellido.',
            ],
            'nombre de pila en medio de la frase' => [
                'Mi hijo Juan necesita ayuda',
                'Juan',
                'Una sola palabra capitalizada nunca coincidía con el patrón anterior.',
            ],
            'nombre en minuscula tras marcador de parentesco' => [
                'mi hijo juan no entiende nada de fracciones',
                'juan',
                'Sin mayúscula no había ninguna posibilidad de detección ortográfica; lo rescata el marcador.',
            ],
            'telefono peruano de 9 digitos' => [
                'llámame al 987654321 para coordinar',
                '987654321',
                'No existía NINGÚN patrón para teléfonos.',
            ],
            'telefono con prefijo internacional' => [
                'mi número es +51 987 654 321',
                '987',
                'Los separadores no deben permitir esquivar la redacción.',
            ],
            'email' => [
                'escríbeme a mama@gmail.com',
                'mama@gmail.com',
                'No existía NINGÚN patrón para emails.',
            ],
            'dni' => [
                'su DNI es 71234567',
                '71234567',
                'No existía NINGÚN patrón para documentos de identidad.',
            ],
            'url' => [
                'mira su perfil en https://facebook.com/juan.perez.123',
                'facebook.com',
                'Una URL puede identificar directamente a la familia.',
            ],
            'nombre de colegio' => [
                'estudia en Colegio San Agustín',
                'San Agustín',
                'El colegio ubica geográficamente al menor.',
            ],
        ];
    }

    // ── El texto pedagógico útil debe sobrevivir ─────────────────────────

    public function test_the_pedagogical_content_survives_redaction(): void
    {
        $redacted = TextRedactor::redact(
            'Mi hijo no entiende las fracciones ni los decimales. Necesita reforzar antes del examen.'
        );

        // Si la redacción destruyera el contenido, la IA no podría hacer su
        // trabajo y el remedio sería peor que la enfermedad.
        $this->assertStringContainsString('fracciones', $redacted);
        $this->assertStringContainsString('decimales', $redacted);
        $this->assertStringContainsString('examen', $redacted);
    }

    public function test_subject_names_are_not_mistaken_for_person_names(): void
    {
        $redacted = TextRedactor::redact('Necesita ayuda en Matemáticas y Comunicación de Secundaria.');

        $this->assertStringContainsString('Matemáticas', $redacted);
        $this->assertStringContainsString('Comunicación', $redacted);
        $this->assertStringContainsString('Secundaria', $redacted);
    }

    public function test_short_numbers_relevant_to_learning_are_preserved(): void
    {
        // Notas, edades y años son información pedagógica legítima; solo las
        // secuencias largas (teléfonos, DNI, cuentas) son identificadores.
        $redacted = TextRedactor::redact('Tiene 12 años, sacó 08 en el examen de 2026.');

        $this->assertStringContainsString('12', $redacted);
        $this->assertStringContainsString('2026', $redacted);
    }

    public function test_a_sentence_starting_with_a_common_word_is_not_destroyed(): void
    {
        $redacted = TextRedactor::redact('No entiende la materia. Necesita apoyo urgente.');

        $this->assertStringContainsString('entiende', $redacted);
        $this->assertStringContainsString('apoyo', $redacted);
        $this->assertStringNotContainsString(TextRedactor::NAME_PLACEHOLDER, $redacted);
    }

    // ── Límite conocido y documentado ────────────────────────────────────

    public function test_a_lowercase_first_name_without_any_marker_is_a_documented_limitation(): void
    {
        // LÍMITE CONOCIDO: "juan" en minúscula, sin marcador de parentesco, es
        // indistinguible de una palabra común sin un diccionario de nombres —
        // y un diccionario tampoco sería exhaustivo. Se documenta como test
        // para que el límite sea explícito y no una sorpresa.
        //
        // La mitigación real es la minimización + el feature flag, no la
        // redacción. Ver la advertencia en TextRedactor.
        $redacted = TextRedactor::redact('juan no entiende fracciones');

        $this->assertStringContainsString(
            'juan',
            $redacted,
            'Si este assert empieza a fallar, la detección mejoró: actualiza la advertencia de TextRedactor.'
        );
    }

    // ── Robustez ─────────────────────────────────────────────────────────

    public function test_empty_text_is_handled(): void
    {
        $this->assertSame('', TextRedactor::redact(''));
    }

    public function test_multiple_identifiers_in_one_text_are_all_redacted(): void
    {
        $redacted = TextRedactor::redact(
            'Juan Pérez, DNI 71234567, teléfono 987654321, correo juan@mail.com, estudia en Colegio San José.'
        );

        foreach (['Juan', 'Pérez', '71234567', '987654321', 'juan@mail.com', 'San José'] as $identifier) {
            $this->assertStringNotContainsString($identifier, $redacted, "Se filtró: {$identifier}");
        }
    }
}
