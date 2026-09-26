<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiGraderBatch extends Model
{
    protected $table = 'ai_grader_batches';

    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_AFTER_SUBMISSION = 'after_submission';
    public const TRIGGER_RETRY = 'retry';
    public const TRIGGER_QUOTA_RELEASED = 'quota_released';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id',
        'canvas_environment_id',
        'ai_grader_blueprint_config_id',
        'ai_grader_quiz_config_id',
        'child_course_id',
        'trigger_type',
        'status',
        'total_items',
        'pending_items',
        'processed_items',
        'failed_items',
        'skipped_items',
        'quota_blocked_items',
        'started_at',
        'finished_at',
        'created_by',
        'metadata',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'canvas_environment_id' => 'integer',
        'ai_grader_blueprint_config_id' => 'integer',
        'ai_grader_quiz_config_id' => 'integer',
        'total_items' => 'integer',
        'pending_items' => 'integer',
        'processed_items' => 'integer',
        'failed_items' => 'integer',
        'skipped_items' => 'integer',
        'quota_blocked_items' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_by' => 'integer',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function canvasEnvironment(): BelongsTo
    {
        return $this->belongsTo(CanvasEnvironment::class);
    }

    public function blueprintConfig(): BelongsTo
    {
        return $this->belongsTo(AiGraderBlueprintConfig::class, 'ai_grader_blueprint_config_id');
    }

    public function quizConfig(): BelongsTo
    {
        return $this->belongsTo(AiGraderQuizConfig::class, 'ai_grader_quiz_config_id');
    }

    public function correctionItems(): HasMany
    {
        return $this->hasMany(AiGraderCorrectionItem::class, 'ai_grader_batch_id');
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(AiGraderUsageLog::class, 'ai_grader_batch_id');
    }
}
