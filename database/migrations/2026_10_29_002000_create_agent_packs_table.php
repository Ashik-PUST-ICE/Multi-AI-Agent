<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_packs', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 64);
            $table->unsignedInteger('version');
            $table->string('name');
            $table->text('description');
            $table->json('definition');
            $table->tinyInteger('status')->default(AGENT_PACK_STATUS_DRAFT)->index();
            $table->boolean('is_builtin')->default(false);
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();
            $table->unique(['slug', 'version']);
            $table->index(['slug', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_packs');
    }
};
