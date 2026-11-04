<?php

namespace App\Services;

use App\Models\AgentPack;
use RuntimeException;

class AgentPackIntegrity
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function signatureFor(
        string $slug,
        int $version,
        string $name,
        string $description,
        array $definition,
        bool $isBuiltin,
        ?int $createdBy,
    ): string {
        $definitionHash = hash(
            'sha256',
            json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        $manifest = json_encode([
            'publisher' => $isBuiltin ? 'platform' : 'admin',
            'slug' => $slug,
            'version' => $version,
            'name' => $name,
            'description' => $description,
            'definition_sha256' => $definitionHash,
            'is_builtin' => $isBuiltin,
            'created_by' => $createdBy,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', $manifest, $this->signingKey());
    }

    public function verifies(AgentPack $pack): bool
    {
        if (! is_string($pack->integrity_signature)) {
            return false;
        }

        $expectedSignature = $this->signatureFor(
            $pack->slug,
            $pack->version,
            $pack->name,
            $pack->description,
            $pack->definition ?? [],
            $pack->is_builtin,
            $pack->created_by,
        );

        return hash_equals($expectedSignature, $pack->integrity_signature);
    }

    private function signingKey(): string
    {
        $applicationKey = (string) config('app.key');

        if (str_starts_with($applicationKey, 'base64:')) {
            $decodedKey = base64_decode(substr($applicationKey, 7), true);
            if ($decodedKey !== false) {
                $applicationKey = $decodedKey;
            }
        }

        if ($applicationKey === '') {
            throw new RuntimeException('Agent pack signatures require a configured application key.');
        }

        return $applicationKey;
    }
}
