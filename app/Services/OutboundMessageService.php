<?php

namespace App\Services;

use App\Models\NotificationChannelConfig;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;

class OutboundMessageService
{
    /**
     * @param  array{channel: string, recipient: string, message: string, subject?: string}  $input
     * @return array{sent: true, channel: string}
     */
    public function send(User $user, array $input): array
    {
        $channel = $input['channel'] ?? null;
        $recipient = $input['recipient'] ?? null;
        $body = $input['message'] ?? null;
        $subject = $input['subject'] ?? 'Message from your AI workspace';

        if (! is_string($channel) || ! in_array($channel, ['email', 'telegram', 'whatsapp'], true)
            || ! is_string($recipient) || $recipient === ''
            || ! is_string($body) || trim($body) === '' || mb_strlen($body) > 2000
            || ! is_string($subject) || mb_strlen($subject) > 160) {
            throw new InvalidArgumentException('The outbound message input is invalid.');
        }

        $config = NotificationChannelConfig::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->where('enabled', true)
            ->first();
        if ($config === null) {
            throw new RuntimeException('Enable and configure this message channel in Settings first.');
        }

        $settings = $config->settings ?? [];
        $configuredRecipient = match ($channel) {
            'email' => $settings['recipient'] ?? null,
            'telegram' => $settings['chat_id'] ?? null,
            'whatsapp' => $settings['recipient'] ?? null,
        };
        if (! is_string($configuredRecipient) || ! hash_equals($configuredRecipient, $recipient)) {
            throw new RuntimeException('The recipient must match the destination saved in your channel Settings.');
        }

        match ($channel) {
            'email' => $this->sendEmail($user->id, $settings, $recipient, $subject, $body),
            'telegram' => $this->sendTelegram($settings, $recipient, $subject, $body),
            'whatsapp' => $this->sendWhatsApp($settings, $recipient, $subject, $body),
        };

        return ['sent' => true, 'channel' => $channel];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function sendEmail(int $userId, array $settings, string $recipient, string $subject, string $body): void
    {
        $mailerName = 'user-outbound-'.$userId;
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
            Mail::mailer($mailerName)->raw($body, function ($mail) use ($recipient, $subject, $settings): void {
                $mail->to($recipient)
                    ->from($settings['from_address'], $settings['from_name'] ?? config('app.name'))
                    ->subject($subject);
            });
        } finally {
            Mail::purge($mailerName);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function sendTelegram(array $settings, string $recipient, string $subject, string $body): void
    {
        $response = Http::timeout(10)
            ->withoutRedirecting()
            ->post('https://api.telegram.org/bot'.$settings['bot_token'].'/sendMessage', [
                'chat_id' => $recipient,
                'text' => $subject."\n".$body,
            ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram message delivery failed with HTTP '.$response->status().'.');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function sendWhatsApp(array $settings, string $recipient, string $subject, string $body): void
    {
        $response = Http::timeout(10)
            ->withoutRedirecting()
            ->withToken($settings['access_token'])
            ->post('https://graph.facebook.com/v21.0/'.$settings['phone_number_id'].'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $recipient,
                'type' => 'text',
                'text' => ['body' => $subject."\n".$body],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('WhatsApp message delivery failed with HTTP '.$response->status().'.');
        }
    }
}
