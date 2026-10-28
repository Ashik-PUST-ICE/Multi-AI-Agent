<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('run_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id')->nullable()->index();
            $table->unsignedBigInteger('agent_run_id')->nullable()->index();
            $table->string('event_type')->index();
            $table->unsignedBigInteger('sequence')->default(0);
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
            $table->index(['task_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_events');
    }
};
