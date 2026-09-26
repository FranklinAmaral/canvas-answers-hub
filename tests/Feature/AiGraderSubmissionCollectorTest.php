<?php

declare(strict_types=1);

use App\Models\AiGraderBatch;
use App\Models\AiGraderAiProvider;
use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderClientSetting;
use App\Models\AiGraderCorrectionItem;
use App\Models\AiGraderQuestionConfig;
use App\Models\AiGraderQuizConfig;
use App\Jobs\AiGrader\AiGraderRunPipelineJob;
use App\Models\CanvasEnvironment;
use App\Models\Organization;
use App\Services\AiGrader\AiGraderSubmissionCollector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function aiGraderCollectorOrganization(int $quotaTotal = 50): Organization
{
    $organization = Organization::query()->create([
        'name' => 'Collector Org',
        'slug' => 'collector-org-' . uniqid(),
        'timezone' => 'America/Sao_Paulo',
        'is_active' => true,
    ]);

    AiGraderClientSetting::query()->create([
        'organization_id' => $organization->id,
        'enabled' => true,
        'status' => AiGraderClientSetting::STATUS_TRIAL,
        'trial_quota_total' => $quotaTotal,
        'purchased_quota_total' => 0,
        'quota_used' => 0,
        'quota_reserved' => 0,
    ]);

    return $organization;
}

function aiGraderCollectorEnvironment(Organization $organization): CanvasEnvironment
{
    config(['app.key' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);

    return CanvasEnvironment::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Collector Canvas',
        'slug' => 'collector-canvas-' . uniqid(),
        'environment_type' => 'test',
        'base_url' => 'https://canvas.test',
        'api_token' => 'secret-token',
        'is_active' => true,
        'is_default' => true,
    ]);
}

function aiGraderCollectorQuizConfig(
    Organization $organization,
    CanvasEnvironment $environment,
    array $quizOverrides = [],
): AiGraderQuizConfig {
    $blueprint = AiGraderBlueprintConfig::query()->create([
        'organization_id' => $organization->id,
        'canvas_environment_id' => $environment->id,
        'blueprint_course_id' => '3',
        'blueprint_course_name' => 'Blueprint Course',
        'enabled' => true,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL,
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_MANUAL,
        'teacher_can_adjust_score' => true,
        'teacher_can_adjust_feedback' => true,
        'teacher_can_publish' => true,
        'teacher_can_republish' => true,
        'teacher_can_export' => false,
        'default_feedback_language' => 'pt_BR',
    ]);

    return AiGraderQuizConfig::query()->create(array_merge([
        'ai_grader_blueprint_config_id' => $blueprint->id,
        'canvas_quiz_id' => 'blueprint-quiz-4',
        'canvas_assignment_id' => 'blueprint-assignment-12',
        'canvas_quiz_title' => 'Essay Quiz',
        'canvas_quiz_type' => 'assignment',
        'points_possible' => 10,
        'question_count' => 2,
        'enabled' => true,
        'correction_enabled' => true,
    ], $quizOverrides));
}

function aiGraderCollectorQuestion(
    AiGraderQuizConfig $quizConfig,
    string $questionId = '4',
    array $overrides = [],
): AiGraderQuestionConfig {
    return AiGraderQuestionConfig::query()->create(array_merge([
        'ai_grader_quiz_config_id' => $quizConfig->id,
        'canvas_question_id' => $questionId,
        'canvas_question_name' => 'Essay question',
        'canvas_question_type' => AiGraderQuestionConfig::TYPE_ESSAY,
        'canvas_question_text' => '<p>Explique o conceito.</p>',
        'canvas_points_possible' => 10,
        'enabled' => true,
        'ai_grading_instructions' => 'Rubrica privada com critérios de avaliação, resposta esperada e feedback.',
        'instructions_version' => 1,
        'settings' => ['position' => 1],
    ], $overrides));
}

