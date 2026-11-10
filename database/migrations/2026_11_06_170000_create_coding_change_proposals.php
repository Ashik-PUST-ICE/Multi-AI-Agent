<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coding_change_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('workspace_label', 120);
            $table->string('title', 200);
            $table->text('plan');
            $table->longText('diff');
            $table->char('diff_sha256', 64);
            $table->string('status', 16)->default('pending');
            $table->string('secret_scan_status', 16)->default('passed');
            $table->timestamp('decision_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coding_change_proposals');
    }
};
