<?php

namespace App\Agents;

use App\Llm\UserLlmProviderResolver;
use App\Tools\ToolRegistry;
use InvalidArgumentException;

class AgentFactory
{
    /**
     * @var array<string, class-string<BaseAgent>>
     */
    private const AGENT_CLASSES = [
        'Orchestrator' => OrchestratorAgent::class,
        'Research' => ResearchAgent::class,
        'Writer' => WriterAgent::class,
        'Reviewer' => ReviewerAgent::class,
    ];

    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly UserLlmProviderResolver $userLlmProviderResolver,
    ) {}

    public function make(string $agentName, string $mode): BaseAgent
    {
        $agentClass = self::AGENT_CLASSES[$agentName] ?? null;

        if ($agentClass === null) {
            throw new InvalidArgumentException("Agent [{$agentName}] is not available.");
        }

        return app($agentClass, [
            'provider' => null,
            'tools' => $this->toolRegistry,
            'mode' => $mode,
            'userProviderResolver' => $this->userLlmProviderResolver,
        ]);
    }
}
