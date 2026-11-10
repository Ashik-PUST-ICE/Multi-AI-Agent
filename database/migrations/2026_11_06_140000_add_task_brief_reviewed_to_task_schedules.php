<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_schedules', function (Blueprint $table): void {
            $table->boolean('task_brief_reviewed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('task_schedules', function (Blueprint $table): void {
            $table->dropColumn('task_brief_reviewed');
        });
    }
};
