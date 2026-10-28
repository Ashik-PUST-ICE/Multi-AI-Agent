<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tenantOwnedTables = [
        'tasks',
        'user_llm_settings',
        'user_api_keys',
    ];

    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        foreach ($this->tenantOwnedTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            });
        }

        DB::transaction(function (): void {
            DB::table('users')->orderBy('id')->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    $tenantId = DB::table('tenants')->insertGetId([
                        'name' => "{$user->name}'s Workspace",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);

                    foreach ($this->tenantOwnedTables as $tableName) {
                        if (Schema::hasColumn($tableName, 'user_id')) {
                            DB::table($tableName)
                                ->where('user_id', $user->id)
                                ->update(['tenant_id' => $tenantId]);
                        }
                    }
                }
            });
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
        });

        foreach (array_reverse($this->tenantOwnedTables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['tenant_id']);
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }

        Schema::dropIfExists('tenants');
    }
};
