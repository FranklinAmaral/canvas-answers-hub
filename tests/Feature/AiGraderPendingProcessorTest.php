<?php

declare(strict_types=1);

use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderAiProvider;
use App\Models\AiGraderClientSetting;
use App\Models\AiGraderCorrectionItem;
use App\Models\AiGraderUsageLog;
use App\Models\CanvasEnvironment;
use App\Models\Organization;
use App\Services\AiGrader\AiGraderCorrectionEvaluator;
use App\Services\AiGrader\AiGraderPendingProcessor;
use App\Services\AiGrader\AiGraderQuotaService;
use App\Services\AiGrader\AiGraderSanitizer;
use App\Services\AiGrader\Data\AiGraderResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function aiGraderProcessorOrganization(): Organization
{
    return Organization::query()->create([
        'name' => 'Processor Org',
        'slug' => 'processor-org-' . uniqid(),
        'timezone' => 'America/Sao_Paulo',
        'is_active' => true,
    ]);
}

function aiGraderProcessorEnvironment(Organization $organization): CanvasEnvironment
{
    config(['app.key' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);

    return CanvasEnvironment::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Processor Canvas',
        'slug' => 'processor-canvas-' . uniqid(),
        'environment_type' => 'test',
        'base_url' => 'https://canvas.test',
        'api_token' => 'secret-token',
        'is_active' => true,
        'is_default' => true,
    ]);
}

function aiGraderProcessorSetting(Organization $organization, int $quotaReserved = 1): AiGraderClientSetting
{
    return AiGraderClientSetting::query()->create([
        'organization_id' => $organization->id,
        'enabled' => true,
        'status' => AiGraderClientSetting::STATUS_TRIAL,
        'trial_quota_total' => 50,
        'purchased_quota_total' => 0,
        'quota_used' => 0,
        'quota_reserved' => $quotaReserved,
    ]);
}

function aiGraderProcessorItem(Organization $organization, CanvasEnvironment $environment, array $overrides = []): AiGraderCorrectionItem
{
    if (! AiGraderAiProvider::query()->where('organization_id', $organization->id)->where('is_default', true)->exists()) {
        AiGraderAiProvider::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Processor Mock',
            'provider_type' => AiGraderAiProvider::TYPE_MOCK,
            'enabled' => true,
            'is_default' => true,
        ]);
    }

    $unique = uniqid();

    return AiGraderCorrectionItem::query()->create(array_merge([
        'organization_id' => $organization->id,
        'canvas_environment_id' => $environment->id,
        'child_course_id' => 'child-' . $unique,
        'canvas_quiz_id' => 'quiz-200',
        'canvas_assignment_id' => 'assignment-300',
        'canvas_quiz_submission_id' => 'quiz-submission-' . $unique,
        'canvas_assignment_submission_id' => 'assignment-submission-' . $unique,
        'canvas_user_id' => 'user-' . $unique,
        'canvas_user_name' => 'Processor Student',
        'attempt' => 1,
        'canvas_question_id' => 'question-600',
        'canvas_question_name' => 'Essay question',
        'canvas_question_text' => 'Explique o conceito avaliado.',
        'canvas_points_possible' => 10,
        'answer_text' => str_repeat('Resposta válida. ', 12),
        'ai_grading_instructions_snapshot' => 'Avalie clareza, aderência ao enunciado e justificativa.',
        'instructions_version' => 1,
        'status' => AiGraderCorrectionItem::STATUS_PENDING,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL,
    ], $overrides));
}

function aiGraderProcessorWithResult(AiGraderResult $result): AiGraderPendingProcessor
{
    $evaluator = new class($result) extends AiGraderCorrectionEvaluator {
        public function __construct(private readonly AiGraderResult $result)
        {
        }

        public function evaluate(AiGraderCorrectionItem $item, ?AiGraderAiProvider $providerOverride = null): AiGraderResult
        {
            return $this->result;
        }
    };

    return new AiGraderPendingProcessor(
        $evaluator,
        new AiGraderQuotaService(),
        new AiGraderSanitizer(),
    );
}

it('processes pending item with MockProvider and saves ai score and feedback', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    aiGraderProcessorSetting($organization);
    $item = aiGraderProcessorItem($organization, $environment);

    $summary = app(AiGraderPendingProcessor::class)->process();

    $item->refresh();

    expect($summary['processed_success'])->toBe(1)
        ->and($item->ai_score)->not->toBeNull()
        ->and($item->ai_feedback)->toContain('Correção simulada/mock')
        ->and($item->ai_confidence)->toBe('mock')
        ->and($item->ai_raw_response['provider'])->toBe('mock');
});

it('marks the item as failed with a traceable error when no provider is configured', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    aiGraderProcessorSetting($organization);
    $item = aiGraderProcessorItem($organization, $environment);
    AiGraderAiProvider::query()->delete();

    $summary = app(AiGraderPendingProcessor::class)->process();

    expect($summary['failed'])->toBe(1)
        ->and($item->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_FAILED)
        ->and($item->fresh()->failure_reason)->toContain('No enabled AI provider');
});

