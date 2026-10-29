<?php

namespace App\Tools;

use App\Services\OutboundMessageService;
use InvalidArgumentException;
use LogicException;

class OutboundMessageTool implements ContextualToolInterface
{
    public function name(): string
    {
        return 'send_message';
    }

    public function description(): string
    {
        return 'Send a user-reviewed message through an enabled Email, Telegram, or WhatsApp destination explicitly saved in Settings.';
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function minimumTrustLevel(): int
    {
        return AGENT_TRUST_LEVEL_OUTBOUND;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'channel' => [
                    'type' => 'string',
                    'enum' => ['email', 'telegram', 'whatsapp'],
                ],
                'recipient' => [
                    'type' => 'string',
                    'description' => 'Must exactly match the destination saved in the owner channel Settings.',
                    'maxLength' => 255,
                ],
                'subject' => [
                    'type' => 'string',
                    'maxLength' => 160,
                ],
                'message' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 2000,
                ],
            ],
            'required' => ['channel', 'recipient', 'message'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $input): array
    {
        throw new LogicException('Outbound messages require an authenticated owner context.');
    }

    public function executeWithContext(array $input, ToolExecutionContext $context): array
    {
        if (! is_string($input['channel'] ?? null)
            || ! is_string($input['recipient'] ?? null)
            || ! is_string($input['message'] ?? null)
            || isset($input['subject']) && ! is_string($input['subject'])) {
            throw new InvalidArgumentException('The outbound message input is invalid.');
        }

        return app(OutboundMessageService::class)->send($context->user, $input);
    }

    public function simulate(array $input): array
    {
        $channel = $input['channel'] ?? null;
        if (! is_string($channel) || ! in_array($channel, ['email', 'telegram', 'whatsapp'], true)) {
            throw new InvalidArgumentException('Choose a supported outbound message channel.');
        }

        return [
            'simulated' => true,
            'channel' => $channel,
            'sent' => false,
            'notice' => 'Sandbox simulation only; no message was sent.',
        ];
    }
}
