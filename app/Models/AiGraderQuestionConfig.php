<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiGraderQuestionConfig extends Model
{
    protected $table = 'ai_grader_question_configs';

    public const TYPE_ESSAY = 'essay_question';
    public const CORRECTION_MODE_SIMPLE = 'simple';
    public const CORRECTION_MODE_CANVAS_RUBRIC = 'canvas_rubric';

    protected $fillable = [
        'ai_grader_quiz_config_id',
        'canvas_question_id',
        'canvas_question_name',
        'canvas_question_type',
        'canvas_question_text',
        'canvas_points_possible',
        'enabled',
        'correction_mode',
        'ai_grading_instructions',
        'canvas_rubric_id',
        'rubric_title',
        'rubric_snapshot',
        'rubric_synced_at',
        'canvas_neutral_comments_snapshot',
        'instructions_version',
        'instructions_updated_at',
        'last_synced_at',
        'settings',
    ];

    protected $casts = [
        'ai_grader_quiz_config_id' => 'integer',
        'canvas_points_possible' => 'decimal:2',
        'enabled' => 'boolean',
        'rubric_snapshot' => 'array',
        'rubric_synced_at' => 'datetime',
        'instructions_version' => 'integer',
        'instructions_updated_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'settings' => 'array',
    ];

    public function quizConfig(): BelongsTo
    {
        return $this->belongsTo(AiGraderQuizConfig::class, 'ai_grader_quiz_config_id');
    }

    public function correctionItems(): HasMany
    {
        return $this->hasMany(AiGraderCorrectionItem::class, 'ai_grader_question_config_id');
    }

    /**
     * @return array<int, string>
     */
    public static function correctionModes(): array
    {
        return [
            self::CORRECTION_MODE_SIMPLE,
            self::CORRECTION_MODE_CANVAS_RUBRIC,
        ];
    }

    public function normalizedCorrectionMode(): string
    {
        return in_array($this->correction_mode, self::correctionModes(), true)
            ? (string) $this->correction_mode
            : self::CORRECTION_MODE_SIMPLE;
    }

    public function usesCanvasRubric(): bool
    {
        return $this->normalizedCorrectionMode() === self::CORRECTION_MODE_CANVAS_RUBRIC;
    }

    public function hasRubricSnapshot(): bool
    {
        return is_array($this->rubric_snapshot)
            && ($this->rubric_snapshot['criteria'] ?? []) !== [];
    }

    public function isEligibleForAi(): bool
    {
        return $this->enabled
            && $this->canvas_question_type === self::TYPE_ESSAY
            && trim((string) $this->ai_grading_instructions) !== ''
            && (! $this->usesCanvasRubric() || $this->hasRubricSnapshot());
    }
}
