<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ZoomService
{
    // ──────────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Create a Zoom meeting.
     *
     * @param  string  $topic           Meeting subject shown in Zoom
     * @param  string  $startTime       ISO-8601 datetime (e.g. "2026-06-15T18:00:00")
     * @param  int     $durationMinutes Duration in minutes
     * @return array{meeting_id: string, join_url: string, password: string}
     *
     * @throws \RuntimeException when credentials are missing or the API returns an error.
     */
    public function createMeeting(string $topic, string $startTime, int $durationMinutes): array
    {
        $this->assertConfigured();

        $token  = $this->getAccessToken();
        $userId = config('zoom.user_id', 'me');

        $response = Http::withToken($token)
            ->post("https://api.zoom.us/v2/users/{$userId}/meetings", [
                'topic'      => $topic,
                'type'       => 2,                       // Scheduled
                'start_time' => $startTime,
                'duration'   => $durationMinutes,
                'timezone'   => config('zoom.timezone', 'America/Lima'),
                'password'   => $this->generatePassword(),
                'settings'   => [
                    'host_video'        => true,
                    'participant_video'  => true,
                    'join_before_host'   => false,
                    'waiting_room'       => true,
                    'auto_recording'     => 'none',
                    'mute_upon_entry'    => false,
                ],
            ]);

        if ($response->failed()) {
            $body = $response->json();
            $msg  = $body['message'] ?? $response->status();
            Log::error('[Zoom] Create meeting failed', ['status' => $response->status(), 'body' => $body]);
            throw new RuntimeException("Zoom API error al crear la reunión: {$msg}");
        }

        $data = $response->json();

        Log::info('[Zoom] Meeting created', [
            'id'    => $data['id'],
            'topic' => $topic,
            'start' => $startTime,
        ]);

        return [
            'meeting_id' => (string) $data['id'],
            'join_url'   => $data['join_url'],
            'password'   => $data['password'] ?? '',
        ];
    }

    /**
     * Delete a Zoom meeting (called when a lesson is cancelled).
     */
    public function deleteMeeting(string $meetingId): void
    {
        if (!$this->isConfigured()) return;

        try {
            $token = $this->getAccessToken();
            Http::withToken($token)
                ->delete("https://api.zoom.us/v2/meetings/{$meetingId}");
        } catch (\Throwable $e) {
            Log::warning('[Zoom] Could not delete meeting ' . $meetingId . ': ' . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function isConfigured(): bool
    {
        $id     = config('zoom.account_id');
        $cid    = config('zoom.client_id');
        $secret = config('zoom.client_secret');

        return $id && $cid && $secret
            && $id !== 'test' && $cid !== 'test' && $secret !== 'test';
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException(
                'Las credenciales de Zoom no están configuradas. ' .
                'Añade ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID y ZOOM_CLIENT_SECRET en el archivo .env.'
            );
        }
    }

    private function getAccessToken(): string
    {
        return Cache::remember('zoom_access_token', 3500, function () {
            $response = Http::asForm()
                ->withBasicAuth(config('zoom.client_id'), config('zoom.client_secret'))
                ->post('https://zoom.us/oauth/token', [
                    'grant_type' => 'account_credentials',
                    'account_id' => config('zoom.account_id'),
                ]);

            if ($response->failed()) {
                $body = $response->json();
                Log::error('[Zoom] OAuth token request failed', $body);
                throw new RuntimeException(
                    'No se pudo obtener el token de Zoom. ' .
                    'Verifica que ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID y ZOOM_CLIENT_SECRET sean correctos.'
                );
            }

            return $response->json('access_token');
        });
    }

    private function generatePassword(): string
    {
        // 6-char alphanumeric password matching Zoom's own format
        return substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 6);
    }
}
