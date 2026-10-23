<?php

namespace App\Jobs;

use App\Models\AccountDeletionRequest;
use App\Services\CloudinaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Limpieza secundaria tras el cierre inmediato (archivos externos). Idempotente, con reintentos y
 * observable: `cleanup_status` pasa de pending a completed/failed. Nunca bloquea ni revierte el cierre.
 * `mova:account-closure:finish` la reintenta si el worker no llegó a ejecutarla.
 */
class FinishAccountClosureCleanup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public int $deletionRequestId) {}

    public function handle(CloudinaryService $cloudinary): void
    {
        $request = AccountDeletionRequest::find($this->deletionRequestId);
        if (! $request || $request->cleanup_status === 'completed' || $request->cleanup_status === 'not_required') {
            return;
        }

        $request->increment('cleanup_attempts');
        try {
            // El avatar es un archivo propio del titular (public id por usuario): no se tocan recursos compartidos.
            $cloudinary->deleteAvatar($request->user_id);
            $request->forceFill(['cleanup_status' => 'completed'])->save();
        } catch (Throwable $exception) {
            // Sin mensaje de la excepción: puede incluir URLs o credenciales del proveedor.
            $request->forceFill(['cleanup_status' => 'failed'])->save();
            report($exception);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        AccountDeletionRequest::whereKey($this->deletionRequestId)->update(['cleanup_status' => 'failed']);
    }
}
