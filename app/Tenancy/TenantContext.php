<?php

namespace App\Tenancy;

use InvalidArgumentException;

class TenantContext
{
    private ?int $tenantId = null;

    private bool $initialized = false;

    public function initialize(int $tenantId): void
    {
        if ($tenantId < 1) {
            throw new InvalidArgumentException('A valid tenant is required.');
        }

        $this->tenantId = $tenantId;
        $this->initialized = true;
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->initialized = false;
    }
}
