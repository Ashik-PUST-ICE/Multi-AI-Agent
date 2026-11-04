<?php

use App\Services\AgentPackIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_packs', function (Blueprint $table): void {
            $table->char('integrity_signature', 64)->nullable();
        });

        $integrity = app(AgentPackIntegrity::class);
        DB::table('agent_packs')
            ->orderBy('id')
            ->each(function (object $pack) use ($integrity): void {
                $definition = is_string($pack->definition)
                    ? json_decode($pack->definition, true, 512, JSON_THROW_ON_ERROR)
                    : $pack->definition;
                $signature = $integrity->signatureFor(
                    $pack->slug,
                    (int) $pack->version,
                    $pack->name,
                    $pack->description,
                    $definition,
                    (bool) $pack->is_builtin,
                    $pack->created_by === null ? null : (int) $pack->created_by,
                );
                DB::table('agent_packs')
                    ->where('id', $pack->id)
                    ->update(['integrity_signature' => $signature]);
            });
    }

    public function down(): void
    {
        Schema::table('agent_packs', function (Blueprint $table): void {
            $table->dropColumn('integrity_signature');
        });
    }
};
