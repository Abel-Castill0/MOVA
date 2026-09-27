<?php

namespace App\Exceptions;

use RuntimeException;

// AZ-2: en producción el filesystem del contenedor es efímero (Azure
// Container Apps), así que guardar un avatar en storage/app/public lo
// perdería en el siguiente redeploy. Sin Cloudinary configurado, la subida
// debe fallar de forma controlada — nunca caer en silencio a disco local.
class AvatarStorageUnavailable extends RuntimeException
{
    public static function cloudinaryNotConfigured(): self
    {
        return new self('El almacenamiento de imágenes no está configurado en este entorno.');
    }
}
