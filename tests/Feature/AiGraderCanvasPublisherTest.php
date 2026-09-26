<?php

declare(strict_types=1);

use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderCanvasPublicationLog;
use App\Models\AiGraderCorrectionItem;
use App\Models\CanvasEnvironment;
use App\Models\Organization;
use App\Services\AiGrader\AiGraderCanvasPublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function aiGraderPublisherOrganization(): Organization
{
    return Organization::query()->create([
        'name' => 'Publisher Org',
        'slug' => 'publisher-org-' . uniqid(),
        'timezone' => 'America/Sao_Paulo',
        'is_active' => true,
    ]);
}

function aiGraderPublisherEnvironment(Organization $organization): CanvasEnvironment
{
    config(['app.key' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);

    return CanvasEnvironment::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Publisher Canvas',
        'slug' => 'publisher-canvas-' . uniqid(),
        'environment_type' => 'test',
        'base_url' => 'https://canvas.test',
        'api_token' => 'secret-token',
        'is_active' => true,
        'is_default' => true,
    ]);
}

function aiGraderPublisherItem(Organization $organization, CanvasEnvironment $environment, array $overrides = []): AiGraderCorrectionItem
{
    $unique = uniqid();

    return AiGraderCorrectionItem::query()->create(array_merge([
        'organization_id' => $organization->id,
        'canvas_environment_id' => $environment->id,
        'child_course_id' => '11',
        'canvas_quiz_id' => '4',
        'canvas_assignment_id' => '12',
        'canvas_quiz_submission_id' => '44',
        'canvas_assignment_submission_id' => '900',
        'canvas_user_id' => 'user-' . $unique,
        'canvas_user_name' => 'Publisher Student',
        'attempt' => 1,
        'canvas_question_id' => '29',
        'canvas_question_name' => 'Essay question',
        'canvas_question_text' => 'Explique o conceito avaliado.',
        'canvas_points_possible' => 10,
        'answer_text' => 'Resposta discursiva.',
        'ai_grading_instructions_snapshot' => 'Rubrica.',
        'ai_score' => 6.5,
        'ai_feedback' => 'Feedback gerado pela IA mock.',
        'ai_confidence' => 'mock',
        'status' => AiGraderCorrectionItem::STATUS_AI_CORRECTED,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH,
    ], $overrides));
}

function aiGraderPublisherSuccessFake(array $response = []): void
{
    Http::fake([
        'https://canvas.test/api/v1/courses/11/quizzes/4/submissions/44' => Http::response(array_merge([
            'ok' => true,
            'validation_token' => 'secret-validation-token',
        ], $response), 200),
    ]);
}

it('publishes ai corrected item to Canvas with question score and comment payload', function (): void {
    $organization = aiGraderPublisherOrganization();
    $environment = aiGraderPublisherEnvironment($organization);
    $item = aiGraderPublisherItem($organization, $environment);
    aiGraderPublisherSuccessFake();

    $summary = app(AiGraderCanvasPublisher::class)->publish();

    expect($summary['published'])->toBe(1);

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return $request->method() === 'PUT'
            && $request->url() === 'https://canvas.test/api/v1/courses/11/quizzes/4/submissions/44'
            && ($payload['quiz_submissions'][0]['attempt'] ?? null) === 1
            && ($payload['quiz_submissions'][0]['questions']['29']['score'] ?? null) === 6.5
            && ($payload['quiz_submissions'][0]['questions']['29']['comment'] ?? null) === 'Feedback gerado pela IA mock.';
    });

    expect($item->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS);
});

it('does not publish pending teacher approval item', function (): void {
    $organization = aiGraderPublisherOrganization();
    $environment = aiGraderPublisherEnvironment($organization);
    $item = aiGraderPublisherItem($organization, $environment, [
        'status' => AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL,
    ]);
    Http::fake();

    $summary = app(AiGraderCanvasPublisher::class)->publish(['item_id' => $item->id]);

    expect($summary['ignored'])->toBe(1)
        ->and($item->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL);
    Http::assertNothingSent();
});

it('does not publish skipped blank answer item', function (): void {
    $organization = aiGraderPublisherOrganization();
    $environment = aiGraderPublisherEnvironment($organization);
    $item = aiGraderPublisherItem($organization, $environment, [
        'status' => AiGraderCorrectionItem::STATUS_SKIPPED_BLANK_ANSWER,
    ]);
    Http::fake();

    app(AiGraderCanvasPublisher::class)->publish(['item_id' => $item->id]);

    expect($item->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_SKIPPED_BLANK_ANSWER);
    Http::assertNothingSent();
});

it('does not publish item already published to Canvas', function (): void {
    $organization = aiGraderPublisherOrganization();
    $environment = aiGraderPublisherEnvironment($organization);
    $item = aiGraderPublisherItem($organization, $environment, [
        'status' => AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS,
    ]);
    Http::fake();

    app(AiGraderCanvasPublisher::class)->publish(['item_id' => $item->id]);

    expect($item->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS);
    Http::assertNothingSent();
});

it('uses final score and final feedback when filled', function (): void {
    $organization = aiGraderPublisherOrganization();
    $environment = aiGraderPublisherEnvironment($organization);
    $item = aiGraderPublisherItem($organization, $environment, [
        'ai_score' => 3,
        'ai_feedback' => 'Feedback IA.',
        'final_score' => 8,
        'final_feedback' => 'Feedback final do professor.',
    ]);
    aiGraderPublisherSuccessFake();

    app(AiGraderCanvasPublisher::class)->publish(['item_id' => $item->id]);

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return ($payload['quiz_submissions'][0]['questions']['29']['score'] ?? null) === 8.0
            && ($payload['quiz_submissions'][0]['questions']['29']['comment'] ?? null) === 'Feedback final do professor.';
    });

    $item->refresh();

    expect((float) $item->canvas_published_score)->toBe(8.0)
        ->and($item->canvas_published_feedback)->toBe('Feedback final do professor.');
});

