<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateAssistantMessageRequest;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Task;
use App\Models\User;
use App\Services\OrchestratorService;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssistantController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private readonly OrchestratorService $orchestratorService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $conversations = $request->user()->assistantConversations()
            ->with(['messages' => fn ($query) => $query->latest('id')->limit(1)])
            ->latest('updated_at')
            ->paginate(30);

        return $this->success([
            'conversations' => $conversations->getCollection()
                ->map(fn (AssistantConversation $conversation): array => [
                    'id' => $conversation->id,
                    'title' => $conversation->title,
                    'updated_at' => $conversation->updated_at?->toISOString(),
                    'last_message' => $this->messageData($conversation->messages->first()),
                ])
                ->values(),
            'pagination' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        $conversation = $request->user()->assistantConversations()
            ->with(['messages' => fn ($query) => $query->orderBy('id')])
            ->findOrFail($conversation);

        return $this->success([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'created_at' => $conversation->created_at?->toISOString(),
                'updated_at' => $conversation->updated_at?->toISOString(),
                'messages' => $conversation->messages
                    ->map(fn (AssistantMessage $message): array => $this->messageData($message))
                    ->values(),
            ],
        ]);
    }

    public function store(CreateAssistantMessageRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        $conversation = isset($validated['conversation_id'])
            ? $user->assistantConversations()->findOrFail($validated['conversation_id'])
            : null;
        $content = trim($validated['content']);

        if ($content === '') {
            return $this->error('Message content cannot be blank.', 422);
        }

        $conversation ??= DB::transaction(fn (): AssistantConversation => $user->assistantConversations()->create([
            'title' => Str::limit($content, 120),
        ]));
        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => $content,
        ]);

        $directReply = $this->directReply($content, $user->name);
        if ($directReply !== null) {
            $assistantMessage = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $directReply,
            ]);

            return $this->success([
                'conversation' => ['id' => $conversation->id, 'title' => $conversation->title],
                'user_message' => $this->messageData($userMessage),
                'assistant_message' => $this->messageData($assistantMessage),
                'handled_directly' => true,
            ]);
        }

        $taskResponse = $this->orchestratorService->submit($user, [
            'title' => Str::limit($content, 120, ''),
            'prompt' => $content,
            'task_brief_review_token' => $validated['task_brief_review_token'] ?? null,
        ]);
        $responseData = $taskResponse->getData(true);
        $taskData = is_array($responseData['task'] ?? null) ? $responseData['task'] : null;
        if ($taskData === null) {
            return $taskResponse;
        }

        $task = Task::query()->findOrFail((int) $taskData['id']);
        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Task created and queued. Its current status and result will appear in this conversation.',
            'task_id' => $task->id,
        ]);

        return response()->json([
            'conversation' => ['id' => $conversation->id, 'title' => $conversation->title],
            'user_message' => $this->messageData($userMessage),
            'assistant_message' => $this->messageData($assistantMessage),
            'task' => $taskData,
            'handled_directly' => false,
        ], $taskResponse->getStatusCode());
    }

    private function directReply(string $content, string $name): ?string
    {
        $normalized = mb_strtolower(trim($content));

        if (preg_match('/^(hi|hello|hey|salam|assalamu[\x27’]?alaikum|হ্যালো|হাই|আসসালামু আলাইকুম)[!.? ]*$/u', $normalized) === 1) {
            return "Hello, {$name}. I can help with research, writing, analysis, and other tasks. What would you like to do?";
        }

        if (in_array($normalized, ['help', 'what can you do', 'what can you do?'], true)) {
            return 'I can answer greetings directly or create a tracked task for research, writing, analysis, and other requests. Task actions continue to follow your configured safety and approval rules.';
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function messageData(?AssistantMessage $message): ?array
    {
        if ($message === null) {
            return null;
        }

        $data = [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'created_at' => $message->created_at?->toISOString(),
        ];

        if ($message->task_id !== null) {
            $task = $message->relationLoaded('task') ? $message->task : $message->task()->first();
            $data['task'] = $task === null ? null : [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'mode' => $task->mode,
                'result' => $task->result,
                'error_message' => $task->error_message,
                'completed_at' => $task->completed_at?->toISOString(),
            ];
        }

        return $data;
    }
}
