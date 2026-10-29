<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_agent_packs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('agent_pack_id');
            $table->tinyInteger('status')->default(USER_AGENT_PACK_STATUS_ENABLED)->index();
            $table->timestamps();
            $table->unique(['user_id', 'agent_pack_id']);
            $table->foreign('agent_pack_id')->references('id')->on('agent_packs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_agent_packs');
    }
};
