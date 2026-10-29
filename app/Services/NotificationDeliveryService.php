<?php

namespace App\Services;

use App\Jobs\DeliverUserNotificationJob;
use App\Models\NotificationChannelConfig;
use App\Models\NotificationDelivery;
use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;
use Throwable;

class NotificationDeliveryService
{
    /**
     * @return list<array{channel: string, delivered: bool}>
     */
    public function deliver(UserNotification $notification): array
    {
        $user = $notification->user;
        if ($user === null) {
            throw new RuntimeException('Cannot deliver a notification without its owner.');
        }
        $deferUntil = $this->deferUntil($notification);
        if ($deferUntil !== null) {
            DeliverUserNotificationJob::dispatch($notification->id)->delay($deferUntil);

            return [];
        }

        $settings = $user->notificationChannels()->where('enabled', true)->get()->keyBy('channel');
        $accessSettings = $user->accessSettings;
        $preferences = $accessSettings?->notification_preferences ?? [];
        $explicitChannels = $notification->data['delivery_channels'] ?? null;
        $channels = $explicitChannels
            ?? $preferences[$notification->type]['channels']
            ?? $preferences['default_channels']
            ?? ['in_app'];
        $channels = array_values(array_unique(array_filter($channels, 'is_string')));
        $inAppEnabled = $preferences[$notification->type]['in_app'] ?? true;
        if ($explicitChannels === null && $inAppEnabled && ! in_array('in_app', $channels, true)) {
            $channels[] = 'in_app';
        } elseif (! $inAppEnabled) {
            $channels = array_values(array_filter($channels, static fn (string $channel): bool => $channel !== 'in_app'));
        }
        $results = [];
        $links = $notification->external_response_links ?? [];
        $retryDelayMinutes = null;

        foreach ($channels as $channel) {
            $delivery = NotificationDelivery::query()->firstOrCreate(
                ['notification_id' => $notification->id, 'channel' => $channel],
                ['user_id' => $user->id, 'status' => NOTIFICATION_DELIVERY_PENDING],
            );
            if ($delivery->status === NOTIFICATION_DELIVERY_SENT) {
                $results[] = ['channel' => $channel, 'delivered' => true];

                continue;
            }

            try {
                $this->sendChannel($channel, $settings->get($channel), $notification, $links);
                $delivery->update([
                    'status' => NOTIFICATION_DELIVERY_SENT,
                    'attempts' => $delivery->attempts + 1,
                    'failure_reason' => null,
                    'attempted_at' => now(),
                    'delivered_at' => now(),
                ]);
                $results[] = ['channel' => $channel, 'delivered' => true];
            } catch (Throwable $exception) {
                Log::warning('Notification channel delivery failed.', [
                    'notification_id' => $notification->id,
                    'channel' => $channel,
                    'exception' => class_basename($exception),
                ]);
                $delivery->update([
                    'status' => NOTIFICATION_DELIVERY_FAILED,
                    'attempts' => $delivery->attempts + 1,
                    'failure_reason' => class_basename($exception),
                    'attempted_at' => now(),
                ]);
                $results[] = ['channel' => $channel, 'delivered' => false];
                if ($delivery->attempts < 3) {
                    $retryDelayMinutes = max(
                        $retryDelayMinutes ?? 0,
                        5 * (2 ** max(0, $delivery->attempts - 1)),
                    );
                }
            }
        }

        if ($retryDelayMinutes !== null) {
            DeliverUserNotificationJob::dispatch($notification->id)->delay(now()->addMinutes($retryDelayMinutes));
        } elseif ($notification->external_response_links !== null) {
            $notification->update(['external_response_links' => null]);
        }

        return $results;
    }

    private function deferUntil(UserNotification $notification): ?Carbon
    {
        if ($notification->priority >= NOTIFICATION_PRIORITY_HIGH) {
            return null;
        }

        $settings = $notification->user?->accessSettings;
        if ($settings === null) {
            return null;
        }
        $dueAt = null;
        $now = now();

        if ($settings->digest_time !== null) {
            $digestAt = $now->copy()->setTimeFromTimeString($settings->digest_time);
            if ($digestAt->lessThanOrEqualTo($now)) {
                $digestAt->addDay();
            }
            $dueAt = $digestAt;
        }

        $quietStart = $settings->quiet_hours_start;
        $quietEnd = $settings->quiet_hours_end;
        if ($quietStart !== null && $quietEnd !== null) {
            $currentTime = $now->format('H:i');
            $isQuiet = $quietStart <= $quietEnd
                ? $currentTime >= $quietStart && $currentTime < $quietEnd
                : $currentTime >= $quietStart || $currentTime < $quietEnd;
            if ($isQuiet) {
                $quietEndAt = $now->copy()->setTimeFromTimeString($quietEnd);
                if ($quietStart > $quietEnd && $currentTime >= $quietStart) {
                    $quietEndAt->addDay();
                }
                if ($dueAt === null || $quietEndAt->greaterThan($dueAt)) {
                    $dueAt = $quietEndAt;
                }
            }
        }

        return $dueAt;
    }