it('processes pending item with configured lm studio provider', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    aiGraderProcessorSetting($organization);
    $provider = AiGraderAiProvider::query()->create([
        'organization_id' => $organization->id,
        'name' => 'LM Studio',
        'provider_type' => AiGraderAiProvider::TYPE_LM_STUDIO,
        'base_url' => 'http://localhost:1234',
        'model' => 'local-model',
        'api_key_encrypted' => 'process-token',
        'request_timeout_seconds' => 30,
        'max_tokens' => 500,
        'temperature' => 0.2,
        'enabled' => true,
        'is_default' => true,
        'settings' => ['endpoint_path' => '/v1/chat/completions'],
    ]);
    $blueprint = AiGraderBlueprintConfig::query()->create([
        'organization_id' => $organization->id,
        'canvas_environment_id' => $environment->id,
        'blueprint_course_id' => 'bp-process',
        'enabled' => true,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH,
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_MANUAL,
        'default_feedback_language' => 'pt_BR',
        'ai_provider_id' => $provider->id,
    ]);
    $item = aiGraderProcessorItem($organization, $environment, [
        'ai_grader_blueprint_config_id' => $blueprint->id,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH,
    ]);

    Http::fake([
        'http://localhost:1234/v1/chat/completions' => Http::response([
            'choices' => [
                ['message' => ['content' => '{"score":8,"feedback":"Feedback do LM Studio.","confidence":"high","review_flags":[]}']],
            ],
        ]),
    ]);

    $summary = app(AiGraderPendingProcessor::class)->process();

    $item->refresh();

    expect($summary['ai_corrected'])->toBe(1)
        ->and($item->status)->toBe(AiGraderCorrectionItem::STATUS_AI_CORRECTED)
        ->and((float) $item->ai_score)->toBe(8.0)
        ->and($item->ai_feedback)->toBe('Feedback do LM Studio.')
        ->and($item->ai_raw_response['provider'])->toBe(AiGraderAiProvider::TYPE_LM_STUDIO);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer process-token'));
});

it('changes teacher approval item to pending teacher approval', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    aiGraderProcessorSetting($organization);
    $item = aiGraderProcessorItem($organization, $environment, [
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL,
    ]);

    app(AiGraderPendingProcessor::class)->process();

    expect($item->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL);
});

it('changes auto publish item to ai corrected', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    aiGraderProcessorSetting($organization);
    $item = aiGraderProcessorItem($organization, $environment, [
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH,
    ]);

    app(AiGraderPendingProcessor::class)->process();

    expect($item->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_AI_CORRECTED);
});

it('keeps auto publish with review flags pending teacher approval when flags exist', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    aiGraderProcessorSetting($organization);
    $item = aiGraderProcessorItem($organization, $environment, [
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH_WITH_REVIEW_FLAGS,
    ]);

    app(AiGraderPendingProcessor::class)->process();

    expect($item->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL);
});

it('changes auto publish with review flags to ai corrected when no flags exist', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    aiGraderProcessorSetting($organization);
    $item = aiGraderProcessorItem($organization, $environment, [
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH_WITH_REVIEW_FLAGS,
    ]);
    $processor = aiGraderProcessorWithResult(new AiGraderResult(
        score: 7,
        feedback: 'Feedback mock sem flags.',
        confidence: 'fake',
        reviewFlags: [],
        rawResponse: ['provider' => 'fake'],
    ));

    $processor->process();

    expect($item->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_AI_CORRECTED);
});

it('does not process skipped blank answer item', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    aiGraderProcessorSetting($organization);
    $item = aiGraderProcessorItem($organization, $environment, [
        'status' => AiGraderCorrectionItem::STATUS_SKIPPED_BLANK_ANSWER,
    ]);

    $summary = app(AiGraderPendingProcessor::class)->process();

    expect($summary['items_found'])->toBe(0)
        ->and($item->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_SKIPPED_BLANK_ANSWER);
});

it('does not process pending quota item', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    aiGraderProcessorSetting($organization);
    $item = aiGraderProcessorItem($organization, $environment, [
        'status' => AiGraderCorrectionItem::STATUS_PENDING_QUOTA,
    ]);

    $summary = app(AiGraderPendingProcessor::class)->process();

    expect($summary['items_found'])->toBe(0)
        ->and($item->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_PENDING_QUOTA);
});

it('marks pending item without answer as failed', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    aiGraderProcessorSetting($organization);
    $item = aiGraderProcessorItem($organization, $environment, [
        'answer_text' => '',
    ]);

    app(AiGraderPendingProcessor::class)->process();

    $item->refresh();

    expect($item->status)->toBe(AiGraderCorrectionItem::STATUS_FAILED)
        ->and($item->failure_reason)->toBe('missing_answer');
});

