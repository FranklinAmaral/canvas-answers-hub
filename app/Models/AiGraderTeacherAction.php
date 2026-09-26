<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGraderTeacherAction extends Model
{
    protected $table = 'ai_grader_teacher_actions';

    public const UPDATED_AT = null;

    public const ACTION_VIEWED = 'viewed';
    public const ACTION_APPROVED = 'approved';
    public const ACTION_ADJUSTED_SCORE = 'adjusted_score';
    public const ACTION_ADJUSTED_FEEDBACK = 'adjusted_feedback';
    public const ACTION_PUBLISHED = 'published';
    public const ACTION_REPUBLISHED = 'republished';
    public const ACTION_REJECTED = 'rejected';

    protected $fillable = [
        'ai_grader_correction_item_id',
        'organization_id',
        'actor_user_id',
        'action',
        'previous_score',
        'new_score',
        'previous_feedback',
        'new_feedback',
        'metadata',
    ];

    protected $casts = [
        'ai_grader_correction_item_id' => 'integer',
        'organization_id' => 'integer',
        'actor_user_id' => 'integer',
        'previous_score' => 'decimal:2',
        'new_score' => 'decimal:2',
        'metadata' => 'array',
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
