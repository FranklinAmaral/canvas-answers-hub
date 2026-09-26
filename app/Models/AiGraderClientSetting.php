<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGraderClientSetting extends Model
{
    protected $table = 'ai_grader_client_settings';

    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_QUOTA_EXCEEDED = 'quota_exceeded';

    protected $fillable = [
        'organization_id',
        'enabled',
        'status',
        'plan_name',
        'trial_quota_total',
        'purchased_quota_total',
        'quota_used',
        'quota_reserved',
        'quota_reset_at',
        'starts_at',
        'ends_at',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'enabled' => 'boolean',
        'trial_quota_total' => 'integer',
        'purchased_quota_total' => 'integer',
        'quota_used' => 'integer',
        'quota_reserved' => 'integer',
        'quota_reset_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
