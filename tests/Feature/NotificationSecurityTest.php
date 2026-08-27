<?php

namespace Tests\Feature;

use App\Models\ClassRequest;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\ClassConfirmedNotification;
use App\Notifications\ClassReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * C-3 — La sala de videollamada es un token de acceso (ver Lesson::$hidden) y el
 * único punto autorizado para revelarla es LessonController::join(), que exige
 * policy + estado válido. Estas pruebas garantizan que ninguna notificación
 * vuelva a difundirla por email, WhatsApp, base de datos o broadcast.
 *
 * Se comprueba la ausencia del VALOR real de jitsi_room, no solo la cadena
 * "meet.jit.si": cambiar de proveedor de vídeo no debe hacer que estas pruebas
 * pasen por accidente mientras la sala se sigue filtrando.
 */
class NotificationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const ROOM = 'mova-lesson-999-SalaSecretaDeUnMenor';

    // ── Payload de base de datos (canal 'database') ──────────────────────────

    public function test_reminder_database_payload_does_not_expose_the_room(): void
    {
        [$lesson, $parent] = $this->scenario();

        $payload = (new ClassReminderNotification($lesson, '10m'))->toArray($parent);

        $this->assertArrayNotHasKey('jitsi_url', $payload);
        $this->assertPayloadIsClean($payload);
    }

    public function test_confirmed_database_payload_does_not_expose_the_room(): void
    {
        [$lesson, $parent] = $this->scenario();

        $payload = (new ClassConfirmedNotification($lesson))->toArray($parent);

        $this->assertArrayNotHasKey('jitsi_url', $payload);
        $this->assertPayloadIsClean($payload);
    }

    /**
     * toBroadcast() reutiliza toArray(), así que Pusher es un cuarto canal de
     * fuga que es fácil pasar por alto al auditar solo mail/whatsapp/database.
     */
    public function test_confirmed_broadcast_payload_does_not_expose_the_room(): void
    {
        [$lesson, $parent] = $this->scenario();

        $broadcast = (new ClassConfirmedNotification($lesson))->toBroadcast($parent);

        $this->assertArrayNotHasKey('jitsi_url', $broadcast->data);
        $this->assertPayloadIsClean($broadcast->data);
    }

    // ── Email ────────────────────────────────────────────────────────────────

    public function test_reminder_mail_does_not_expose_the_room(): void
    {
        [$lesson, $parent] = $this->scenario();

        $mail = (new ClassReminderNotification($lesson, '24h'))->toMail($parent);

        $this->assertMailIsClean($mail);
    }

    public function test_confirmed_mail_does_not_expose_the_room(): void
    {
        [$lesson, $parent] = $this->scenario();

        $mail = (new ClassConfirmedNotification($lesson))->toMail($parent);

        $this->assertMailIsClean($mail);
    }

    // ── WhatsApp ─────────────────────────────────────────────────────────────

    public function test_reminder_whatsapp_does_not_expose_the_room(): void
    {
        [$lesson, $parent] = $this->scenario();

        $text = (new ClassReminderNotification($lesson, '2h'))->toWhatsApp($parent);

        $this->assertStringNotContainsString(self::ROOM, $text);
        $this->assertStringNotContainsString('meet.jit.si', $text);
    }

    public function test_confirmed_whatsapp_does_not_expose_the_room(): void
    {
        [$lesson, $parent] = $this->scenario();

        $text = (new ClassConfirmedNotification($lesson))->toWhatsApp($parent);

        $this->assertStringNotContainsString(self::ROOM, $text);
        $this->assertStringNotContainsString('meet.jit.si', $text);
    }

    // ── Deep link por rol ────────────────────────────────────────────────────

    public function test_mail_cta_points_to_the_parent_class_list_for_parents(): void
    {
        [$lesson, $parent] = $this->scenario();

        $this->assertStringEndsWith(
            route('parent.lessons', [], false),
            (new ClassConfirmedNotification($lesson))->toMail($parent)->actionUrl
        );
        $this->assertStringEndsWith(
            route('parent.lessons', [], false),
            (new ClassReminderNotification($lesson, '10m'))->toMail($parent)->actionUrl
        );
    }

    public function test_mail_cta_points_to_the_teacher_class_list_for_teachers(): void
    {
        [$lesson, , $teacher] = $this->scenario();

        $this->assertStringEndsWith(
            route('teacher.lessons', [], false),
            (new ClassConfirmedNotification($lesson))->toMail($teacher)->actionUrl
        );
        $this->assertStringEndsWith(
            route('teacher.lessons', [], false),
            (new ClassReminderNotification($lesson, '10m'))->toMail($teacher)->actionUrl
        );
    }

    // ── Regresión global sobre todo el directorio ────────────────────────────

    /**
     * Barrido de todo app/Notifications en vez de instanciar las 21 clases (cada
     * una con su propio constructor). Ninguna notificación debe siquiera
     * mencionar jitsi_room: si una futura necesita la sala, debe pasar por
     * join(), no construir la URL por su cuenta.
     */
    public function test_no_notification_class_references_the_video_room(): void
    {
        $offenders = [];

        foreach (glob(app_path('Notifications/*.php')) as $file) {
            if (Str::contains($this->codeWithoutComments($file), ['meet.jit.si', 'jitsi_room', 'jitsi_url'])) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'Estas notificaciones exponen la sala: '.implode(', ', $offenders));
    }

    /**
     * Se descartan los comentarios: lo que debe estar libre de la sala es el
     * código, no la prosa. Documentar POR QUÉ no se enlaza jitsi_room es
     * deseable y no debe hacer fallar esta comprobación.
     */
    private function codeWithoutComments(string $file): string
    {
        $code = '';

        foreach (token_get_all(file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    // ── Comando de purga histórica ───────────────────────────────────────────

    public function test_purge_removes_only_the_jitsi_url_key(): void
    {
        $id = $this->storedNotification([
            'type'       => 'class_confirmed',
            'lesson_id'  => 5,
            'start_time' => '2026-08-02T08:18:00.000000Z',
            'jitsi_url'  => 'https://meet.jit.si/'.self::ROOM,
            'message'    => 'Su clase ha sido confirmada.',
        ]);

        $this->artisan('mova:purge-jitsi-urls')->assertSuccessful();

        $data = json_decode(DB::table('notifications')->where('id', $id)->value('data'), true);

        $this->assertArrayNotHasKey('jitsi_url', $data);
        $this->assertSame('class_confirmed', $data['type']);
        $this->assertSame(5, $data['lesson_id']);
        $this->assertSame('2026-08-02T08:18:00.000000Z', $data['start_time']);
        $this->assertSame('Su clase ha sido confirmada.', $data['message']);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_purge_is_idempotent(): void
    {
        $this->storedNotification([
            'type'      => 'class_reminder',
            'jitsi_url' => 'https://meet.jit.si/'.self::ROOM,
            'message'   => 'Su clase empieza pronto.',
        ]);

        $this->artisan('mova:purge-jitsi-urls')->assertSuccessful();
        $first = DB::table('notifications')->value('data');

        $this->artisan('mova:purge-jitsi-urls')->assertSuccessful();

        $this->assertSame($first, DB::table('notifications')->value('data'));
    }

    public function test_purge_dry_run_changes_nothing(): void
    {
        $this->storedNotification([
            'type'      => 'class_reminder',
            'jitsi_url' => 'https://meet.jit.si/'.self::ROOM,
            'message'   => 'Su clase empieza pronto.',
        ]);

        $before = DB::table('notifications')->value('data');

        $this->artisan('mova:purge-jitsi-urls', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, DB::table('notifications')->value('data'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: Lesson, 1: User, 2: User} [$lesson, $parent, $teacher] */
    private function scenario(): array
    {
        $teacher = $this->userWithRole('teacher');
        $profile = TeacherProfile::create([
            'user_id'           => $teacher->id,
            'is_verified'       => true,
            'credits_available' => 0,
            'credits_reserved'  => 1,
        ]);

        $subject = Subject::create([
            'name'  => 'Materia '.fake()->unique()->numerify('####'),
            'level' => 'secundaria',
        ]);

        $parent = $this->userWithRole('parent');
        $student = Student::create([
            'parent_user_id' => $parent->id,
            'first_name'     => 'Alumno',
            'last_name'      => 'Prueba',
            'grade_level'    => 'secundaria',
        ]);
        $request = ClassRequest::create([
            'student_id'  => $student->id,
            'subject_id'  => $subject->id,
            'help_needed' => 'Necesita reforzar el tema.',
            'status'      => 'accepted',
        ]);

        $lesson = Lesson::create([
            'teacher_profile_id' => $profile->id,
            'student_id'         => $student->id,
            'class_request_id'   => $request->id,
            'start_time'         => now()->addHour(),
            'duration_minutes'   => 60,
            'status'             => 'scheduled',
            'jitsi_room'         => self::ROOM,
        ]);

        return [$lesson, $parent, $teacher];
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->assignRole($role);

        return $user;
    }

    private function storedNotification(array $data): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id'              => $id,
            'type'            => ClassConfirmedNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id'   => $this->userWithRole('parent')->id,
            'data'            => json_encode($data),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return $id;
    }

    /** Ningún valor del payload, a cualquier profundidad, puede contener la sala. */
    private function assertPayloadIsClean(array $payload): void
    {
        $flat = '';
        array_walk_recursive($payload, function ($value) use (&$flat) {
            $flat .= is_scalar($value) ? (string) $value : '';
        });

        $this->assertStringNotContainsString(self::ROOM, $flat);
        $this->assertStringNotContainsString('meet.jit.si', $flat);
    }

    private function assertMailIsClean(\Illuminate\Notifications\Messages\MailMessage $mail): void
    {
        $rendered = (string) $mail->render();

        $this->assertStringNotContainsString(self::ROOM, $rendered);
        $this->assertStringNotContainsString('meet.jit.si', $rendered);
        $this->assertStringNotContainsString(self::ROOM, (string) $mail->actionUrl);
    }
}
