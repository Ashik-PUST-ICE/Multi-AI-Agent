<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_specs')) {
            return;
        }

        DB::transaction(function (): void {
            $active = DB::table('agent_specs')
                ->where('agent_name', 'Writer')
                ->where('status', AGENT_SPEC_STATUS_ACTIVE)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            if ($active === null || (int) $active->version !== 1 || $active->created_by !== null) {
                return;
            }

            $allowedTools = json_decode((string) $active->allowed_tools, true);
            if (! is_array($allowedTools)) {
                $allowedTools = [];
            }
            if (in_array('send_message', $allowedTools, true)) {
                return;
            }

            $allowedTools[] = 'send_message';
            $now = now();
            $nextVersion = (int) DB::table('agent_specs')
                ->where('agent_name', 'Writer')
                ->max('version') + 1;

            DB::table('agent_specs')
                ->where('id', $active->id)
                ->update(['status' => AGENT_SPEC_STATUS_RETIRED, 'updated_at' => $now]);
            DB::table('agent_specs')->insert([
                'agent_name' => $active->agent_name,
                'version' => $nextVersion,
                'status' => AGENT_SPEC_STATUS_ACTIVE,
                'role' => $active->role,
                'system_prompt' => $active->system_prompt,
                'input_schema' => $active->input_schema,
                'output_schema' => $active->output_schema,
                'allowed_tools' => json_encode(array_values($allowedTools), JSON_THROW_ON_ERROR),
                'minimum_trust_level' => $active->minimum_trust_level,
                'default_model' => $active->default_model,
                'fallback_model' => $active->fallback_model,
                'max_steps' => $active->max_steps,
                'max_tokens' => $active->max_tokens,
                'timeout_seconds' => $active->timeout_seconds,
                'stop_conditions' => $active->stop_conditions,
                'success_criteria' => $active->success_criteria,
                'created_by' => $active->created_by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        // Agent specifications are versioned records; rollback must not discard user edits.
    }
};
