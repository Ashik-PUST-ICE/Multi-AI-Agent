<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAssistantSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'persona',
        'custom_persona',
        'voice_input_enabled',
        'voice_output_enabled',
        'voice_language',
        'vision_enabled',
        'smart_home_enabled',
        'proactive_enabled',
        'proactive_max_suggestions',
        'proactive_timezone',
        'proactive_briefing_date',
        'proactive_briefing',
    ];

    protected function casts(): array
    {
        return [
            'voice_input_enabled' => 'boolean',
            'voice_output_enabled' => 'boolean',
            'proactive_enabled' => 'boolean',
            'proactive_max_suggestions' => 'integer',
            'proactive_briefing_date' => 'date:Y-m-d',
            'proactive_briefing' => 'array',
            'vision_enabled' => 'boolean',
            'smart_home_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
