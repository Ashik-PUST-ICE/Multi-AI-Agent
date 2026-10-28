<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserApiKey extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'provider',
        'api_key',
        'base_url',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function maskedKey(): ?string
    {
        $apiKey = $this->api_key;

        if ($apiKey === null || $apiKey === '') {
            return null;
        }

        $visibleSuffix = substr($apiKey, -4);

        return str_repeat('*', 8).$visibleSuffix;
    }
}