function aiGraderCollectorFakeCanvas(array $submissionData, array $submissions = [], array $childQuestions = []): void
{
    $childQuestions = $childQuestions !== [] ? $childQuestions : [
        [
            'id' => 29,
            'position' => 1,
            'question_name' => 'Essay question',
            'question_type' => 'essay_question',
            'question_text' => '<p>Explique o conceito.</p>',
            'points_possible' => 10,
        ],
        [
            'id' => 30,
            'position' => 2,
            'question_name' => 'Objective question',
            'question_type' => 'multiple_choice_question',
            'question_text' => '<p>Escolha uma alternativa.</p>',
            'points_possible' => 2,
        ],
    ];

    Http::fake([
        'https://canvas.test/api/v1/courses/11/quizzes/4/questions*' => Http::response($childQuestions),
        'https://canvas.test/api/v1/courses/11/quizzes/4/submissions*' => Http::response([
            'quiz_submissions' => $submissions !== [] ? $submissions : [
                [
                    'id' => 4,
                    'user_id' => 11,
                    'attempt' => 1,
                    'workflow_state' => 'pending_review',
                    'score' => null,
                    'kept_score' => null,
                    'finished_at' => '2026-06-07T12:00:00Z',
                ],
            ],
        ]),
        'https://canvas.test/api/v1/courses/11/assignments/12/submissions/*' => Http::response([
            'id' => 900,
            'user_id' => 11,
            'user' => [
                'id' => 11,
                'name' => 'Student One',
                'login_id' => 'student.one',
            ],
            'submission_history' => [
                [
                    'attempt' => 1,
                    'submitted_at' => '2026-06-07T12:00:00Z',
                    'submission_data' => $submissionData,
                ],
                [
                    'attempt' => 2,
                    'submitted_at' => '2026-06-07T13:00:00Z',
                    'submission_data' => $submissionData,
                ],
            ],
        ]),
    ]);
}

function aiGraderCollectorRun(array $filters = []): array
{
    return app(AiGraderSubmissionCollector::class)->collect(array_merge([
        'child_course_id' => '11',
        'child_quiz_id' => '4',
        'child_assignment_id' => '12',
    ], $filters));
}

it('creates pending correction item for a valid essay answer', function (): void {
    $organization = aiGraderCollectorOrganization();
    $environment = aiGraderCollectorEnvironment($organization);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment);
    $questionConfig = aiGraderCollectorQuestion($quizConfig, '4');
    aiGraderCollectorFakeCanvas([
        ['question_id' => 29, 'text' => '<p>Resposta discursiva válida.</p>'],
    ]);

    $summary = aiGraderCollectorRun();

    expect($summary['pending_created'])->toBe(1);
    $this->assertDatabaseHas('ai_grader_correction_items', [
        'status' => AiGraderCorrectionItem::STATUS_PENDING,
        'canvas_quiz_id' => '4',
        'canvas_assignment_id' => '12',
        'canvas_quiz_submission_id' => '4',
        'canvas_question_id' => '29',
        'ai_grader_question_config_id' => $questionConfig->id,
        'canvas_user_id' => '11',
        'answer_text' => 'Resposta discursiva válida.',
    ]);

    $item = AiGraderCorrectionItem::query()->first();

    expect($summary['questions_mapped'])->toBe(1)
        ->and($summary['question_mapping_strategy_counts']['position_type'])->toBe(1)
        ->and($item->metadata['blueprint_question_id'])->toBe('4')
        ->and($item->metadata['child_question_id'])->toBe('29')
        ->and($item->metadata['question_mapping_strategy'])->toBe('position_type');
});

it('ignores objective question answers', function (): void {
    $organization = aiGraderCollectorOrganization();
    $environment = aiGraderCollectorEnvironment($organization);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment);
    aiGraderCollectorQuestion($quizConfig, '29');
    aiGraderCollectorQuestion($quizConfig, '30', [
        'canvas_question_type' => 'multiple_choice_question',
        'enabled' => true,
    ]);
    aiGraderCollectorFakeCanvas([
        ['question_id' => 30, 'text' => 'A'],
    ]);

    $summary = aiGraderCollectorRun();

    expect($summary['essay_answers_found'])->toBe(0);
    $this->assertDatabaseMissing('ai_grader_correction_items', [
        'canvas_question_id' => '30',
    ]);
});