    /**
     * @param  array<string, mixed>|null  $links
     */
    private function sendChannel(
        string $channel,
        ?NotificationChannelConfig $config,
        UserNotification $notification,
        ?array $links,
    ): void {
        if ($channel === 'in_app') {
            return;
        }
        if ($config === null || ! $config->enabled) {
            throw new RuntimeException("Notification channel [{$channel}] is not configured.");
        }

        $settings = $config->settings ?? [];
        $message = $notification->message;
        if ($links !== null) {
            $message .= "\n".implode("\n", array_map(
                static fn (string $decision, string $url): string => ucfirst($decision).': '.$url,
                array_keys($links),
                array_values($links),
            ));
        }

        match ($channel) {
            'email' => $this->sendEmail($settings, $notification, $message),
            'telegram' => $this->sendTelegram($settings, $notification, $message),
            'whatsapp' => $this->sendWhatsApp($settings, $notification, $message),
            'push' => $this->sendPush($settings, $notification, $message),
            default => throw new RuntimeException("Unsupported notification channel [{$channel}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function sendEmail(array $settings, UserNotification $notification, string $body): void
    {
        $mailerName = 'user-notification-'.$notification->user_id;
        config(["mail.mailers.{$mailerName}" => [
            'transport' => 'smtp',
            'scheme' => ($settings['encryption'] ?? 'tls') === 'none' ? null : $settings['encryption'],
            'host' => $settings['host'],
            'port' => (int) $settings['port'],
            'username' => $settings['username'] ?? null,
            'password' => $settings['password'] ?? null,
            'timeout' => 10,
            'local_domain' => parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost',
        ]]);
        Mail::purge($mailerName);
        try {
            Mail::mailer($mailerName)->raw($body, function ($message) use ($settings, $notification): void {
                $message->to($settings['recipient'])
                    ->from($settings['from_address'], $settings['from_name'] ?? config('app.name'))
                    ->subject($notification->title);
            });
        } finally {
            Mail::purge($mailerName);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function sendTelegram(array $settings, UserNotification $notification, string $body): void
    {
        $response = Http::timeout(10)->withoutRedirecting()->post(
            'https://api.telegram.org/bot'.$settings['bot_token'].'/sendMessage',
            ['chat_id' => $settings['chat_id'], 'text' => $notification->title."\n".$body],
        );
        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram delivery failed with HTTP '.$response->status().'.');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function sendWhatsApp(array $settings, UserNotification $notification, string $body): void
    {
        $response = Http::timeout(10)->withoutRedirecting()
            ->withToken($settings['access_token'])
            ->post('https://graph.facebook.com/v21.0/'.$settings['phone_number_id'].'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $settings['recipient'],
                'type' => 'text',
                'text' => ['body' => $notification->title."\n".$body],
            ]);
        if (! $response->successful()) {
            throw new RuntimeException('WhatsApp delivery failed with HTTP '.$response->status().'.');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function sendPush(array $settings, UserNotification $notification, string $body): void
    {
        if (empty($settings['public_key']) || empty($settings['private_key']) || empty($settings['subject'])) {
            throw new RuntimeException('Web Push VAPID settings are incomplete.');
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $settings['subject'],
                'publicKey' => $settings['public_key'],
                'privateKey' => $settings['private_key'],
            ],
        ], ['TTL' => 300], 10);
        $subscriptions = $notification->user->pushSubscriptions;
        if ($subscriptions->isEmpty()) {
            throw new RuntimeException('No browser push subscriptions are registered.');
        }

        foreach ($subscriptions as $subscriptionRecord) {
            $endpointHost = parse_url($subscriptionRecord->endpoint, PHP_URL_HOST);
            if (! is_string($endpointHost) || ! $this->isAllowedPushHost($endpointHost)) {
                throw new RuntimeException('Push endpoint host is not an allowed public push service.');
            }

            $report = $webPush->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscriptionRecord->endpoint,
                    'keys' => ['p256dh' => $subscriptionRecord->public_key, 'auth' => $subscriptionRecord->auth_token],
                    'contentEncoding' => 'aes128gcm',
                ]),
                json_encode([
                    'title' => $notification->title,
                    'body' => mb_substr($body, 0, 180),
                    'url' => '/notifications',
                ], JSON_THROW_ON_ERROR),
            );
            if (! $report->isSuccess()) {
                throw new RuntimeException('Web Push delivery failed.');
            }
        }
    }

    private function isAllowedPushHost(string $host): bool
    {
        foreach (['fcm.googleapis.com', 'push.services.mozilla.com', 'notify.windows.com', 'push.apple.com'] as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }
}
