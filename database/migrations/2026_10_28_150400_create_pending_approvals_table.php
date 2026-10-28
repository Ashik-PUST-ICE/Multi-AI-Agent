<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('pending_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id')->nullable()->index();
            $table->unsignedBigInteger('agent_run_id')->nullable()->index();
            $table->string('action');
            $table->string('requested_by');
            $table->json('payload')->nullable();
            $table->tinyInteger('status')->default(APPROVAL_STATUS_PENDING)->index();
            $table->unsignedBigInteger('decided_by')->nullable()->index();
            $table->text('decision_note')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['task_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_approvals');
    }
};
