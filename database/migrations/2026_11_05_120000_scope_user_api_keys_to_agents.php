<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_api_keys', function (Blueprint $table): void {
            $table->string('agent_name', 80)->nullable()->after('user_id');
            $table->dropUnique('user_api_keys_user_id_provider_unique');
            $table->unique(
                ['user_id', 'agent_name', 'provider'],
                'user_api_keys_user_agent_provider_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('user_api_keys', function (Blueprint $table): void {
            $table->dropUnique('user_api_keys_user_agent_provider_unique');
            $table->dropColumn('agent_name');
            $table->unique(['user_id', 'provider']);
        });
    }
};
