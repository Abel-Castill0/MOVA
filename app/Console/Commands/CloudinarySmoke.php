<?php

namespace App\Console\Commands;

use App\Services\CloudinaryService;
use App\Support\QaDatabaseGuard;
use Cloudinary\Api\Admin\AdminApi;
use Cloudinary\Api\Exception\NotFound;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * QA: demuestra el almacenamiento PERSISTENTE de avatares con Cloudinary de
 * punta a punta usando la misma clase que la aplicación (CloudinaryService):
 *
 *   1. sube un PNG sintético con un id de usuario RESERVADO (nunca un usuario
 *      real: ≥ 9 000 000 000);
 *   2. lo lee desde fuera, por HTTPS, desde el CDN de Cloudinary;
 *   3. confirma que NO quedó ninguna copia en el disco local del contenedor
 *      (que es efímero);
 *   4. lo borra con deleteAvatar() y confirma contra la Admin API (fuente de
 *      verdad, no el CDN) que ya no existe.
 *
 * Solo APP_ENV local|testing; exige CLOUDINARY_URL de un cloud de PRUEBA; nunca
 * imprime la URL de configuración ni sus credenciales.
 */
class CloudinarySmoke extends Command
{
    protected $signature = 'mova:cloudinary-smoke';

    protected $description = 'QA: sube, lee y borra un avatar sintético en Cloudinary y confirma que no se usa el disco local.';

    public function handle(CloudinaryService $cloudinary): int
    {
        try {
            QaDatabaseGuard::assertSafeEnvironment();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $cloudinary->isConfigured()) {
            $this->error('CLOUDINARY_URL no está configurada (o está incompleta). Cárgala en qa/.env.sandbox con un cloud de PRUEBA.');

            return self::FAILURE;
        }

        $userId = 9_000_000_000 + random_int(1, 999_999);
        $publicId = CloudinaryService::avatarPublicId($userId);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $tmp = tempnam(sys_get_temp_dir(), 'mova-avatar-').'.png';
        file_put_contents($tmp, $png);

        $steps = [];
        $ok = true;

        try {
            $url = $cloudinary->uploadAvatar(new UploadedFile($tmp, 'qa.png', 'image/png', null, true), $userId);

            $isCdn = str_starts_with($url, 'https://res.cloudinary.com/');
            $steps[] = ['subida a Cloudinary', $isCdn ? 'OK (https://res.cloudinary.com/…)' : 'FALLÓ (la URL no es del CDN de Cloudinary)'];
            $ok = $ok && $isCdn;

            $response = Http::timeout(20)->get($url);
            $served = $response->successful() && str_starts_with((string) $response->header('Content-Type'), 'image/');
            $steps[] = ['lectura externa por HTTPS', $served ? 'OK ('.$response->status().', '.$response->header('Content-Type').')' : 'FALLÓ (HTTP '.$response->status().')'];
            $ok = $ok && $served;

            $local = Storage::disk('public')->exists('avatars/user-'.$userId.'.png');
            $steps[] = ['sin copia en disco local', $local ? 'FALLÓ (quedó un archivo local)' : 'OK'];
            $ok = $ok && ! $local;

            $cloudinary->deleteAvatar($userId);

            $gone = $this->assetIsGone($publicId);
            $steps[] = ['borrado real (Admin API)', $gone ? 'OK (el recurso ya no existe)' : 'FALLÓ (el recurso sigue existiendo)'];
            $ok = $ok && $gone;
        } catch (Throwable $e) {
            $ok = false;
            $steps[] = ['excepción', class_basename($e)];
        } finally {
            @unlink($tmp);
            // Limpieza defensiva: si algo falló a mitad, no dejar el recurso de prueba.
            try {
                $cloudinary->deleteAvatar($userId);
            } catch (Throwable) {
            }
        }

        $this->table(['paso', 'resultado'], $steps);
        $this->line('Alcance: Cloudinary real con credenciales de prueba. No prueba el flujo de subida desde el navegador ni producción.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function assetIsGone(string $publicId): bool
    {
        $admin = new AdminApi((string) config('services.cloudinary.cloud_url'));

        // La Admin API es la fuente de verdad; se reintenta unos segundos por
        // consistencia eventual tras el destroy().
        for ($i = 0; $i < 5; $i++) {
            try {
                $admin->asset($publicId);
            } catch (NotFound) {
                return true;
            } catch (Throwable) {
                return false;
            }
            sleep(1);
        }

        return false;
    }
}