it('turns reserved quota into used quota on success', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    $setting = aiGraderProcessorSetting($organization);
    aiGraderProcessorItem($organization, $environment);

    app(AiGraderPendingProcessor::class)->process();

    $setting->refresh();

    expect($setting->quota_reserved)->toBe(0)
        ->and($setting->quota_used)->toBe(1);
});

it('releases reserved quota when AI rejects item', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    $setting = aiGraderProcessorSetting($organization);
    $item = aiGraderProcessorItem($organization, $environment);
    $processor = aiGraderProcessorWithResult(AiGraderResult::rejected('provider_rejected', ['provider' => 'fake']));

    $processor->process();

    $setting->refresh();

    expect($item->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_AI_REJECTED)
        ->and($setting->quota_reserved)->toBe(0)
        ->and($setting->quota_used)->toBe(0);
});

it('creates correction consumed usage log on success', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    aiGraderProcessorSetting($organization);
    $item = aiGraderProcessorItem($organization, $environment);

    app(AiGraderPendingProcessor::class)->process();

    $this->assertDatabaseHas('ai_grader_usage_logs', [
        'ai_grader_correction_item_id' => $item->id,
        'usage_type' => AiGraderUsageLog::TYPE_CORRECTION_CONSUMED,
        'quantity' => 1,
        'status' => 'success',
    ]);
});

it('creates correction released usage log on rejection', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    aiGraderProcessorSetting($organization);
    $item = aiGraderProcessorItem($organization, $environment);
    $processor = aiGraderProcessorWithResult(AiGraderResult::rejected('provider_rejected', ['provider' => 'fake']));

    $processor->process();

    $this->assertDatabaseHas('ai_grader_usage_logs', [
        'ai_grader_correction_item_id' => $item->id,
        'usage_type' => AiGraderUsageLog::TYPE_CORRECTION_RELEASED,
        'quantity' => 1,
        'status' => 'rejected',
    ]);
});

it('marks technical provider rejection as failed and releases quota', function (string $reason): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    $setting = aiGraderProcessorSetting($organization);
    $item = aiGraderProcessorItem($organization, $environment);
    $processor = aiGraderProcessorWithResult(AiGraderResult::rejected($reason, ['provider' => 'lm_studio']));

    $summary = $processor->process();

    $item->refresh();
    $setting->refresh();

    expect($summary['ai_rejected'])->toBe(0)
        ->and($summary['failed'])->toBe(1)
        ->and($summary['quota_released'])->toBe(1)
        ->and($item->status)->toBe(AiGraderCorrectionItem::STATUS_FAILED)
        ->and($item->failure_reason)->toBe($reason)
        ->and($item->failed_at)->not->toBeNull()
        ->and($setting->quota_reserved)->toBe(0)
        ->and($setting->quota_used)->toBe(0);

    $this->assertDatabaseHas('ai_grader_usage_logs', [
        'ai_grader_correction_item_id' => $item->id,
        'usage_type' => AiGraderUsageLog::TYPE_CORRECTION_RELEASED,
        'quantity' => 1,
        'status' => 'failed',
    ]);
})->with(['connection_error', 'timeout']);

it('dry run does not alter database or quota', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    $setting = aiGraderProcessorSetting($organization);
    $item = aiGraderProcessorItem($organization, $environment);

    $summary = app(AiGraderPendingProcessor::class)->process(['dry_run' => true]);

    $item->refresh();
    $setting->refresh();

    expect($summary['items_found'])->toBe(1)
        ->and($item->status)->toBe(AiGraderCorrectionItem::STATUS_PENDING)
        ->and($item->ai_score)->toBeNull()
        ->and($setting->quota_reserved)->toBe(1)
        ->and($setting->quota_used)->toBe(0)
        ->and(AiGraderUsageLog::query()->count())->toBe(0);
});

it('item id filter processes only one item', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    $setting = aiGraderProcessorSetting($organization, 2);
    $first = aiGraderProcessorItem($organization, $environment);
    $second = aiGraderProcessorItem($organization, $environment);

    app(AiGraderPendingProcessor::class)->process(['item_id' => $second->id]);

    $setting->refresh();

    expect($first->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_PENDING)
        ->and($second->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL)
        ->and($setting->quota_reserved)->toBe(1)
        ->and($setting->quota_used)->toBe(1);
});

it('limit restricts number of processed items', function (): void {
    $organization = aiGraderProcessorOrganization();
    $environment = aiGraderProcessorEnvironment($organization);
    $setting = aiGraderProcessorSetting($organization, 2);
    $first = aiGraderProcessorItem($organization, $environment);
    $second = aiGraderProcessorItem($organization, $environment);

    $summary = app(AiGraderPendingProcessor::class)->process(['limit' => 1]);

    $setting->refresh();

    expect($summary['items_found'])->toBe(1)
        ->and($first->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL)
        ->and($second->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_PENDING)
        ->and($setting->quota_reserved)->toBe(1)
        ->and($setting->quota_used)->toBe(1);
});
