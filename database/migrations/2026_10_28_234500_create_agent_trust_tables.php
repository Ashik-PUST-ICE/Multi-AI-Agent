<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_trust', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('agent_name');
            $table->unsignedTinyInteger('trust_level')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'agent_name']);
        });

        Schema::create('agent_trust_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('agent_name');
            $table->unsignedTinyInteger('from_level');
            $table->unsignedTinyInteger('to_level');
            $table->string('change_type');
            $table->string('reason', 500);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'agent_name', 'id']);
        });

        $trustDefaults = config('agent_specs.trust_defaults', []);
        DB::transaction(function () use ($trustDefaults): void {
            DB::table('users')->orderBy('id')->chunkById(200, function ($users) use ($trustDefaults): void {
                foreach ($users as $user) {
                    $trustRecords = [];
                    foreach ($trustDefaults as $agentName => $trustLevel) {
                        $trustRecords[] = [
                            'user_id' => $user->id,
                            'agent_name' => $agentName,
                            'trust_level' => $trustLevel,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    if ($trustRecords !== []) {
                        DB::table('agent_trust')->insert($trustRecords);
                    }
                }
            });
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_trust_history');
        Schema::dropIfExists('agent_trust');
    }
};
