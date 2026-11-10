<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_skills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name', 120);
            $table->string('slug', 160);
            $table->text('description');
            $table->string('visibility', 16)->default('personal');
            $table->string('source', 20)->default('authored');
            $table->string('publisher_claim', 160)->nullable();
            $table->string('source_reference', 500)->nullable();
            $table->string('status', 20)->default('pending_review')->index();
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'slug']);
            $table->index(['tenant_id', 'visibility', 'status']);
        });

        Schema::create('user_skill_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->longText('instructions');
            $table->char('content_sha256', 64)->index();
            $table->json('scan_findings')->nullable();
            $table->string('verification_status', 20)->default('pending_review');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['user_skill_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_skill_versions');
        Schema::dropIfExists('user_skills');
    }
};
