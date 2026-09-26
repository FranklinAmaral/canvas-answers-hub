<?php

declare(strict_types=1);

use App\Models\AiGraderAiProvider;
use App\Models\AiGraderCorrectionItem;
use App\Models\CanvasEnvironment;
use App\Models\Organization;
use App\Services\AiGrader\AiGraderPromptBuilder;
use App\Services\AiGrader\AiGraderSanitizer;
use App\Services\AiGrader\Data\AiGraderPromptContext;
use App\Services\AiGrader\Providers\LmStudioAiGraderProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function lmStudioOrganization(): Organization
{
    config(['app.key' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);

    return Organization::query()->create([
        'name' => 'LM Studio Org',
        'slug' => 'lm-studio-org-' . uniqid(),
        'timezone' => 'America/Sao_Paulo',
        'is_active' => true,
    ]);
}

function lmStudioProvider(Organization $organization, array $overrides = []): AiGraderAiProvider
{
    return AiGraderAiProvider::query()->create(array_merge([
        'organization_id' => $organization->id,
        'name' => 'LM Studio',
        'provider_type' => AiGraderAiProvider::TYPE_LM_STUDIO,
        'base_url' => 'http://localhost:1234',
        'model' => 'local-model',
        'api_key_encrypted' => 'lm-secret',
        'request_timeout_seconds' => 30,
        'max_tokens' => 500,
        'temperature' => 0.1,
        'enabled' => true,
        'is_default' => true,
        'settings' => ['endpoint_path' => '/v1/chat/completions'],
    ], $overrides));
}

function lmStudioItem(Organization $organization): AiGraderCorrectionItem
{
    $environment = CanvasEnvironment::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Canvas',
        'slug' => 'canvas-' . uniqid(),
        'environment_type' => 'test',
        'base_url' => 'https://canvas.test',
        'api_token' => 'canvas-token',
        'is_active' => true,
        'is_default' => true,
    ]);

    return AiGraderCorrectionItem::query()->create([
        'organization_id' => $organization->id,
        'canvas_environment_id' => $environment->id,
        'child_course_id' => '11',
        'canvas_quiz_id' => '4',
        'canvas_assignment_id' => '12',
        'canvas_quiz_submission_id' => '44',
        'canvas_user_id' => '11',
        'attempt' => 1,
        'canvas_question_id' => '29',
        'canvas_question_text' => 'Explique.',
        'canvas_points_possible' => 10,
        'answer_text' => 'Resposta do estudante.',
        'ai_grading_instructions_snapshot' => 'Rubrica.',
        'status' => AiGraderCorrectionItem::STATUS_PENDING,
    ]);
}

function lmStudioContext(): AiGraderPromptContext
{
    return new AiGraderPromptContext(
        questionText: 'Explique.',
        answerText: 'Resposta do estudante.',
        pointsPossible: 10,
        gradingInstructions: 'Rubrica.',
        feedbackLanguage: 'pt_BR',
    );
}

function lmStudioService(AiGraderAiProvider $provider): LmStudioAiGraderProvider
{
    return new LmStudioAiGraderProvider($provider, new AiGraderPromptBuilder(), new AiGraderSanitizer());
}

it('builds endpoint from base url and endpoint path and sends authorization bearer', function (): void {
    $organization = lmStudioOrganization();
    $provider = lmStudioProvider($organization);
    $item = lmStudioItem($organization);

    Http::fake([
        'http://localhost:1234/v1/chat/completions' => Http::response([
            'choices' => [
                ['message' => ['content' => '{"score":7.5,"feedback":"Bom trabalho.","confidence":"high","review_flags":[]}']],
            ],
        ]),
    ]);

    $result = lmStudioService($provider)->grade($item, lmStudioContext());

    expect($result->rejected)->toBeFalse()
        ->and($result->score)->toBe(7.5)
        ->and($result->feedback)->toBe('Bom trabalho.');

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return $request->url() === 'http://localhost:1234/v1/chat/completions'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer lm-secret')
            && ($payload['model'] ?? null) === 'local-model';
    });
});

it('works without authorization when token is empty', function (): void {
    $organization = lmStudioOrganization();
    $provider = lmStudioProvider($organization, ['api_key_encrypted' => null]);
    $item = lmStudioItem($organization);

    Http::fake([
        'http://localhost:1234/v1/chat/completions' => Http::response([
            'choices' => [
                ['message' => ['content' => '{"score":5,"feedback":"Feedback valido.","confidence":"medium","review_flags":[]}']],
            ],
        ]),
    ]);

    lmStudioService($provider)->grade($item, lmStudioContext());

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

it('parses valid json from choices message content even with surrounding text', function (): void {
    $organization = lmStudioOrganization();
    $provider = lmStudioProvider($organization);
    $item = lmStudioItem($organization);

    Http::fake([
        'http://localhost:1234/v1/chat/completions' => Http::response([
            'choices' => [
                ['message' => ['content' => 'Resultado: {"score":6,"feedback":"Feedback claro.","confidence":"high","review_flags":{"needs_review":false}}']],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ]),
    ]);

    $result = lmStudioService($provider)->grade($item, lmStudioContext());

    expect($result->rejected)->toBeFalse()
        ->and($result->score)->toBe(6.0)
        ->and($result->feedback)->toBe('Feedback claro.')
        ->and($result->totalTokens)->toBe(30)
        ->and($result->rawResponse['provider'])->toBe(AiGraderAiProvider::TYPE_LM_STUDIO);
});

it('rejects response without json', function (): void {
    $organization = lmStudioOrganization();
    $provider = lmStudioProvider($organization);
    $item = lmStudioItem($organization);

    Http::fake([
        'http://localhost:1234/v1/chat/completions' => Http::response([
            'choices' => [
                ['message' => ['content' => 'sem json aqui']],
            ],
        ]),
    ]);

    $result = lmStudioService($provider)->grade($item, lmStudioContext());

    expect($result->rejected)->toBeTrue()
        ->and($result->rejectionReason)->toBe('parse_error');
});

it('rejects json without score', function (): void {
    $organization = lmStudioOrganization();
    $provider = lmStudioProvider($organization);
    $item = lmStudioItem($organization);

    Http::fake([
        'http://localhost:1234/v1/chat/completions' => Http::response([
            'choices' => [
                ['message' => ['content' => '{"feedback":"Feedback valido."}']],
            ],
        ]),
    ]);

    $result = lmStudioService($provider)->grade($item, lmStudioContext());

    expect($result->rejected)->toBeTrue()
        ->and($result->rejectionReason)->toBe('missing_score');
});

it('rejects json without feedback', function (): void {
    $organization = lmStudioOrganization();
    $provider = lmStudioProvider($organization);
    $item = lmStudioItem($organization);

    Http::fake([
        'http://localhost:1234/v1/chat/completions' => Http::response([
            'choices' => [
                ['message' => ['content' => '{"score":4}']],
            ],
        ]),
    ]);

    $result = lmStudioService($provider)->grade($item, lmStudioContext());

    expect($result->rejected)->toBeTrue()
        ->and($result->rejectionReason)->toBe('missing_feedback');
});
