<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_api_integrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('endpoint', 2048);
            $table->text('access_token')->nullable();
            $table->json('allowed_methods');
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index(['tenant_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_api_integrations');
    }
};
