<?php

namespace Tests\Feature;

use App\Exceptions\AvatarStorageUnavailable;
use App\Services\CloudinaryService;
use Cloudinary\Api\ApiResponse;
use Cloudinary\Api\Upload\UploadApi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class CloudinaryServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_production_without_cloudinary_throws_avatar_storage_unavailable(): void
    {
        config(['app.env' => 'production']);
        config(['services.cloudinary.cloud_url' => null]);
        $this->app['env'] = 'production';

        $service = new CloudinaryService();
        $file = UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg');

        $this->expectException(AvatarStorageUnavailable::class);
        $service->uploadAvatar($file, 1);
    }

    public function test_non_production_without_cloudinary_uses_local_storage_fallback(): void
    {
        config(['app.env' => 'local']);
        config(['services.cloudinary.cloud_url' => null]);

        Storage::fake('public');

        $service = new CloudinaryService();
        $file = UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg');

        $url = $service->uploadAvatar($file, 42);

        $this->assertStringContainsString('/storage/avatars/', $url);
        Storage::disk('public')->assertExists('avatars/user-42.jpg');
    }

    public function test_configured_cloudinary_uses_deterministic_public_id(): void
    {
        config(['services.cloudinary.cloud_url' => 'cloudinary://key:secret@cloud']);

        $fakeResponse = new ApiResponse(
            ['secure_url' => 'https://res.cloudinary.com/cloud/image/upload/v1/mova/avatars/user-7.jpg'],
            ['x-featureratelimit-reset' => '0', 'x-featureratelimit-limit' => '0', 'x-featureratelimit-remaining' => '0']
        );

        $mockUploadApi = Mockery::mock(UploadApi::class);
        $mockUploadApi
            ->shouldReceive('upload')
            ->once()
            ->with(
                Mockery::type('string'),
                Mockery::on(function ($options) {
                    return $options['folder'] === 'mova/avatars'
                        && $options['public_id'] === 'user-7'
                        && $options['overwrite'] === true;
                })
            )
            ->andReturn($fakeResponse);

        $service = new CloudinaryService($mockUploadApi);
        $file = UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg');

        $url = $service->uploadAvatar($file, 7);

        $this->assertSame('https://res.cloudinary.com/cloud/image/upload/v1/mova/avatars/user-7.jpg', $url);
    }

    public function test_upload_options_preserve_transformation(): void
    {
        config(['services.cloudinary.cloud_url' => 'cloudinary://key:secret@cloud']);

        $fakeResponse = new ApiResponse(
            ['secure_url' => 'https://res.cloudinary.com/cloud/image/upload/test.jpg'],
            ['x-featureratelimit-reset' => '0', 'x-featureratelimit-limit' => '0', 'x-featureratelimit-remaining' => '0']
        );

        $mockUploadApi = Mockery::mock(UploadApi::class);
        $mockUploadApi
            ->shouldReceive('upload')
            ->once()
            ->with(
                Mockery::type('string'),
                Mockery::on(function ($options) {
                    $t = $options['transformation'] ?? [];

                    return $options['overwrite'] === true
                        && ($t['width'] ?? 0) === 400
                        && ($t['height'] ?? 0) === 400
                        && ($t['crop'] ?? '') === 'fill'
                        && ($t['gravity'] ?? '') === 'face'
                        && ($t['quality'] ?? '') === 'auto'
                        && ($t['fetch_format'] ?? '') === 'auto';
                })
            )
            ->andReturn($fakeResponse);

        $service = new CloudinaryService($mockUploadApi);
        $file = UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg');

        $service->uploadAvatar($file, 99);

        $this->assertTrue(true);
    }

    public function test_secure_url_from_provider_is_returned(): void
    {
        config(['services.cloudinary.cloud_url' => 'cloudinary://key:secret@cloud']);

        $expectedUrl = 'https://res.cloudinary.com/demo/image/upload/v1/mova/avatars/user-5.jpg';
        $fakeResponse = new ApiResponse(
            ['secure_url' => $expectedUrl],
            ['x-featureratelimit-reset' => '0', 'x-featureratelimit-limit' => '0', 'x-featureratelimit-remaining' => '0']
        );

        $mockUploadApi = Mockery::mock(UploadApi::class);
        $mockUploadApi
            ->shouldReceive('upload')
            ->once()
            ->andReturn($fakeResponse);

        $service = new CloudinaryService($mockUploadApi);
        $file = UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg');

        $result = $service->uploadAvatar($file, 5);

        $this->assertSame($expectedUrl, $result);
    }

    // Un string no vacío NO es sinónimo de configurado: si CLOUDINARY_URL
    // no es una cloudinary URL válida (typo, secret rotado a mano, valor
    // placeholder), isConfigured() debe decir false para que el fallo en
    // producción sea el controlado (AvatarStorageUnavailable) y no una
    // excepción del SDK a mitad de la request.
    public function test_malformed_cloudinary_url_counts_as_not_configured(): void
    {
        config(['services.cloudinary.cloud_url' => 'no-es-una-url-cloudinary']);

        $service = new CloudinaryService();

        $this->assertFalse($service->isConfigured());
    }

    // Mismo criterio para credenciales INCOMPLETAS: sin api key/secret la
    // subida firmada no puede funcionar (el SDK lanzaría
    // InvalidArgumentException al llamar), así que no es "configurado".
    public function test_cloudinary_url_without_credentials_counts_as_not_configured(): void
    {
        config(['services.cloudinary.cloud_url' => 'cloudinary://demo']);

        $service = new CloudinaryService();

        $this->assertFalse($service->isConfigured());
    }

    public function test_production_with_malformed_url_fails_closed(): void
    {
        config(['services.cloudinary.cloud_url' => 'no-es-una-url-cloudinary']);
        $this->app['env'] = 'production';

        $service = new CloudinaryService();
        $file = UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg');

        $this->expectException(AvatarStorageUnavailable::class);
        $service->uploadAvatar($file, 1);
    }

    // P0-F — el destroy usa SOLO el public_id derivado del id del usuario.
    public function test_delete_avatar_destroys_server_derived_public_id_only(): void
    {
        config(['services.cloudinary.cloud_url' => 'cloudinary://key:secret@cloud']);

        $mockUploadApi = Mockery::mock(UploadApi::class);
        $mockUploadApi->shouldReceive('destroy')->once()
            ->with('mova/avatars/user-7', ['invalidate' => true]);

        (new CloudinaryService($mockUploadApi))->deleteAvatar(7);
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_delete_avatar_remote_failure_is_reported_not_thrown(): void
    {
        config(['services.cloudinary.cloud_url' => 'cloudinary://key:secret@cloud']);

        $mockUploadApi = Mockery::mock(UploadApi::class);
        $mockUploadApi->shouldReceive('destroy')->once()->andThrow(new \RuntimeException('network'));

        (new CloudinaryService($mockUploadApi))->deleteAvatar(7);
        $this->assertTrue(true);
    }

    public function test_delete_avatar_removes_only_that_users_local_fallback_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/user-7.jpg', 'x');
        Storage::disk('public')->put('avatars/user-70.jpg', 'x');

        (new CloudinaryService())->deleteAvatar(7);

        Storage::disk('public')->assertMissing('avatars/user-7.jpg');
        Storage::disk('public')->assertExists('avatars/user-70.jpg');
    }
}
