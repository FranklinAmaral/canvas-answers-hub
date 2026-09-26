<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGraderCanvasPublicationLog extends Model
{
    protected $table = 'ai_grader_canvas_publication_logs';

    public const UPDATED_AT = null;

    protected $fillable = [
        'ai_grader_correction_item_id',
        'organization_id',
        'canvas_environment_id',
        'endpoint',
        'http_method',
        'payload',
        'response_status',
        'response_body',
        'success',
        'published_score',
        'published_feedback',
        'error_message',
    ];

    protected $casts = [
        'ai_grader_correction_item_id' => 'integer',
        'organization_id' => 'integer',
        'canvas_environment_id' => 'integer',
        'payload' => 'array',
        'response_status' => 'integer',
        'response_body' => 'array',
        'success' => 'boolean',
        'published_score' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function correctionItem(): BelongsTo
    {
        return $this->belongsTo(AiGraderCorrectionItem::class, 'ai_grader_correction_item_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function canvasEnvironment(): BelongsTo
    {
        return $this->belongsTo(CanvasEnvironment::class);
    }
}
