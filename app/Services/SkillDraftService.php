<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SkillDraftService
{
    public function __construct(
        private readonly PiiMasker $piiMasker,
        private readonly SkillContentScanner $scanner,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function suggestFromTask(User $user, int $taskId): array
    {
        $task = Task::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->findOrFail($taskId);

        if ($task->status !== TASK_STATUS_COMPLETED || ($task->result['criteria_passed'] ?? null) === false) {
            throw ValidationException::withMessages([
                'task' => 'Only successfully completed tasks can be used to suggest a skill draft.',
            ]);
        }

        $title = trim($this->piiMasker->maskText($task->title));
        $name = 'Workflow: '.mb_substr($title !== '' ? $title : "Task {$task->id}", 0, 110);
        $description = "Owner-reviewed draft inspired by completed task #{$task->id}. The original task description and output are not copied; customize this reusable workflow before saving.";
        $instructions = implode("\n", [
            '# Reviewable skill draft',
            '',
            "This is a starting point inspired by completed task #{$task->id}. No task prompt or result was copied.",
            'Replace the placeholders with reusable guidance, and remove any private or task-specific details before saving.',
            '',
            '## Reusable workflow',
            '1. Confirm the user’s goal, scope, constraints, and desired output.',
            '2. Use only available, explicitly authorized capabilities; this skill grants no tools or access.',
            '3. Work from the information the user provides and distinguish facts from assumptions.',
            '4. Check the result against the user’s success criteria and disclose remaining uncertainty.',
            '',
            '## Guidance to add',
            '- What repeatable approach made the source task successful?',
            '- Which details must be supplied anew for each task?',
            '- What should the skill avoid doing?',
        ]);
        $findings = $this->scanner->findings($name, $description, $instructions);

        return [
            'draft' => [
                'name' => $name,
                'description' => $description,
                'instructions' => $instructions,
                'visibility' => 'personal',
                'source' => 'authored',
            ],
            'source_task' => [
                'id' => $task->id,
                'title' => $title,
                'completed_at' => $task->completed_at?->toISOString(),
            ],
            'scan_findings' => $findings,
            'saved' => false,
            'scripts_enabled' => false,
            'required_tools' => [],
            'minimum_trust_level' => AGENT_TRUST_LEVEL_NONE,
            'requires_owner_review' => true,
        ];
    }
}
