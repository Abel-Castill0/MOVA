<?php

namespace App\Services;

use App\Exceptions\AvatarStorageUnavailable;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Configuration\Configuration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CloudinaryService
{
    public function __construct(
        private ?UploadApi $uploadApi = null,
    ) {}

    // Fail-closed: "configurado" solo si el PROPIO SDK consigue construir una
    // configuración con credenciales completas desde el URL. Un string no
    // vacío pero malformado (o sin api key/secret) cuenta como NO
    // configurado: en producción eso dispara el error controlado de
    // uploadAvatar() en lugar de una ConfigurationException del SDK en medio
    // de la request, y `filled()` por sí solo no distingue esos casos.
    public function isConfigured(): bool
    {
        $url = config('services.cloudinary.cloud_url');

        if (! is_string($url) || $url === '') {
            return false;
        }

        try {
            // Misma ruta de parsing que ejecutará UploadApi al construirse:
            // si el SDK no acepta esta URL, aquí tampoco cuenta.
            $configuration = new Configuration($url);
        } catch (Throwable) {
            return false;
        }

        // UploadApi firma la subida: sin cloud name + api key + api secret el
        // SDK lanza InvalidArgumentException al llamar (ApiClient::
        // validateAuthorization). isConfigured() tiene que predecirlo.
        return filled($configuration->cloud->cloudName)
            && filled($configuration->cloud->apiKey)
            && filled($configuration->cloud->apiSecret);
    }

    public function uploadAvatar(UploadedFile $file, int $userId): string
    {
        if (! $this->isConfigured() && app()->environment('production')) {
            throw AvatarStorageUnavailable::cloudinaryNotConfigured();
        }

        if ($this->isConfigured()) {
            // Se pasa al SDK el MISMO URL que isConfigured() acaba de
            // validar, nunca getenv('CLOUDINARY_URL') global: con config
            // cacheada en producción, config() y getenv() pueden divergir y
            // entonces isConfigured()==true no garantizaría que un
            // `new UploadApi()` sin argumentos tenga credenciales reales.
            $result = ($this->uploadApi ?? new UploadApi(config('services.cloudinary.cloud_url')))->upload($file->getRealPath(), [
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

            return $result['secure_url'];
        }

        $path = $file->storeAs('avatars', "user-{$userId}.".$file->extension(), 'public');

        return Storage::disk('public')->url($path);
    }
}