it('does not create correction item when blueprint question cannot be mapped to child question', function (): void {
    $organization = aiGraderCollectorOrganization();
    $environment = aiGraderCollectorEnvironment($organization);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment);
    aiGraderCollectorQuestion($quizConfig, '4');
    aiGraderCollectorFakeCanvas(
        [['question_id' => 29, 'text' => '<p>Resposta sem mapeamento.</p>']],
        [],
        [
            [
                'id' => 29,
                'position' => 9,
                'question_name' => 'Different essay',
                'question_type' => 'essay_question',
                'question_text' => '<p>Outro enunciado.</p>',
                'points_possible' => 10,
            ],
        ],
    );

    $summary = aiGraderCollectorRun();

    expect($summary['question_mapping_failed'])->toBe(1)
        ->and($summary['pending_created'])->toBe(0)
        ->and(AiGraderCorrectionItem::query()->count())->toBe(0);
});

it('creates skipped blank answer item for empty essay answer', function (): void {
    $organization = aiGraderCollectorOrganization();
    $environment = aiGraderCollectorEnvironment($organization);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment);
    aiGraderCollectorQuestion($quizConfig);
    aiGraderCollectorFakeCanvas([
        ['question_id' => 29, 'text' => '<p> </p>'],
    ]);

    $summary = aiGraderCollectorRun();

    expect($summary['skipped_blank_answer'])->toBe(1);
    $this->assertDatabaseHas('ai_grader_correction_items', [
        'status' => AiGraderCorrectionItem::STATUS_SKIPPED_BLANK_ANSWER,
        'canvas_question_id' => '29',
    ]);
});

it('does not duplicate an existing correction item', function (): void {
    $organization = aiGraderCollectorOrganization();
    $environment = aiGraderCollectorEnvironment($organization);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment);
    aiGraderCollectorQuestion($quizConfig);
    aiGraderCollectorFakeCanvas([
        ['question_id' => 29, 'text' => '<p>Resposta discursiva válida.</p>'],
    ]);

    aiGraderCollectorRun();
    $summary = aiGraderCollectorRun();

    expect($summary['skipped_already_processed'])->toBe(1)
        ->and(AiGraderCorrectionItem::query()->count())->toBe(1);
});

it('creates pending quota item when balance is zero', function (): void {
    $organization = aiGraderCollectorOrganization(0);
    $environment = aiGraderCollectorEnvironment($organization);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment);
    aiGraderCollectorQuestion($quizConfig);
    aiGraderCollectorFakeCanvas([
        ['question_id' => 29, 'text' => '<p>Resposta com saldo indisponível.</p>'],
    ]);

    $summary = aiGraderCollectorRun();

    expect($summary['pending_quota_created'])->toBe(1);
    $this->assertDatabaseHas('ai_grader_correction_items', [
        'status' => AiGraderCorrectionItem::STATUS_PENDING_QUOTA,
        'canvas_question_id' => '29',
    ]);
});

it('reserves quota when creating pending item', function (): void {
    $organization = aiGraderCollectorOrganization();
    $environment = aiGraderCollectorEnvironment($organization);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment);
    aiGraderCollectorQuestion($quizConfig);
    aiGraderCollectorFakeCanvas([
        ['question_id' => 29, 'text' => '<p>Resposta para reservar cota.</p>'],
    ]);

    aiGraderCollectorRun();

    expect($organization->aiGraderClientSetting->fresh()->quota_reserved)->toBe(1);
});

it('uses quiz submission attempt in idempotency key', function (): void {
    $organization = aiGraderCollectorOrganization();
    $environment = aiGraderCollectorEnvironment($organization);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment);
    aiGraderCollectorQuestion($quizConfig);
    aiGraderCollectorFakeCanvas(
        [['question_id' => 29, 'text' => '<p>Resposta da tentativa.</p>']],
        [
            [
                'id' => 4,
                'user_id' => 11,
                'attempt' => 1,
                'workflow_state' => 'pending_review',
                'finished_at' => '2026-06-07T12:00:00Z',
            ],
            [
                'id' => 4,
                'user_id' => 11,
                'attempt' => 2,
                'workflow_state' => 'pending_review',
                'finished_at' => '2026-06-07T13:00:00Z',
            ],
        ],
    );

    aiGraderCollectorRun();

    expect(AiGraderCorrectionItem::query()->count())->toBe(2)
        ->and(AiGraderCorrectionItem::query()->pluck('attempt')->sort()->values()->all())->toBe([1, 2]);
});

