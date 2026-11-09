<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_unread_counter_counts_all_notifications_not_only_the_latest_twenty(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        for ($i = 0; $i < 25; $i++) {
            $this->storeNotification($user, "Aviso {$i}");
        }
        $this->storeNotification($user, 'Ya leído', true);
        $this->storeNotification($other, 'De otra cuenta');

        $this->actingAs($user)->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonCount(20, 'notifications')
            ->assertJsonPath('unread_count', 25)
            ->assertJsonMissing(['message' => 'De otra cuenta']);
    }

    public function test_mark_all_updates_only_the_authenticated_users_unread_notifications(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        for ($i = 0; $i < 25; $i++) {
            $this->storeNotification($user, "Aviso {$i}");
        }
        $this->storeNotification($other, 'Ajeno');

        $this->actingAs($user)->postJson(route('notifications.readAll'))->assertOk();

        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_one_user_cannot_mark_another_users_notification_as_read(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $id = $this->storeNotification($other, 'Notificación privada');

        $this->actingAs($user)->postJson(route('notifications.read', $id))
            ->assertNotFound();

        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    private function storeNotification(User $user, string $message, bool $read = false): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'Tests\\Feature\\NotificationBellTest',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => json_encode(['message' => $message]),
            'read_at' => $read ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
