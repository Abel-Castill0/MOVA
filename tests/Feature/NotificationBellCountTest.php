<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationBellCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_badge_uses_all_unread_notifications_even_though_list_is_limited_to_twenty(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        for ($i = 0; $i < 25; $i++) {
            $this->insertNotification($user, $i);
        }
        $this->insertNotification($other, 99);

        $this->actingAs($user)->get('/notifications')
            ->assertOk()
            ->assertJsonCount(20)
            ->assertHeader('X-Mova-Unread-Count', '25');

        $this->actingAs($other)->get('/notifications')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertHeader('X-Mova-Unread-Count', '1');

        $this->actingAs($user)->post('/notifications/read-all')
            ->assertOk();
        $this->get('/notifications')->assertHeader('X-Mova-Unread-Count', '0');
        $this->actingAs($other)->get('/notifications')->assertHeader('X-Mova-Unread-Count', '1');
    }

    private function insertNotification(User $user, int $index): void
    {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'MOVA\\TestNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['message' => "Prueba {$index}"]),
            'created_at' => now()->subSeconds($index),
            'updated_at' => now(),
            'read_at' => null,
        ]);
    }
}