it('uses ai grading instructions snapshot from question config', function (): void {
    $organization = aiGraderCollectorOrganization();
    $environment = aiGraderCollectorEnvironment($organization);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment);
    aiGraderCollectorQuestion($quizConfig, '29', [
        'ai_grading_instructions' => 'Snapshot privado da rubrica que precisa acompanhar o item de correção.',
        'instructions_version' => 7,
    ]);
    aiGraderCollectorFakeCanvas([
        ['question_id' => 29, 'text' => '<p>Resposta com snapshot.</p>'],
    ]);

    aiGraderCollectorRun();

    $this->assertDatabaseHas('ai_grader_correction_items', [
        'ai_grading_instructions_snapshot' => 'Snapshot privado da rubrica que precisa acompanhar o item de correção.',
        'instructions_version' => 7,
    ]);
});

it('does not process quiz config without correction enabled', function (): void {
    $organization = aiGraderCollectorOrganization();
    $environment = aiGraderCollectorEnvironment($organization);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment, [
        'correction_enabled' => false,
    ]);
    aiGraderCollectorQuestion($quizConfig);

    $summary = aiGraderCollectorRun();

    expect($summary['configurations_found'])->toBe(0)
        ->and(AiGraderCorrectionItem::query()->count())->toBe(0);
});

it('does not process question config without enabled flag', function (): void {
    $organization = aiGraderCollectorOrganization();
    $environment = aiGraderCollectorEnvironment($organization);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment);
    aiGraderCollectorQuestion($quizConfig, '29', ['enabled' => false]);

    $summary = aiGraderCollectorRun();

    expect($summary['configurations_found'])->toBe(0)
        ->and(AiGraderCorrectionItem::query()->count())->toBe(0);
});

it('does not process question config without AI grading instructions', function (): void {
    $organization = aiGraderCollectorOrganization();
    $environment = aiGraderCollectorEnvironment($organization);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment);
    aiGraderCollectorQuestion($quizConfig, '29', ['ai_grading_instructions' => null]);

    $summary = aiGraderCollectorRun();

    expect($summary['configurations_found'])->toBe(0)
        ->and(AiGraderCorrectionItem::query()->count())->toBe(0);
});

it('does not persist batches or items in dry run', function (): void {
    $organization = aiGraderCollectorOrganization();
    $environment = aiGraderCollectorEnvironment($organization);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment);
    aiGraderCollectorQuestion($quizConfig);
    aiGraderCollectorFakeCanvas([
        ['question_id' => 29, 'text' => '<p>Resposta simulada.</p>'],
    ]);

    $summary = aiGraderCollectorRun(['dry_run' => true]);

    expect($summary['pending_created'])->toBe(1)
        ->and($summary['child_course_mappings_persisted'])->toBe(0)
        ->and(AiGraderCorrectionItem::query()->count())->toBe(0)
        ->and(AiGraderBatch::query()->count())->toBe(0)
        ->and($quizConfig->fresh()->settings['child_courses'] ?? [])->toBe([]);
});

it('persists child course mapping in quiz config settings during collection', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-08 10:00:00'));
    $organization = aiGraderCollectorOrganization();
    $environment = aiGraderCollectorEnvironment($organization);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment, [
        'settings' => ['existing_key' => 'preserved'],
    ]);
    aiGraderCollectorQuestion($quizConfig);
    aiGraderCollectorFakeCanvas([
        ['question_id' => 29, 'text' => '<p>Resposta com vínculo.</p>'],
    ]);

    $summary = aiGraderCollectorRun();

    Carbon::setTestNow();
    $settings = $quizConfig->fresh()->settings;

    expect($summary['child_course_mappings_persisted'])->toBe(1)
        ->and($summary['child_course_mappings_updated'])->toBe(0)
        ->and($settings['existing_key'])->toBe('preserved')
        ->and($settings['child_courses'])->toHaveCount(1)
        ->and($settings['child_courses'][0])->toMatchArray([
            'child_course_id' => '11',
            'child_quiz_id' => '4',
            'child_assignment_id' => '12',
            'child_course_name' => null,
            'last_seen_at' => '2026-06-08 10:00:00',
            'source' => 'collector',
            'mapping_strategy' => 'command_parameters',
        ]);
});

