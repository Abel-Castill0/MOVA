<?php

namespace App\Services;

use App\Models\Subject;
use Illuminate\Support\Str;

/**
 * Movi local — respuestas preparadas por MOVA sobre cómo usar la plataforma.
 *
 * No es IA generativa ni llama a ningún servicio externo: lo que el usuario escribe se compara con temas conocidos
 * en nuestro propio servidor y nunca sale de él. Por eso funciona sin clave, sin costo por mensaje y sin cambiar la
 * Política de Privacidad. Solo afirma lo que la plataforma hace de verdad (precios y plazos salen de la configuración).
 *
 * Los textos usan los marcadores [Crear cuenta], [Solicitar Clase], … que el navegador convierte en botones de acción
 * (resources/js/utils/chatbotFormat.js). Cualquier otro texto se escapa en el cliente.
 */
class MoviKnowledgeBase
{
    /** Tema → [palabras clave (ya sin tildes/minúsculas) con peso, respuesta]. */
    private function topics(string $supportEmail): array
    {
        $creditPrice = number_format((float) config('credits.credit_price_pen', '2.00'), 2);
        $packages = collect((array) config('credits.packages', []))
            ->map(fn ($p) => "{$p['name']} ({$p['credits']} créditos, S/ ".number_format((float) $p['amount_pen'], 0).')')
            ->implode(', ');
        $refundDays = 7;
        $subjects = $this->subjectNames();

        return [
            'greeting' => [
                'keywords' => ['hola' => 3, 'buenas' => 3, 'buenos dias' => 3, 'buenas tardes' => 3, 'buenas noches' => 3, 'hey' => 2, 'saludos' => 2],
                'reply' => "¡Hola! Soy **Movi**, el asistente de MOVA. Puedo orientarte sobre cómo pedir una clase, cómo funcionan los créditos, cómo ser profesor o dónde encontrar ayuda. ¿Qué necesitas?",
            ],
            'thanks' => [
                'keywords' => ['gracias' => 4, 'te agradezco' => 3, 'genial' => 1, 'perfecto' => 1],
                'reply' => '¡Con gusto! Si tienes otra duda sobre MOVA, aquí estaré.',
            ],
            'bye' => [
                'keywords' => ['adios' => 4, 'chao' => 4, 'hasta luego' => 4, 'nos vemos' => 3],
                'reply' => '¡Hasta pronto! Que tengas un excelente día de aprendizaje.',
            ],
            'what_is_mova' => [
                'keywords' => ['que es mova' => 6, 'que es esto' => 3, 'que hacen' => 3, 'de que trata' => 3, 'para que sirve' => 3, 'que ofrece' => 3, 'como funciona' => 4, 'quienes son' => 2],
                'reply' => "MOVA es una plataforma peruana que conecta a familias con profesores particulares verificados para clases en vivo por videollamada. Los padres piden la clase, un profesor la acepta y la clase se hace dentro de MOVA, con reporte de aprendizaje al final.\n\nPuedes empezar creando tu cuenta: [Crear cuenta]",
            ],
            'request_class' => [
                'keywords' => ['pedir' => 3, 'pido' => 3, 'solicit' => 3, 'agend' => 4, 'reserv' => 3, 'primera clase' => 5, 'buscar profesor' => 5, 'busco un profesor' => 5, 'buscar un profesor' => 5, 'encontrar un profesor' => 5, 'conseguir un profesor' => 5, 'busco' => 2, 'buscar' => 2, 'encontrar' => 2, 'conseguir' => 2, 'necesito un profesor' => 5, 'necesito clase' => 5, 'quiero una clase' => 5, 'quiero clases' => 5, 'clase' => 1, 'profesor para' => 3],
                'reply' => "Para pedir una clase: crea tu cuenta como padre o apoderado, registra a tu hijo/a, y completa la solicitud en unos pocos pasos (hijo/a, materia, tema y horario). Un profesor verificado la revisa y te propone la clase.\n\n[Solicitar Clase] o mira antes a los profesores: [Ver profesores]",
            ],
            'student_account' => [
                'keywords' => ['hijo' => 2, 'hija' => 2, 'alumno' => 2, 'estudiante' => 2, 'menor de edad' => 4, 'registrar a mi hij' => 6, 'cuenta del alumno' => 6, 'agregar hij' => 5, 'agregar alumn' => 5, 'cuenta para mi hij' => 6],
                'reply' => "Los alumnos no tienen cuenta propia: el padre, madre o apoderado crea su cuenta, registra a su hijo/a (con su autorización) y gestiona las solicitudes y los pagos. Así los menores siempre están acompañados por un adulto responsable.\n\n[Crear cuenta]",
            ],
            'credits' => [
                'keywords' => ['credit' => 5, 'saldo' => 3, 'recarg' => 5, 'paquet' => 4],
                'reply' => "Los créditos son para profesores: 1 crédito equivale a 1 hora de clase y cuesta S/ {$creditPrice}. Al aceptar una solicitud se reservan los créditos y se consumen cuando la clase se liquida; si la clase se cancela, se devuelven. Paquetes: {$packages}.\n\nLos padres no compran créditos: ellos confirman la clase y coordinan el pago con el profesor.",
            ],
            'parent_payment' => [
                'keywords' => ['cuanto cuesta' => 4, 'cuanto cobr' => 5, 'precio' => 4, 'tarifa' => 4, 'pagar' => 3, 'pago' => 2, 'costo' => 3, 'cuanto vale' => 4, 'cuanto pago' => 5],
                'reply' => "Cada profesor tiene su tarifa por hora, visible en su perfil antes de que pidas la clase. MOVA no cobra la clase a los padres: al terminar la clase confirmas el pago en MOVA y lo coordinas directamente con el profesor (Yape o Plin).\n\n[Ver profesores]",
            ],
            'refund' => [
                'keywords' => ['reembols' => 6, 'devol' => 5, 'devuelv' => 5, 'recuperar mi dinero' => 5],
                'reply' => "Si compraste créditos y no los usaste, puedes pedir el reembolso íntegro dentro de los {$refundDays} días siguientes a la compra, escribiendo a **{$supportEmail}**. Los créditos ya reservados o consumidos por clases no se reembolsan, salvo error de MOVA.",
            ],
            'cancel_class' => [
                'keywords' => ['cancel' => 5, 'anular' => 4, 'reprogram' => 5, 'no puedo asistir' => 5, 'cambiar horario' => 5, 'cambiar la hora' => 5],
                'reply' => "Puedes cancelar o reprogramar una clase desde **Clases** en tu panel. Si el profesor no se presenta, avísanos y el equipo de MOVA revisa el caso; los créditos del profesor se liberan y tú no pagas nada.\n\nSi necesitas ayuda con un caso concreto, escribe a **{$supportEmail}**.",
            ],
            'join_class' => [
                'keywords' => ['entrar a la clase' => 6, 'unirme' => 4, 'unirse' => 4, 'como entro' => 4, 'videollamada' => 4, 'camara' => 3, 'microfono' => 3, 'enlace' => 3, 'link' => 3, 'sala' => 3, 'no puedo entrar' => 4],
                'reply' => "La clase se hace dentro de MOVA, sin instalar nada. Entra a **Clases** en tu panel y pulsa **Unirse** cuando se acerque la hora. Permite el acceso a la cámara y al micrófono en tu navegador; si no entra, prueba con Chrome y recarga la página.\n\n¿Sigue sin funcionar? Escríbenos a **{$supportEmail}**.",
            ],
            'become_teacher' => [
                'keywords' => ['ser profesor' => 6, 'ensenar' => 4, 'dar clases' => 5, 'trabajar como profesor' => 6, 'postul' => 5, 'voluntari' => 6, 'practicante' => 5, 'soy profesor' => 4, 'requisito' => 4],
                'reply' => "Para enseñar en MOVA crea tu cuenta de profesor y completa tu perfil. El equipo de MOVA revisa tu identidad, formación y antecedentes antes de marcarte como **verificado**; solo los profesores verificados reciben solicitudes.\n\n[Voluntariado]",
            ],
            'verified' => [
                'keywords' => ['verificad' => 5, 'verific' => 3, 'segur' => 4, 'confiable' => 4, 'confiar' => 3, 'antecedent' => 5],
                'reply' => "Los profesores que reciben solicitudes pasan por una revisión manual del equipo de MOVA (identidad, formación y antecedentes). Además, antes de pedir la clase puedes ver el perfil, la tarifa y las valoraciones del profesor, y después recibes un reporte de aprendizaje de cada clase.\n\n[Ver profesores]",
            ],
            'account_access' => [
                'keywords' => ['contrasen' => 5, 'olvid' => 5, 'no puedo iniciar' => 5, 'no puedo entrar a mi cuenta' => 6, 'recuperar cuenta' => 6, 'restablec' => 5, 'iniciar sesion' => 4, 'login' => 3],
                'reply' => "Si olvidaste tu contraseña, puedes crear una nueva con tu correo: [Recuperar contraseña]. Si aún no tienes cuenta: [Crear cuenta]. Si inicias sesión con Google, usa el mismo botón de Google.",
            ],
            'verify_email' => [
                'keywords' => ['verificar correo' => 6, 'verificar mi correo' => 6, 'no me llego' => 5, 'no llega' => 4, 'correo de verificacion' => 6, 'no recibi' => 4, 'spam' => 3, 'correo' => 2, 'email' => 2],
                'reply' => "Revisa también la carpeta de **spam o promociones**. Si no te llegó el correo de verificación, entra a tu cuenta y pide que lo reenviemos. Si sigue sin llegar, escríbenos a **{$supportEmail}**.",
            ],
            'delete_account' => [
                'keywords' => ['eliminar cuenta' => 6, 'borrar cuenta' => 6, 'dar de baja' => 5, 'eliminar mis datos' => 6, 'borrar mis datos' => 6, 'mis datos' => 3, 'privacidad' => 4, 'datos personales' => 5, 'eliminar' => 2],
                'reply' => "Puedes eliminar tu cuenta desde tu **Perfil**. Si tienes historial de clases o créditos, la cuenta se anonimiza (se borran tu nombre, correo, teléfono y foto) y se conservan solo los registros necesarios. Más detalle: [Privacidad]",
            ],
            'complaints' => [
                'keywords' => ['reclam' => 6, 'queja' => 6, 'libro de reclamaciones' => 7, 'denunci' => 4, 'mal servicio' => 4],
                'reply' => "Puedes registrar un reclamo o una queja en nuestro **Libro de Reclamaciones** y te responderemos dentro del plazo legal.\n\n[Libro de Reclamaciones]",
            ],
            'contact' => [
                'keywords' => ['contact' => 5, 'soporte' => 5, 'ayuda' => 3, 'correo de soporte' => 6, 'hablar con' => 3, 'humano' => 4, 'atencion' => 3, 'telefono' => 3, 'whatsapp' => 3],
                'reply' => "Puedes escribirnos a **{$supportEmail}** y te responderemos lo antes posible. Para reclamos formales usa el [Libro de Reclamaciones].",
            ],
            'founders' => [
                'keywords' => ['creador' => 6, 'fundador' => 6, 'quien creo' => 6, 'quienes crearon' => 6, 'quien fundo' => 6, 'quienes fundaron' => 6, 'crearon' => 6, 'duenos' => 4],
                'reply' => 'MOVA fue creada y fundada por **Elias J. Paz** y **Abel Castillo**, dos socios apasionados por la educación.',
            ],
            'schedule' => [
                'keywords' => ['horario' => 3, 'disponibilidad' => 4, 'a que hora' => 4, 'fin de semana' => 4, 'fines de semana' => 4, 'sabado' => 3, 'domingo' => 3, 'cuanto dura' => 5, 'duracion' => 4],
                'reply' => "Cada profesor publica su disponibilidad semanal y tú propones el día y la hora al pedir la clase; el profesor acepta o te propone otro horario. Cada hora (o fracción) de clase consume 1 crédito del profesor.\n\n[Solicitar Clase]",
            ],
            'subjects' => [
                'keywords' => ['materia' => 5, 'curso' => 4, 'matematic' => 3, 'ingles' => 3, 'fisica' => 3, 'program' => 3, 'primaria' => 3, 'secundaria' => 3],
                'reply' => "Hoy puedes pedir clases de: {$subjects}. Iremos sumando más materias.\n\n[Solicitar Clase]",
            ],
        ];
    }

