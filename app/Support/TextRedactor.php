<?php

namespace App\Support;

/**
 * F-05 — Redacción de identificadores antes de enviar texto libre a un
 * proveedor de IA externo.
 *
 * ADVERTENCIA EXPLÍCITA, LEER ANTES DE CONFIAR EN ESTA CLASE:
 *
 *   Esto es redacción BEST-EFFORT, no anonimización garantizada. Es
 *   imposible garantizar por medios léxicos que un texto libre escrito por un
 *   padre sobre su hijo no contenga un identificador. Un nombre de pila en
 *   minúscula y sin marcador de parentesco ("juan no entiende fracciones") no
 *   es distinguible de una palabra común sin un diccionario de nombres, y un
 *   diccionario tampoco sería exhaustivo.
 *
 *   La versión anterior de este código vivía dentro de
 *   DiagnosticAiEnrichmentService y su docblock afirmaba que la IA "nunca
 *   recibe nombre del alumno/padre, email, teléfono". La implementación —un
 *   único regex que exigía DOS palabras capitalizadas con lookbehind— no podía
 *   cumplir eso: fallaba con un nombre de pila solo, con cualquier nombre al
 *   inicio del texto, con minúsculas, y no tenía patrón alguno para teléfonos,
 *   emails ni DNI. El caso más frecuente en producción ("Juan no entiende
 *   las fracciones") se enviaba literal.
 *
 *   Esta clase cubre mucho más, pero la garantía real de privacidad sigue
 *   siendo la MINIMIZACIÓN: la IA está desactivada por defecto
 *   (DIAGNOSTIC_AI_ENABLED=false), nunca decide qué profesor se recomienda, y
 *   ai_usage_logs no persiste ni el prompt ni la respuesta ni el texto
 *   original. Activar la IA es una decisión que debe tomarse sabiendo que
 *   esto es mitigación, no blindaje.
 */
class TextRedactor
{
    public const NAME_PLACEHOLDER   = '[NOMBRE]';
    public const NUMBER_PLACEHOLDER = '[NÚMERO]';
    public const EMAIL_PLACEHOLDER  = '[EMAIL]';
    public const URL_PLACEHOLDER    = '[ENLACE]';

    /**
     * Marcadores de parentesco/identidad tras los cuales la siguiente palabra
     * capitalizada —o incluso en minúscula— es casi con certeza un nombre.
     * Es lo que rescata el caso que el regex anterior no veía: "mi hijo juan".
     */
    private const KINSHIP_MARKERS = [
        'mi hijo', 'mi hija', 'mi nieto', 'mi nieta', 'mi sobrino', 'mi sobrina',
        'el niño', 'la niña', 'el alumno', 'la alumna', 'mi alumno', 'mi alumna',
        'se llama', 'llamado', 'llamada', 'nombre es',
    ];

    public static function redact(string $text): string
    {
        // El orden importa: emails y URLs contienen dígitos y puntos que los
        // patrones numéricos partirían por la mitad, dejando fragmentos
        // reconocibles.
        $text = self::redactEmails($text);
        $text = self::redactUrls($text);
        $text = self::redactNumbers($text);
        $text = self::redactNamesAfterKinshipMarkers($text);
        $text = self::redactCapitalizedNames($text);

        return $text;
    }

    private static function redactEmails(string $text): string
    {
        return preg_replace(
            '/[\p{L}0-9._%+\-]+@[\p{L}0-9.\-]+\.[\p{L}]{2,}/u',
            self::EMAIL_PLACEHOLDER,
            $text
        );
    }

    private static function redactUrls(string $text): string
    {
        return preg_replace(
            '#\b(?:https?://|www\.)[^\s<>"\']+#iu',
            self::URL_PLACEHOLDER,
            $text
        );
    }

    /**
     * Cualquier secuencia de 6 o más dígitos (permitiendo espacios, guiones y
     * puntos internos). Cubre de una sola vez teléfonos peruanos de 9 dígitos,
     * el prefijo +51, DNI de 8, números de operación y de cuenta — en lugar de
     * un patrón frágil por cada formato. Un año ("2026") o una nota ("15") no
     * llegan a 6 dígitos, así que la información pedagógica útil se conserva.
     */
    private static function redactNumbers(string $text): string
    {
        return preg_replace(
            '/\+?\d(?:[\s.\-]?\d){5,}/u',
            self::NUMBER_PLACEHOLDER,
            $text
        );
    }

    /**
     * "mi hijo Juan", "mi hija ana", "se llama Pedro Ramírez" -> el nombre cae
     * aunque vaya en minúscula, que es donde el enfoque puramente
     * ortográfico se quedaba corto.
     */
    private static function redactNamesAfterKinshipMarkers(string $text): string
    {
        foreach (self::KINSHIP_MARKERS as $marker) {
            $quoted = preg_quote($marker, '/');

            $text = preg_replace(
                // Hasta dos palabras tras el marcador; se excluyen conectores
                // frecuentes para no borrar "mi hijo no entiende".
                '/\b('.$quoted.')\s+(?!(?:no|que|se|es|está|esta|tiene|necesita|va|y|de|del|con|en|le|lo|me|su|sus|un|una|el|la)\b)'
                .'(\p{L}[\p{L}\'\-]*(?:\s+\p{Lu}[\p{L}\'\-]*)?)/iu',
                '$1 '.self::NAME_PLACEHOLDER,
                $text
            );
        }

        return $text;
    }

    /**
     * Palabras capitalizadas que parecen nombres propios.
     *
     * Cambios respecto a la versión anterior:
     *   - Se eliminó el lookbehind que exigía un carácter previo, así que un
     *     nombre AL INICIO del texto ya se detecta (antes no, y es una de las
     *     formas más comunes de empezar la frase).
     *   - Basta UNA palabra capitalizada, no dos. Antes "Juan" solo nunca
     *     coincidía.
     *   - Se excluyen las palabras capitalizadas por posición (inicio de frase)
     *     que son vocabulario común, para no destrozar el texto: se comprueba
     *     contra una lista de arranques frecuentes.
     */
    private static function redactCapitalizedNames(string $text): string
    {
        // Vocabulario común que aparece capitalizado por ir tras punto o al
        // inicio; redactarlo dejaría el texto inservible para el modelo.
        $common = 'El|La|Los|Las|Un|Una|Mi|Su|Este|Esta|Ese|Esa|No|Sí|Si|Y|O|Pero|Porque|Cuando|Como|Que|'
            .'Necesita|Tiene|Está|Esta|Quiere|Puede|Debe|Hace|Va|Le|Se|Me|Por|Para|Con|Sin|De|Del|En|A|'
            .'Matemática|Matemáticas|Álgebra|Geometría|Física|Química|Historia|Lenguaje|Comunicación|'
            .'Inglés|Biología|Aritmética|Trigonometría|Cálculo|Razonamiento|Ciencias|Literatura|'
            .'Primaria|Secundaria|Colegio|Profesor|Profesora|Clase|Clases|Examen|Tarea|Curso|Nivel';

        return preg_replace(
            '/(?<![\p{L}])(?!(?:'.$common.')(?![\p{L}]))'
            .'\p{Lu}[\p{Ll}\'\-]+(?:\s+\p{Lu}[\p{Ll}\'\-]+)*/u',
            self::NAME_PLACEHOLDER,
            $text
        );
    }
}