it('does not duplicate child course mapping when collection runs twice', function (): void {
    $organization = aiGraderCollectorOrganization();
    $environment = aiGraderCollectorEnvironment($organization);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment);
    aiGraderCollectorQuestion($quizConfig);
    aiGraderCollectorFakeCanvas([
        ['question_id' => 29, 'text' => '<p>Resposta já processada.</p>'],
    ]);

    aiGraderCollectorRun();
    $summary = aiGraderCollectorRun();

    $settings = $quizConfig->fresh()->settings;

    expect($summary['child_course_mappings_persisted'])->toBe(0)
        ->and($summary['child_course_mappings_updated'])->toBe(1)
        ->and($summary['skipped_already_processed'])->toBe(1)
        ->and($settings['child_courses'])->toHaveCount(1);
});

it('updates last seen at when child course mapping already exists', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-08 11:30:00'));
    $organization = aiGraderCollectorOrganization();
    $environment = aiGraderCollectorEnvironment($organization);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment, [
        'settings' => [
            'other_setting' => true,
            'child_courses' => [
                [
                    'child_course_id' => '11',
                    'child_quiz_id' => '4',
                    'child_assignment_id' => '12',
                    'child_course_name' => 'Old name',
                    'last_seen_at' => '2026-06-01 08:00:00',
                    'source' => 'manual',
                    'mapping_strategy' => 'legacy',
                    'extra' => 'keep-me',
                ],
            ],
        ],
    ]);
    aiGraderCollectorQuestion($quizConfig);
    aiGraderCollectorFakeCanvas([
        ['question_id' => 29, 'text' => '<p>Resposta atualizando vínculo.</p>'],
    ]);

    $summary = aiGraderCollectorRun();

    Carbon::setTestNow();
    $settings = $quizConfig->fresh()->settings;

    expect($summary['child_course_mappings_persisted'])->toBe(0)
        ->and($summary['child_course_mappings_updated'])->toBe(1)
        ->and($settings['other_setting'])->toBeTrue()
        ->and($settings['child_courses'])->toHaveCount(1)
        ->and($settings['child_courses'][0]['last_seen_at'])->toBe('2026-06-08 11:30:00')
        ->and($settings['child_courses'][0]['source'])->toBe('collector')
        ->and($settings['child_courses'][0]['mapping_strategy'])->toBe('command_parameters')
        ->and($settings['child_courses'][0]['extra'])->toBe('keep-me');
});

it('scheduler dispatches pipeline after collector persisted child course mapping', function (): void {
    $organization = aiGraderCollectorOrganization();
    $environment = aiGraderCollectorEnvironment($organization);
    AiGraderAiProvider::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Collector Scheduler Mock',
        'provider_type' => AiGraderAiProvider::TYPE_MOCK,
        'enabled' => true,
        'is_default' => true,
    ]);
    $quizConfig = aiGraderCollectorQuizConfig($organization, $environment);
    $quizConfig->blueprintConfig->update([
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_AFTER_SUBMISSION,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH,
    ]);
    aiGraderCollectorQuestion($quizConfig);
    aiGraderCollectorFakeCanvas([
        ['question_id' => 29, 'text' => '<p>Resposta para scheduler.</p>'],
    ]);

    aiGraderCollectorRun(['blueprint_config_id' => (string) $quizConfig->ai_grader_blueprint_config_id]);

    Bus::fake();

    $this->artisan('ai-grader:dispatch-scheduled-pipelines --blueprint-config-id=' . $quizConfig->ai_grader_blueprint_config_id)
        ->assertExitCode(0);

    Bus::assertDispatched(AiGraderRunPipelineJob::class, fn (AiGraderRunPipelineJob $job): bool => $job->blueprintConfigId === $quizConfig->ai_grader_blueprint_config_id
        && $job->childCourseId === '11'
        && $job->childQuizId === '4'
        && $job->childAssignmentId === '12');
});
