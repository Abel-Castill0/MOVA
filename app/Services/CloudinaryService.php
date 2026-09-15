<?php

namespace App\Services;

use App\Exceptions\AvatarStorageUnavailable;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CloudinaryService
{
    public function isConfigured(): bool
    {
        return filled(config('cloudinary.cloud_url'));
    }

    // Sin CLOUDINARY_URL en .env, cae a disco público local — la plataforma
    // sigue funcionando en desarrollo sin depender de una cuenta de terceros.
    //
    // En producción ese fallback está PROHIBIDO (AZ-2): el disco del
    // contenedor es efímero y el avatar desaparecería en el próximo deploy.
    // Se lanza AvatarStorageUnavailable en vez de persistir en silencio.
    public function uploadAvatar(UploadedFile $file, int $userId): string
    {
        if (! $this->isConfigured() && app()->environment('production')) {
            throw AvatarStorageUnavailable::cloudinaryNotConfigured();
        }

        if ($this->isConfigured()) {
            $result = Cloudinary::upload($file->getRealPath(), [
                'folder' => 'mova/avatars',
                'public_id' => "user-{$userId}",
                'overwrite' => true,
                'transformation' => [
                    'width' => 400,
                    'height' => 400,
                    'crop' => 'fill',
                    'gravity' => 'face',
                    'quality' => 'auto',
                    'fetch_format' => 'auto',
                ],
            ]);

            return $result->getSecurePath();
        }

        $path = $file->storeAs('avatars', "user-{$userId}.".$file->extension(), 'public');

        return Storage::disk('public')->url($path);
    }
}