    private function subjectNames(): string
    {
        try {
            $names = Subject::query()->orderBy('name')->pluck('name')->all();
        } catch (\Throwable) {
            $names = [];
        }

        return $names === [] ? 'varias materias de primaria y secundaria' : implode(', ', $names);
    }

    /** Detecta una consulta de tarea/ejercicio: Movi no la resuelve y deriva a un profesor. */
    private function looksLikeHomework(string $normalized): bool
    {
        if (preg_match('/\d\s*[\+\-\*\/x÷^]\s*\d/u', $normalized)) {
            return true;
        }

        return (bool) preg_match('/\b(resuelve|resolver|resuelveme|calcula|calcular|cuanto es|cuanto da|despeja|deriva|integra|simplifica|factoriza|ecuacion|ecuaciones|ejercicio|ejercicios|tarea|tareas|problema de|dame la respuesta|respuesta de)\b/u', $normalized);
    }

    /**
     * @return array{reply:string, intent:string}
     */
    public function answer(string $message, ?string $role = null): array
    {
        $supportEmail = (string) config('legal.support_email');
        $normalized = $this->normalize($message);

        if ($normalized === '') {
            return ['intent' => 'empty', 'reply' => '¿En qué te puedo ayudar? Escríbeme tu pregunta sobre MOVA.'];
        }

        $best = null;
        $bestScore = 0;

        foreach ($this->topics($supportEmail) as $id => $topic) {
            $score = 0;
            foreach ($topic['keywords'] as $keyword => $weight) {
                if ($this->containsPhrase($normalized, (string) $keyword)) {
                    $score += $weight;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $id;
            }
        }

        // Una tarea escolar gana sobre temas genéricos (p. ej. «ayuda»), pero no sobre una pregunta clara de uso de la plataforma.
        if ($this->looksLikeHomework($normalized) && $bestScore < 5) {
            return [
                'intent' => 'homework',
                'reply' => "Yo no resuelvo tareas ni ejercicios, pero nuestros profesores sí pueden ayudar a tu hijo/a a entenderlos paso a paso, en vivo. Pide una clase y cuéntales qué tema necesita reforzar.\n\n[Solicitar Clase]",
            ];
        }

        if ($best === null || $bestScore < 2) {
            return [
                'intent' => 'fallback',
                'reply' => "No estoy seguro de haber entendido. Puedo ayudarte con: cómo pedir una clase, créditos y pagos, ser profesor, seguridad, tu cuenta o reclamos. Prueba escribiéndolo con otras palabras o escríbenos a **{$supportEmail}**.",
            ];
        }

        $reply = $this->topics($supportEmail)[$best]['reply'];

        // Quien ya es profesor no necesita «crear cuenta»; le damos su atajo.
        if ($role === 'teacher' && $best === 'credits') {
            $reply .= "\n\n[Mis créditos]";
        }

        return ['intent' => $best, 'reply' => $reply];
    }

    private function normalize(string $text): string
    {
        $ascii = Str::lower(Str::ascii($text));
        // Conserva letras, números y los operadores que usa la detección de tareas.
        $clean = preg_replace('/[^a-z0-9\+\-\*\/\^\s]/u', ' ', $ascii) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
    }

    /** Coincidencia por frase: inicio de palabra exacto y sufijo libre en la última palabra (acepta plurales y conjugaciones). */
    private function containsPhrase(string $haystack, string $needle): bool
    {
        // Inicio de palabra obligatorio; la última palabra admite sufijo (credito→creditos, cancel→cancelo/cancelar).
        return (bool) preg_match('/(?<![a-z0-9])'.preg_quote($needle, '/').'[a-z0-9]*(?![a-z0-9])/u', $haystack);
    }
}
