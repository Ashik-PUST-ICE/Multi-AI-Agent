<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['agent_runs', 'agent_messages', 'run_events'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('mode')->default(TASK_MODE_SANDBOX)->index();
            });
        }

        foreach (['agent_runs', 'agent_messages', 'run_events'] as $tableName) {
            DB::table($tableName)
                ->whereNotNull('task_id')
                ->update([
                    'mode' => DB::raw(
                        "(SELECT mode FROM tasks WHERE tasks.id = {$tableName}.task_id)",
                    ),
                ]);
        }

        Schema::create('mode_transitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_id')->index();
            $table->unsignedBigInteger('resulting_task_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('from_mode');
            $table->string('to_mode');
            $table->tinyInteger('status')->default(MODE_TRANSITION_STATUS_BLOCKED)->index();
            $table->json('blocked_requirements')->nullable();
            $table->boolean('user_confirmed')->default(false);
            $table->timestamp('transitioned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mode_transitions');

        foreach (['run_events', 'agent_messages', 'agent_runs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropIndex("{$tableName}_mode_index");
                $table->dropColumn('mode');
            });
        }
    }
};
