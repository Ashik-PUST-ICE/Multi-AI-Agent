<?php

namespace Tests\Feature;

use App\Jobs\DeliverUserNotificationJob;
use App\Models\NotificationChannelConfig;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\NotificationDeliveryService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationChannelsTest extends TestCase
{
    use DatabaseMigrations;

    public function test_channel_secrets_are_encrypted_and_not_returned_by_api(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/notifications/channels/telegram', [
            'enabled' => true,
            'bot_token' => '123456:private-token',
            'chat_id' => '123456789',
        ])->assertOk()
            ->assertJsonMissingPath('bot_token')
            ->assertJsonMissingPath('settings');

        $config = NotificationChannelConfig::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertStringNotContainsString('private-token', $config->getRawOriginal('settings'));
        $this->assertSame('123456:private-token', $config->settings['bot_token']);

        $this->getJson('/api/v1/notifications/channels')->assertOk()
            ->assertJsonPath('channels.telegram.configured', true)
            ->assertJsonPath('channels.telegram.enabled', true)
            ->assertJsonMissingPath('settings')
            ->assertJsonMissingPath('private_key');
    }

    public function test_push_credentials_and_browser_endpoints_are_validated_and_protected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/notifications/push/vapid', [
            'subject' => 'mailto:owner@example.com',
        ])->assertOk()
            ->assertJsonMissingPath('private_key');

        $config = NotificationChannelConfig::query()
            ->where('user_id', $user->id)
            ->where('channel', 'push')
            ->firstOrFail();
        $this->assertFalse($config->enabled);
        $this->assertNotEmpty($config->settings['private_key']);
        $this->assertStringNotContainsString(
            $config->settings['private_key'],
            $config->getRawOriginal('settings'),
        );

        $this->postJson('/api/v1/notifications/push/subscriptions', [
            'endpoint' => 'http://fcm.googleapis.com/push',
            'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-token'],
        ])->assertUnprocessable();
        $this->postJson('/api/v1/notifications/push/subscriptions', [
            'endpoint' => 'https://attacker.example/push',
            'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-token'],
        ])->assertUnprocessable();
        $this->postJson('/api/v1/notifications/push/subscriptions', [
            'endpoint' => 'https://push.services.mozilla.com/push/id',
            'keys' => ['p256dh' => 'public-key', 'auth' => 'secret-auth-token'],
        ])->assertCreated();

        $subscription = WebPushSubscription::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertStringNotContainsString('mozilla.com', $subscription->getRawOriginal('endpoint'));
        $this->assertStringNotContainsString('secret-auth-token', $subscription->getRawOriginal('auth_token'));
        $this->assertSame('https://push.services.mozilla.com/push/id', $subscription->endpoint);
        $this->assertSame('secret-auth-token', $subscription->auth_token);
        $this->assertFalse($response->json('enabled'));
    }

    public function test_telegram_delivery_is_logged(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $user->notificationChannels()->create([
            'tenant_id' => $user->tenant_id,
            'channel' => 'telegram',
            'enabled' => true,
            'settings' => ['bot_token' => '123456:private-token', 'chat_id' => '123456789'],
        ]);
        $notification = $user->notifications()->create([
            'tenant_id' => $user->tenant_id,
            'type' => 'tool_approval',
            'priority' => NOTIFICATION_PRIORITY_HIGH,
            'title' => 'Approval required',
            'message' => 'Review in app.',
            'data' => ['delivery_channels' => ['telegram']],
            'external_response_links' => ['approve' => 'https://example.test/one-time-token'],
            'dedupe_key' => 'telegram-approval',
            'status' => NOTIFICATION_STATUS_UNREAD,
            'delivered_at' => now(),
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
        $service = app(NotificationDeliveryService::class);
        $result = $service->deliver($notification->fresh());
        $this->assertSame([['channel' => 'telegram', 'delivered' => true]], $result);
        $this->assertSame(NOTIFICATION_DELIVERY_SENT, NotificationDelivery::query()
            ->where('notification_id', $notification->id)
            ->where('channel', 'telegram')
            ->value('status'));
        $this->assertNull($notification->fresh()->external_response_links);

    }

    public function test_failed_telegram_delivery_retries_without_losing_links(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $user->notificationChannels()->create([
            'tenant_id' => $user->tenant_id,
            'channel' => 'telegram',
            'enabled' => true,
            'settings' => ['bot_token' => '123456:private-token', 'chat_id' => '123456789'],
        ]);
        $retryNotification = $user->notifications()->create([
            'tenant_id' => $user->tenant_id,
            'type' => 'tool_approval',
            'priority' => NOTIFICATION_PRIORITY_HIGH,
            'title' => 'Approval required',
            'message' => 'Review in app.',
            'data' => ['delivery_channels' => ['telegram']],
            'external_response_links' => ['approve' => 'https://example.test/one-time-token-2'],
            'dedupe_key' => 'telegram-approval-retry',
            'status' => NOTIFICATION_STATUS_UNREAD,
            'delivered_at' => now(),
        ]);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false], 503),
        ]);

        $service = app(NotificationDeliveryService::class);
        $service->deliver($retryNotification->fresh());
        $this->assertSame(NOTIFICATION_DELIVERY_FAILED, NotificationDelivery::query()
            ->where('notification_id', $retryNotification->id)
            ->where('channel', 'telegram')
            ->value('status'));
        $this->assertNotNull($retryNotification->fresh()->external_response_links);
        Queue::assertPushed(DeliverUserNotificationJob::class);

        $service->deliver($retryNotification->fresh());
        $service->deliver($retryNotification->fresh());
        $this->assertSame(3, NotificationDelivery::query()
            ->where('notification_id', $retryNotification->id)
            ->where('channel', 'telegram')
            ->value('attempts'));
        $this->assertNull($retryNotification->fresh()->external_response_links);
    }

    public function test_email_and_whatsapp_channels_use_configured_credentials(): void
    {
        Queue::fake();
        Mail::fake();
        $user = User::factory()->create();
        $user->notificationChannels()->createMany([
            [
                'tenant_id' => $user->tenant_id,
                'channel' => 'email',
                'enabled' => true,
                'settings' => [
                    'host' => 'smtp.example.test',
                    'port' => 587,
                    'username' => 'owner',
                    'password' => 'private-smtp-password',
                    'encryption' => 'tls',
                    'from_address' => 'agent@example.test',
                    'recipient' => 'owner@example.test',
                ],
            ],
            [
                'tenant_id' => $user->tenant_id,
                'channel' => 'whatsapp',
                'enabled' => true,
                'settings' => [
                    'access_token' => 'private-whatsapp-token',
                    'phone_number_id' => '123456789',
                    'recipient' => '+15551234567',
                ],
            ],
        ]);
        $notification = $user->notifications()->create([
            'tenant_id' => $user->tenant_id,
            'type' => 'policy_expiring',
            'priority' => NOTIFICATION_PRIORITY_NORMAL,
            'title' => 'Policy reminder',
            'message' => 'An access policy is expiring.',
            'data' => ['delivery_channels' => ['email', 'whatsapp']],
            'dedupe_key' => 'email-whatsapp-test',
            'status' => NOTIFICATION_STATUS_UNREAD,
            'delivered_at' => now(),
        ]);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200),
        ]);

        app(NotificationDeliveryService::class)->deliver($notification->fresh());

        $this->assertSame(
            NOTIFICATION_DELIVERY_SENT,
            NotificationDelivery::query()->where('notification_id', $notification->id)->where('channel', 'email')->value('status'),
        );
        $this->assertSame(
            NOTIFICATION_DELIVERY_SENT,
            NotificationDelivery::query()->where('notification_id', $notification->id)->where('channel', 'whatsapp')->value('status'),
        );
        $this->assertStringNotContainsString(
            'private-smtp-password',
            $user->notificationChannels()->where('channel', 'email')->firstOrFail()->getRawOriginal('settings'),
        );
    }
}
