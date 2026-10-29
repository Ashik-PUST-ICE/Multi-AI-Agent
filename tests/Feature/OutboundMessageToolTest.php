<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessPolicyEngine;
use App\Services\OutboundMessageService;
use App\Tools\OutboundMessageTool;
use App\Tools\ToolApprovalRequiredException;
use App\Tools\ToolExecutionContext;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundMessageToolTest extends TestCase
{
    use DatabaseMigrations;

    public function test_live_message_requires_exact_approval_and_uses_only_saved_recipient(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $user->notificationChannels()->create([
            'tenant_id' => $user->tenant_id,
            'channel' => 'telegram',
            'enabled' => true,
            'settings' => [
                'bot_token' => '123456:private-token',
                'chat_id' => 'owner-chat-123',
            ],
        ]);
        $task = $user->tasks()->create([
            'title' => 'Send a reviewed message',
            'description' => 'Send the user-composed message to the saved owner destination.',
            'mode' => TASK_MODE_LIVE,
            'status' => TASK_STATUS_PENDING,
        ]);
        $run = $task->agentRuns()->create([
            'agent_name' => 'Writer',
            'input' => [],
            'status' => AGENT_RUN_STATUS_PENDING,
        ]);
        $registry = new ToolRegistry;
        $registry->register(new OutboundMessageTool);
        $toolInput = [
            'channel' => 'telegram',
            'recipient' => 'owner-chat-123',
            'subject' => 'Reviewed subject',
            'message' => 'Reviewed message body.',
        ];
        $this->assertSame(AGENT_TRUST_LEVEL_OUTBOUND, $registry->get('send_message')->minimumTrustLevel());
        $this->assertTrue($registry->get('send_message')->requiresApproval());
        $this->assertTrue(app(AccessPolicyEngine::class)->isNeverAuto('send_message'));

        try {
            $registry->run('send_message', $toolInput, TASK_MODE_LIVE, [], AGENT_TRUST_LEVEL_OUTBOUND, new ToolExecutionContext($user, $run));
            $this->fail('A live outbound message without explicit exact approval must be blocked.');
        } catch (ToolApprovalRequiredException $exception) {
            $this->assertSame($toolInput, $exception->input);
        }

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
        $signature = $registry->approvalSignature('send_message', $toolInput);
        $result = $registry->run(
            'send_message',
            $toolInput,
            TASK_MODE_LIVE,
            [$signature],
            AGENT_TRUST_LEVEL_OUTBOUND,
            new ToolExecutionContext($user, $run),
        );

        $this->assertSame(['sent' => true, 'channel' => 'telegram'], $result);
        Http::assertSent(static fn ($request): bool => (
            $request->url() === 'https://api.telegram.org/bot123456:private-token/sendMessage'
            && $request['chat_id'] === 'owner-chat-123'
            && $request['text'] === "Reviewed subject\nReviewed message body."
        ));
    }

    public function test_message_cannot_be_sent_to_a_recipient_not_saved_in_settings(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $user->notificationChannels()->create([
            'tenant_id' => $user->tenant_id,
            'channel' => 'whatsapp',
            'enabled' => true,
            'settings' => [
                'access_token' => 'private-whatsapp-token',
                'phone_number_id' => '123456789',
                'recipient' => '+15551234567',
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        app(OutboundMessageService::class)->send($user, [
            'channel' => 'whatsapp',
            'recipient' => '+15557654321',
            'message' => 'This must not be sent.',
        ]);
    }

    public function test_sandbox_message_only_simulates(): void
    {
        Http::preventStrayRequests();
        $tool = new OutboundMessageTool;

        $result = $tool->simulate([
            'channel' => 'email',
            'recipient' => 'owner@example.test',
            'message' => 'Sandbox message.',
        ]);

        $this->assertTrue($result['simulated']);
        $this->assertFalse($result['sent']);
    }
}
