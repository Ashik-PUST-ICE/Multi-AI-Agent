<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('agent_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id')->nullable()->index();
            $table->unsignedBigInteger('agent_run_id')->nullable()->index();
            $table->string('role');
            $table->longText('content');
            $table->unsignedInteger('sequence')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['task_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_messages');
    }
};
