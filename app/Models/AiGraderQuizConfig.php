<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiGraderQuizConfig extends Model
{
    protected $table = 'ai_grader_quiz_configs';

    protected $fillable = [
        'ai_grader_blueprint_config_id',
        'canvas_quiz_id',
        'canvas_assignment_id',
        'canvas_quiz_title',
        'canvas_quiz_type',
        'points_possible',
        'question_count',
        'enabled',
        'correction_enabled',
        'last_synced_at',
        'settings',
    ];

    protected $casts = [
        'ai_grader_blueprint_config_id' => 'integer',
        'points_possible' => 'decimal:2',
        'question_count' => 'integer',
        'enabled' => 'boolean',
        'correction_enabled' => 'boolean',
        'last_synced_at' => 'datetime',
        'settings' => 'array',
    ];

    public function blueprintConfig(): BelongsTo
    {
        return $this->belongsTo(AiGraderBlueprintConfig::class, 'ai_grader_blueprint_config_id');
    }

    public function questionConfigs(): HasMany
    {
        return $this->hasMany(AiGraderQuestionConfig::class, 'ai_grader_quiz_config_id');
    }

    public function correctionItems(): HasMany
    {
        return $this->hasMany(AiGraderCorrectionItem::class, 'ai_grader_quiz_config_id');
    }
}
