<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGraderUsageLog extends Model
{
    protected $table = 'ai_grader_usage_logs';

    public const UPDATED_AT = null;

    public const TYPE_CORRECTION_RESERVED = 'correction_reserved';
    public const TYPE_CORRECTION_CONSUMED = 'correction_consumed';
    public const TYPE_CORRECTION_RELEASED = 'correction_released';
    public const TYPE_AI_REQUEST = 'ai_request';
    public const TYPE_AI_VALIDATION_REQUEST = 'ai_validation_request';

    protected $fillable = [
        'organization_id',
        'canvas_environment_id',
        'ai_grader_correction_item_id',
        'ai_grader_batch_id',
        'ai_provider_id',
        'usage_type',
        'quantity',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'estimated_cost',
        'currency',
        'status',
        'metadata',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'canvas_environment_id' => 'integer',
        'ai_grader_correction_item_id' => 'integer',
        'ai_grader_batch_id' => 'integer',
        'ai_provider_id' => 'integer',
        'quantity' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'estimated_cost' => 'decimal:6',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function canvasEnvironment(): BelongsTo
    {
        return $this->belongsTo(CanvasEnvironment::class);
    }

    public function correctionItem(): BelongsTo
    {
        return $this->belongsTo(AiGraderCorrectionItem::class, 'ai_grader_correction_item_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AiGraderBatch::class, 'ai_grader_batch_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiGraderAiProvider::class, 'ai_provider_id');
    }
}
