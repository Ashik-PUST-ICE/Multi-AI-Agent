<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->char('objective_sha256', 64)->nullable();
        });

        Schema::table('agent_packs', function (Blueprint $table): void {
            $table->char('definition_sha256', 64)->nullable();
        });

        Schema::create('security_kill_switches', function (Blueprint $table): void {
            $table->id();
            $table->tinyInteger('scope_type');
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->tinyInteger('enabled')->default(1);
            $table->unsignedBigInteger('triggered_by')->nullable()->index();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['scope_type', 'scope_id']);
        });

        DB::table('tasks')
            ->whereNotNull('description')
            ->whereNull('objective_sha256')
            ->orderBy('id')
            ->each(function (object $task): void {
                DB::table('tasks')
                    ->where('id', $task->id)
                    ->update(['objective_sha256' => hash('sha256', $task->description)]);
            });

        DB::table('tasks')
            ->whereNull('description')
            ->whereNull('objective_sha256')
            ->update(['objective_sha256' => hash('sha256', '')]);

        DB::table('agent_packs')
            ->whereNull('definition_sha256')
            ->orderBy('id')
            ->each(function (object $pack): void {
                $definition = is_string($pack->definition)
                    ? json_decode($pack->definition, true, 512, JSON_THROW_ON_ERROR)
                    : $pack->definition;
                DB::table('agent_packs')
                    ->where('id', $pack->id)
                    ->update([
                        'definition_sha256' => hash(
                            'sha256',
                            json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_kill_switches');
        Schema::table('agent_packs', function (Blueprint $table): void {
            $table->dropColumn('definition_sha256');
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('objective_sha256');
        });
    }
};
