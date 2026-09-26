<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiGraderCorrectionItem extends Model
{
    protected $table = 'ai_grader_correction_items';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PENDING_QUOTA = 'pending_quota';
    public const STATUS_PENDING_CONFIGURATION = 'pending_configuration';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_AI_CORRECTED = 'ai_corrected';
    public const STATUS_AI_REJECTED = 'ai_rejected';
    public const STATUS_PENDING_TEACHER_APPROVAL = 'pending_teacher_approval';
    public const STATUS_APPROVED_BY_TEACHER = 'approved_by_teacher';
    public const STATUS_ADJUSTED_BY_TEACHER = 'adjusted_by_teacher';
    public const STATUS_PUBLISHED_TO_CANVAS = 'published_to_canvas';
    public const STATUS_PUBLICATION_FAILED = 'publication_failed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED_BLANK_ANSWER = 'skipped_blank_answer';
    public const STATUS_SKIPPED_NOT_ESSAY = 'skipped_not_essay';
    public const STATUS_SKIPPED_ALREADY_PROCESSED = 'skipped_already_processed';

    public const REVIEW_STATUS_PENDING = 'pending';
    public const REVIEW_STATUS_DRAFT = 'draft';
    public const REVIEW_STATUS_APPROVED = 'approved';
    public const REVIEW_STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'organization_id',
        'canvas_environment_id',
        'ai_grader_batch_id',
        'ai_grader_blueprint_config_id',
        'ai_grader_quiz_config_id',
        'ai_grader_question_config_id',
        'blueprint_course_id',
        'child_course_id',
        'child_course_name',
        'canvas_quiz_id',
        'canvas_assignment_id',
        'canvas_quiz_submission_id',
        'canvas_assignment_submission_id',
        'canvas_user_id',
        'canvas_user_name',
        'canvas_user_login_id',
        'attempt',
        'canvas_question_id',
        'canvas_question_name',
        'canvas_question_text',
        'canvas_points_possible',
        'answer_html',
        'answer_text',
        'ai_grading_instructions_snapshot',
        'instructions_version',
        'correction_mode',
        'canvas_rubric_id',
        'rubric_title',
        'rubric_snapshot',
        'status',
        'publication_mode',
        'ai_score',
        'ai_rubric_percentage',
        'ai_feedback',
        'ai_rubric_feedback',
        'ai_corrected_at',
        'ai_provider_type',
        'ai_provider_model',
        'ai_raw_response',
        'ai_confidence',
        'ai_review_flags',
        'final_score',
        'final_feedback',
        'teacher_rubric_feedback',
        'review_status',
        'reviewed_at',
        'reviewer_canvas_user_id',
        'reviewer_name',
        'reviewer_login_id',
        'canvas_published_score',
        'canvas_published_feedback',
        'published_rubric_feedback',
        'canvas_publication_error',
        'teacher_user_id',
        'teacher_adjusted_at',
        'published_at',
        'failed_at',
        'failure_reason',
        'metadata',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'canvas_environment_id' => 'integer',
        'ai_grader_batch_id' => 'integer',
        'ai_grader_blueprint_config_id' => 'integer',
        'ai_grader_quiz_config_id' => 'integer',
        'ai_grader_question_config_id' => 'integer',
        'attempt' => 'integer',
        'canvas_points_possible' => 'decimal:2',
        'instructions_version' => 'integer',
        'rubric_snapshot' => 'array',
        'ai_score' => 'decimal:2',
        'ai_rubric_percentage' => 'decimal:2',
        'ai_rubric_feedback' => 'array',
        'ai_corrected_at' => 'datetime',
        'ai_raw_response' => 'array',
        'ai_review_flags' => 'array',
        'final_score' => 'decimal:2',
        'teacher_rubric_feedback' => 'array',
        'reviewed_at' => 'datetime',
        'canvas_published_score' => 'decimal:2',
        'published_rubric_feedback' => 'array',
        'teacher_user_id' => 'integer',
        'teacher_adjusted_at' => 'datetime',
        'published_at' => 'datetime',
        'failed_at' => 'datetime',
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AiGraderBatch::class, 'ai_grader_batch_id');
    }

    public function blueprintConfig(): BelongsTo
    {
        return $this->belongsTo(AiGraderBlueprintConfig::class, 'ai_grader_blueprint_config_id');
    }

    public function quizConfig(): BelongsTo
    {
        return $this->belongsTo(AiGraderQuizConfig::class, 'ai_grader_quiz_config_id');
    }

    public function questionConfig(): BelongsTo
    {
        return $this->belongsTo(AiGraderQuestionConfig::class, 'ai_grader_question_config_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_user_id');
    }

    public function publicationLogs(): HasMany
    {
        return $this->hasMany(AiGraderCanvasPublicationLog::class, 'ai_grader_correction_item_id');
    }

    public function teacherActions(): HasMany
    {
        return $this->hasMany(AiGraderTeacherAction::class, 'ai_grader_correction_item_id');
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(AiGraderUsageLog::class, 'ai_grader_correction_item_id');
    }

    /**
     * @return array<int, string>
     */
    public static function reviewableStatuses(): array
    {
        return [
            self::STATUS_AI_CORRECTED,
            self::STATUS_PENDING_TEACHER_APPROVAL,
            self::STATUS_PUBLISHED_TO_CANVAS,
            self::STATUS_PUBLICATION_FAILED,
            self::STATUS_APPROVED_BY_TEACHER,
            self::STATUS_ADJUSTED_BY_TEACHER,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function teacherPublishableStatuses(): array
    {
        return [
            self::STATUS_AI_CORRECTED,
            self::STATUS_PENDING_TEACHER_APPROVAL,
            self::STATUS_PUBLISHED_TO_CANVAS,
            self::STATUS_PUBLICATION_FAILED,
            self::STATUS_APPROVED_BY_TEACHER,
            self::STATUS_ADJUSTED_BY_TEACHER,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function retryableStatuses(): array
    {
        return [
            self::STATUS_FAILED,
            self::STATUS_PUBLICATION_FAILED,
        ];
    }

    public function canRetry(): bool
    {
        return in_array($this->status, self::retryableStatuses(), true);
    }

    public function hasPublicationFailure(): bool
    {
        return $this->status === self::STATUS_PUBLICATION_FAILED;
    }

    public function normalizedCorrectionMode(): string
    {
        return in_array($this->correction_mode, AiGraderQuestionConfig::correctionModes(), true)
            ? (string) $this->correction_mode
            : AiGraderQuestionConfig::CORRECTION_MODE_SIMPLE;
    }

    public function usesCanvasRubric(): bool
    {
        return $this->normalizedCorrectionMode() === AiGraderQuestionConfig::CORRECTION_MODE_CANVAS_RUBRIC;
    }
}