it('uses ai score and ai feedback when final values are empty', function (): void {
    $organization = aiGraderPublisherOrganization();
    $environment = aiGraderPublisherEnvironment($organization);
    $item = aiGraderPublisherItem($organization, $environment, [
        'ai_score' => 4.25,
        'ai_feedback' => 'Feedback IA usado.',
        'final_score' => null,
        'final_feedback' => '',
    ]);
    aiGraderPublisherSuccessFake();

    app(AiGraderCanvasPublisher::class)->publish(['item_id' => $item->id]);

    $item->refresh();

    expect((float) $item->canvas_published_score)->toBe(4.25)
        ->and($item->canvas_published_feedback)->toBe('Feedback IA usado.')
        ->and((float) $item->final_score)->toBe(4.25)
        ->and($item->final_feedback)->toBe('Feedback IA usado.');
});

it('normalizes score greater than points possible before publishing', function (): void {
    $organization = aiGraderPublisherOrganization();
    $environment = aiGraderPublisherEnvironment($organization);
    $item = aiGraderPublisherItem($organization, $environment, [
        'ai_score' => 99,
        'canvas_points_possible' => 10,
    ]);
    aiGraderPublisherSuccessFake();

    app(AiGraderCanvasPublisher::class)->publish(['item_id' => $item->id]);

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return ($payload['quiz_submissions'][0]['questions']['29']['score'] ?? null) === 10.0;
    });
});

it('success fills publication fields and creates success log', function (): void {
    $organization = aiGraderPublisherOrganization();
    $environment = aiGraderPublisherEnvironment($organization);
    $item = aiGraderPublisherItem($organization, $environment);
    aiGraderPublisherSuccessFake(['result' => 'updated']);

    app(AiGraderCanvasPublisher::class)->publish(['item_id' => $item->id]);

    $item->refresh();
    $log = AiGraderCanvasPublicationLog::query()->where('ai_grader_correction_item_id', $item->id)->first();

    expect($item->status)->toBe(AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS)
        ->and($item->published_at)->not->toBeNull()
        ->and((float) $item->canvas_published_score)->toBe(6.5)
        ->and($item->canvas_published_feedback)->toBe('Feedback gerado pela IA mock.')
        ->and($log)->not->toBeNull()
        ->and($log->success)->toBeTrue()
        ->and($log->http_method)->toBe('PUT')
        ->and($log->response_status)->toBe(200)
        ->and((float) $log->published_score)->toBe(6.5);
});

it('failure changes status to publication failed and creates failed log', function (): void {
    $organization = aiGraderPublisherOrganization();
    $environment = aiGraderPublisherEnvironment($organization);
    $item = aiGraderPublisherItem($organization, $environment);
    Http::fake([
        'https://canvas.test/api/v1/courses/11/quizzes/4/submissions/44' => Http::response([
            'errors' => [
                ['message' => 'Invalid quiz submission'],
            ],
        ], 422),
    ]);

    $summary = app(AiGraderCanvasPublisher::class)->publish(['item_id' => $item->id]);

    $item->refresh();
    $log = AiGraderCanvasPublicationLog::query()->where('ai_grader_correction_item_id', $item->id)->first();

    expect($summary['failed'])->toBe(1)
        ->and($item->status)->toBe(AiGraderCorrectionItem::STATUS_PUBLICATION_FAILED)
        ->and($item->failed_at)->not->toBeNull()
        ->and($item->failure_reason)->toContain('Invalid quiz submission')
        ->and($log)->not->toBeNull()
        ->and($log->success)->toBeFalse()
        ->and($log->response_status)->toBe(422)
        ->and($log->error_message)->toContain('Invalid quiz submission');
});

it('dry run does not call Canvas or alter item', function (): void {
    $organization = aiGraderPublisherOrganization();
    $environment = aiGraderPublisherEnvironment($organization);
    $item = aiGraderPublisherItem($organization, $environment);
    Http::fake();

    $summary = app(AiGraderCanvasPublisher::class)->publish([
        'item_id' => $item->id,
        'dry_run' => true,
    ]);

    expect($summary['ignored'])->toBe(1)
        ->and($item->fresh()->status)->toBe(AiGraderCorrectionItem::STATUS_AI_CORRECTED)
        ->and($item->fresh()->canvas_published_score)->toBeNull()
        ->and(AiGraderCanvasPublicationLog::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('sanitizes validation token from Canvas response log', function (): void {
    $organization = aiGraderPublisherOrganization();
    $environment = aiGraderPublisherEnvironment($organization);
    $item = aiGraderPublisherItem($organization, $environment);
    aiGraderPublisherSuccessFake([
        'validation_token' => 'must-not-be-stored',
        'nested' => [
            'api_token' => 'hidden-api-token',
        ],
    ]);

    app(AiGraderCanvasPublisher::class)->publish(['item_id' => $item->id]);

    $log = AiGraderCanvasPublicationLog::query()->where('ai_grader_correction_item_id', $item->id)->firstOrFail();

    expect($log->response_body['validation_token'])->toBe('[hidden]')
        ->and($log->response_body['nested']['api_token'])->toBe('[hidden]')
        ->and(json_encode($log->response_body))->not->toContain('must-not-be-stored')
        ->and(json_encode($log->response_body))->not->toContain('hidden-api-token');
});
