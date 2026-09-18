<?php

namespace App\Services;

use App\Exceptions\AvatarStorageUnavailable;
use Cloudinary\Api\Upload\UploadApi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CloudinaryService
{
    public function __construct(
        private ?UploadApi $uploadApi = null,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('services.cloudinary.cloud_url'));
    }

    public function uploadAvatar(UploadedFile $file, int $userId): string
    {
        if (! $this->isConfigured() && app()->environment('production')) {
            throw AvatarStorageUnavailable::cloudinaryNotConfigured();
        }

        if ($this->isConfigured()) {
            $result = ($this->uploadApi ?? new UploadApi())->upload($file->getRealPath(), [
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
