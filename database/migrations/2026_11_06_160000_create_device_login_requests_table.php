<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_login_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('device_code_hash', 64)->unique();
            $table->string('user_code_hash', 64)->unique();
            $table->string('device_name', 100);
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_login_requests');
    }
};
