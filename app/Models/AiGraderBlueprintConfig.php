<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiGraderBlueprintConfig extends Model
{
    protected $table = 'ai_grader_blueprint_configs';

    public const PUBLICATION_AUTO_PUBLISH = 'auto_publish';
    public const PUBLICATION_TEACHER_APPROVAL = 'teacher_approval';
    public const PUBLICATION_AUTO_PUBLISH_WITH_REVIEW_FLAGS = 'auto_publish_with_review_flags';

    public const TRIGGER_AFTER_SUBMISSION = 'after_submission';
    public const TRIGGER_AFTER_DATE = 'after_date';
    public const TRIGGER_MANUAL = 'manual';

    protected $fillable = [
        'organization_id',
        'canvas_environment_id',
        'blueprint_course_id',
        'blueprint_course_name',
        'enabled',
        'publication_mode',
        'trigger_mode',
        'scheduled_at',
        'teacher_can_adjust_score',
        'teacher_can_adjust_feedback',
        'teacher_can_publish',
        'teacher_can_republish',
        'teacher_can_export',
        'teacher_can_view_grading_prompt',
        'default_feedback_language',
        'ai_provider_id',
        'settings',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'canvas_environment_id' => 'integer',
        'enabled' => 'boolean',
        'scheduled_at' => 'datetime',
        'teacher_can_adjust_score' => 'boolean',
        'teacher_can_adjust_feedback' => 'boolean',
        'teacher_can_publish' => 'boolean',
        'teacher_can_republish' => 'boolean',
        'teacher_can_export' => 'boolean',
        'teacher_can_view_grading_prompt' => 'boolean',
        'ai_provider_id' => 'integer',
        'settings' => 'array',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function canvasEnvironment(): BelongsTo
    {
        return $this->belongsTo(CanvasEnvironment::class);
    }

    public function aiProvider(): BelongsTo
    {
        return $this->belongsTo(AiGraderAiProvider::class, 'ai_provider_id');
    }

    public function quizConfigs(): HasMany
    {
        return $this->hasMany(AiGraderQuizConfig::class, 'ai_grader_blueprint_config_id');
    }

    public function correctionItems(): HasMany
    {
        return $this->hasMany(AiGraderCorrectionItem::class, 'ai_grader_blueprint_config_id');
    }

    /**
     * @return array<int, string>
     */
    public static function publicationModes(): array
    {
        return [
            self::PUBLICATION_AUTO_PUBLISH,
            self::PUBLICATION_TEACHER_APPROVAL,
        ];
    }

    public static function normalizePublicationMode(?string $mode): string
    {
        return match ($mode) {
            self::PUBLICATION_AUTO_PUBLISH => self::PUBLICATION_AUTO_PUBLISH,
            self::PUBLICATION_TEACHER_APPROVAL => self::PUBLICATION_TEACHER_APPROVAL,
            self::PUBLICATION_AUTO_PUBLISH_WITH_REVIEW_FLAGS => self::PUBLICATION_TEACHER_APPROVAL,
            default => self::PUBLICATION_TEACHER_APPROVAL,
        };
    }
}
