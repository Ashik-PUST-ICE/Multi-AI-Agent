<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_memories', function (Blueprint $table): void {
            $table->tinyInteger('scope_type')->default(USER_MEMORY_SCOPE_USER);
            $table->unsignedBigInteger('task_id')->nullable();
            $table->tinyInteger('status')->default(USER_MEMORY_STATUS_ACTIVE);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->tinyInteger('source_type')->default(USER_MEMORY_SOURCE_USER_INPUT);
            $table->unsignedBigInteger('superseded_by_id')->nullable();
        });

        DB::table('user_memories')->update(['created_by_user_id' => DB::raw('user_id')]);

        Schema::table('user_memories', function (Blueprint $table): void {
            $table->index(['user_id', 'scope_type', 'task_id', 'status'], 'user_memories_scope_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('user_memories', function (Blueprint $table): void {
            $table->dropIndex('user_memories_scope_status_index');
            $table->dropColumn([
                'scope_type',
                'task_id',
                'status',
                'created_by_user_id',
                'source_type',
                'superseded_by_id',
            ]);
        });
    }
};
