<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'nick_name',
        'email',
        'mobile',
        'country',
        'image',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'google2fa_secret',
        'verify_token_hash',
        'otp_hash',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            $user->uuid ??= (string) Str::uuid();
        });

        static::created(function (self $user): void {
            if ($user->tenant_id !== null) {
                return;
            }

            $tenant = Tenant::query()->create(['name' => "{$user->name}'s Workspace"]);
            $user->forceFill(['tenant_id' => $tenant->id])->saveQuietly();
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'google2fa_secret' => 'encrypted',
            'otp_expiry' => 'datetime',
            'last_seen' => 'datetime',
            'email_verification_status' => 'integer',
            'phone_verification_status' => 'integer',
            'google_auth_status' => 'integer',
            'status' => 'integer',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function llmSettings(): HasOne
    {
        return $this->hasOne(UserLlmSetting::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(UserApiKey::class);
    }

    public function agentSpecs(): HasMany
    {
        return $this->hasMany(AgentSpec::class, 'created_by');
    }

    public function agentTrustLevels(): HasMany
    {
        return $this->hasMany(AgentTrust::class);
    }

    public function agentTrustHistory(): HasMany
    {
        return $this->hasMany(AgentTrustHistory::class);
    }

    public function agentPackInstallations(): HasMany
    {
        return $this->hasMany(UserAgentPack::class);
    }

    public function knowledgeDocuments(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class);
    }

    public function memories(): HasMany
    {
        return $this->hasMany(UserMemory::class);
    }

    public function accessPolicies(): HasMany
    {
        return $this->hasMany(UserAccessPolicy::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    public function notificationChannels(): HasMany
    {
        return $this->hasMany(NotificationChannelConfig::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(WebPushSubscription::class);
    }

    public function accessSettings(): HasOne
    {
        return $this->hasOne(UserAccessSetting::class);
    }

    public function webAccessSettings(): HasOne
    {
        return $this->hasOne(UserWebAccessSetting::class);
    }
}
