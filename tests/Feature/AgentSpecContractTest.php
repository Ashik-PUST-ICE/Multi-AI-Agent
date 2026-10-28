<?php

namespace Tests\Feature;

use App\Agents\AgentMessageEnvelope;
use App\Agents\AgentRegistry;
use App\Agents\SchemaValidator;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class AgentSpecContractTest extends TestCase
{
    use DatabaseMigrations;

    public function test_all_four_core_agents_have_valid_input_and_output_contracts(): void
    {
        $agentRegistry = app(AgentRegistry::class);
        $schemaValidator = app(SchemaValidator::class);
        $agentNames = ['Orchestrator', 'Research', 'Writer', 'Reviewer'];

        foreach ($agentNames as $agentName) {
            $agentSpecification = $agentRegistry->activeSpec($agentName);

            $this->assertSame([], $schemaValidator->definitionErrors($agentSpecification['input_schema']));
            $this->assertSame([], $schemaValidator->definitionErrors($agentSpecification['output_schema']));
            $this->assertSame($agentName, $agentSpecification['agent_name']);
            $this->assertNotEmpty($agentSpecification['system_prompt']);
        }
    }

    public function test_message_envelope_round_trips_structured_agent_handoff_data(): void
    {
        $expectedEnvelope = new AgentMessageEnvelope(
            'Research',
            'Writer',
            42,
            'agent.handoff',
            ['prompt' => 'Write a summary.', 'context' => ['research' => ['text' => 'Facts']]],
        );

        $actualEnvelope = AgentMessageEnvelope::fromArray($expectedEnvelope->toArray());

        $this->assertSame($expectedEnvelope->toArray(), $actualEnvelope->toArray());
    }

    public function test_schema_validator_reports_missing_fields_and_wrong_types(): void
    {
        $schemaValidator = app(SchemaValidator::class);
        $validationErrors = $schemaValidator->errors([
            'text' => 12,
        ], [
            'type' => 'object',
            'required' => ['text', 'usage'],
            'properties' => [
                'text' => ['type' => 'string'],
                'usage' => ['type' => 'object'],
            ],
        ]);

        $this->assertCount(2, $validationErrors);
        $this->assertStringContainsString('$.usage is required', $validationErrors[0]);
        $this->assertStringContainsString('$.text must be of type string', $validationErrors[1]);
    }
}
