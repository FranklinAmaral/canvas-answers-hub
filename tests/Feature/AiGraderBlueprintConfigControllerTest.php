<?php

declare(strict_types=1);

use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderAiProvider;
use App\Models\AiGraderClientSetting;
use App\Models\AiGraderQuestionConfig;
use App\Models\AiGraderQuizConfig;
use App\Models\CanvasEnvironment;
use App\Models\Organization;
use App\Models\User;
use App\Services\AiGrader\AiGraderConfigurationStatusService;
use Illuminate\Support\Facades\Http;

function aiGraderBlueprintOrganization(array $overrides = []): Organization
{
    return Organization::query()->create(array_merge([
        'name' => 'Blueprint Org',
        'slug' => 'blueprint-org-' . uniqid(),
        'timezone' => 'America/Sao_Paulo',
        'is_active' => true,
    ], $overrides));
}

function aiGraderBlueprintEnvironment(Organization $organization): CanvasEnvironment
{
    config(['app.key' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);

    return CanvasEnvironment::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Canvas Blueprint',
        'slug' => 'canvas-blueprint-' . uniqid(),
        'environment_type' => 'test',
        'base_url' => 'https://canvas.test',
        'api_token' => 'secret-token',
        'is_active' => true,
        'is_default' => true,
    ]);
}

function aiGraderEnableClient(Organization $organization, string $status = AiGraderClientSetting::STATUS_TRIAL): AiGraderClientSetting
{
    return AiGraderClientSetting::query()->create([
        'organization_id' => $organization->id,
        'enabled' => true,
        'status' => $status,
        'trial_quota_total' => 50,
        'purchased_quota_total' => 0,
        'quota_used' => 0,
        'quota_reserved' => 0,
    ]);
}

function aiGraderBlueprintPayload(Organization $organization, CanvasEnvironment $environment, array $overrides = []): array
{
    return array_merge([
        'organization_id' => $organization->id,
        'canvas_environment_id' => $environment->id,
        'blueprint_course_id' => 'bp-100',
        'blueprint_course_name' => 'Blueprint 100',
        'enabled' => '1',
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL,
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_MANUAL,
        'scheduled_at' => null,
        'default_feedback_language' => 'pt_BR',
        'teacher_can_adjust_score' => '1',
        'teacher_can_adjust_feedback' => '1',
        'teacher_can_publish' => '1',
        'teacher_can_republish' => '1',
        'teacher_can_export' => null,
    ], $overrides);
}

function aiGraderBlueprintConfig(Organization $organization, CanvasEnvironment $environment, array $overrides = []): AiGraderBlueprintConfig
{
    return AiGraderBlueprintConfig::query()->create(array_merge([
        'organization_id' => $organization->id,
        'canvas_environment_id' => $environment->id,
        'blueprint_course_id' => 'bp-100',
        'blueprint_course_name' => 'Blueprint 100',
        'enabled' => true,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL,
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_MANUAL,
        'teacher_can_adjust_score' => true,
        'teacher_can_adjust_feedback' => true,
        'teacher_can_publish' => true,
        'teacher_can_republish' => true,
        'teacher_can_export' => false,
        'default_feedback_language' => 'pt_BR',
    ], $overrides));
}

it('does not allow creating enabled blueprint config when organization has no GraderAI entitlement', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderBlueprintOrganization();
    $environment = aiGraderBlueprintEnvironment($organization);

    $this->actingAs($user)
        ->from(route('ai-grader.blueprints.create'))
        ->post(route('ai-grader.blueprints.store'), aiGraderBlueprintPayload($organization, $environment))
        ->assertRedirect(route('ai-grader.blueprints.create'))
        ->assertSessionHasErrors(['organization_id']);
});

it('allows creating blueprint config for trial organization', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderBlueprintOrganization();
    $environment = aiGraderBlueprintEnvironment($organization);
    aiGraderEnableClient($organization);

    $this->actingAs($user)
        ->post(route('ai-grader.blueprints.store'), aiGraderBlueprintPayload($organization, $environment))
        ->assertRedirect();

    $this->assertDatabaseHas('ai_grader_blueprint_configs', [
        'organization_id' => $organization->id,
        'canvas_environment_id' => $environment->id,
        'blueprint_course_id' => 'bp-100',
        'enabled' => true,
        'default_feedback_language' => 'pt_BR',
    ]);
});

it('rejects an inactive provider or a provider from another organization', function (string $case): void {
    $user = User::factory()->create();
    $organization = aiGraderBlueprintOrganization();
    $otherOrganization = aiGraderBlueprintOrganization();
    $environment = aiGraderBlueprintEnvironment($organization);
    aiGraderEnableClient($organization);
    $provider = AiGraderAiProvider::query()->create([
        'organization_id' => $case === 'other_organization' ? $otherOrganization->id : $organization->id,
        'name' => 'Invalid blueprint provider',
        'provider_type' => AiGraderAiProvider::TYPE_MOCK,
        'enabled' => $case !== 'inactive',
        'is_default' => false,
    ]);

    $this->actingAs($user)
        ->from(route('ai-grader.blueprints.create'))
        ->post(route('ai-grader.blueprints.store'), aiGraderBlueprintPayload($organization, $environment, [
            'ai_provider_id' => $provider->id,
        ]))
        ->assertRedirect(route('ai-grader.blueprints.create'))
        ->assertSessionHasErrors(['ai_provider_id']);
})->with(['inactive', 'other_organization']);

it('requires scheduled_at when trigger mode is after_date', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderBlueprintOrganization();
    $environment = aiGraderBlueprintEnvironment($organization);
    aiGraderEnableClient($organization);

    $this->actingAs($user)
        ->from(route('ai-grader.blueprints.create'))
        ->post(route('ai-grader.blueprints.store'), aiGraderBlueprintPayload($organization, $environment, [
            'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_AFTER_DATE,
            'scheduled_at' => null,
        ]))
        ->assertRedirect(route('ai-grader.blueprints.create'))
        ->assertSessionHasErrors(['scheduled_at']);
});

it('does not allow duplicate blueprint config for same organization environment and blueprint course', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderBlueprintOrganization();
    $environment = aiGraderBlueprintEnvironment($organization);
    aiGraderEnableClient($organization);
    aiGraderBlueprintConfig($organization, $environment);

    $this->actingAs($user)
        ->from(route('ai-grader.blueprints.create'))
        ->post(route('ai-grader.blueprints.store'), aiGraderBlueprintPayload($organization, $environment))
        ->assertRedirect(route('ai-grader.blueprints.create'))
        ->assertSessionHasErrors(['blueprint_course_id']);
});

it('syncs classic quizzes from Canvas without enabling correction automatically', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderBlueprintOrganization();
    $environment = aiGraderBlueprintEnvironment($organization);
    aiGraderEnableClient($organization);
    $blueprintConfig = aiGraderBlueprintConfig($organization, $environment);

    Http::fake([
        'https://canvas.test/api/v1/courses/bp-100/quizzes*' => Http::response([
            [
                'id' => 10,
                'title' => 'Discursive Quiz',
                'assignment_id' => 50,
                'quiz_type' => 'assignment',
                'points_possible' => 12,
                'question_count' => 3,
            ],
            [
                'id' => 99,
                'title' => 'New Quiz ignored',
                'quiz_type' => 'new_quizzes',
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->post(route('ai-grader.blueprints.sync-quizzes', $blueprintConfig))
        ->assertRedirect();

    $this->assertDatabaseHas('ai_grader_quiz_configs', [
        'ai_grader_blueprint_config_id' => $blueprintConfig->id,
        'canvas_quiz_id' => '10',
        'canvas_assignment_id' => '50',
        'canvas_quiz_title' => 'Discursive Quiz',
        'enabled' => false,
        'correction_enabled' => false,
    ]);

    $this->assertDatabaseMissing('ai_grader_quiz_configs', [
        'canvas_quiz_id' => '99',
    ]);
});

it('syncs questions and keeps existing AI grading instructions', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderBlueprintOrganization();
    $environment = aiGraderBlueprintEnvironment($organization);
    aiGraderEnableClient($organization);
    $blueprintConfig = aiGraderBlueprintConfig($organization, $environment);
    $quizConfig = AiGraderQuizConfig::query()->create([
        'ai_grader_blueprint_config_id' => $blueprintConfig->id,
        'canvas_quiz_id' => '10',
        'canvas_assignment_id' => '50',
        'canvas_quiz_title' => 'Discursive Quiz',
        'enabled' => false,
        'correction_enabled' => false,
    ]);

    AiGraderQuestionConfig::query()->create([
        'ai_grader_quiz_config_id' => $quizConfig->id,
        'canvas_question_id' => '29',
        'canvas_question_type' => AiGraderQuestionConfig::TYPE_ESSAY,
        'enabled' => true,
        'ai_grading_instructions' => 'Orientações privadas que não podem ser sobrescritas pela sincronização.',
        'instructions_version' => 2,
    ]);

    Http::fake([
        'https://canvas.test/api/v1/courses/bp-100/quizzes/10/questions*' => Http::response([
            [
                'id' => 29,
                'question_name' => 'Essay question',
                'question_type' => 'essay_question',
                'question_text' => '<p>Explique.</p>',
                'points_possible' => 10,
                'neutral_comments_html' => '<p>Comentário geral Canvas</p>',
            ],
            [
                'id' => 30,
                'question_name' => 'Objective question',
                'question_type' => 'multiple_choice_question',
                'points_possible' => 2,
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->post(route('ai-grader.blueprints.quizzes.sync-questions', [$blueprintConfig, $quizConfig]))
        ->assertRedirect();

    $this->assertDatabaseHas('ai_grader_question_configs', [
        'ai_grader_quiz_config_id' => $quizConfig->id,
        'canvas_question_id' => '29',
        'ai_grading_instructions' => 'Orientações privadas que não podem ser sobrescritas pela sincronização.',
        'canvas_neutral_comments_snapshot' => '<p>Comentário geral Canvas</p>',
    ]);

    $this->assertDatabaseMissing('ai_grader_question_configs', [
        'ai_grader_quiz_config_id' => $quizConfig->id,
        'canvas_question_id' => '30',
    ]);
});

it('does not allow enabling objective questions', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderBlueprintOrganization();
    $environment = aiGraderBlueprintEnvironment($organization);
    aiGraderEnableClient($organization);
    $blueprintConfig = aiGraderBlueprintConfig($organization, $environment);
    $quizConfig = AiGraderQuizConfig::query()->create([
        'ai_grader_blueprint_config_id' => $blueprintConfig->id,
        'canvas_quiz_id' => '10',
        'canvas_assignment_id' => '50',
    ]);
    $questionConfig = AiGraderQuestionConfig::query()->create([
        'ai_grader_quiz_config_id' => $quizConfig->id,
        'canvas_question_id' => '30',
        'canvas_question_type' => 'multiple_choice_question',
        'enabled' => false,
    ]);

    $this->actingAs($user)
        ->from(route('ai-grader.blueprints.questions.edit', [$blueprintConfig, $quizConfig, $questionConfig]))
        ->put(route('ai-grader.blueprints.questions.update', [$blueprintConfig, $quizConfig, $questionConfig]), [
            'enabled' => '1',
            'ai_grading_instructions' => 'Critérios objetivos não deveriam permitir habilitação por IA.',
        ])
        ->assertRedirect(route('ai-grader.blueprints.questions.edit', [$blueprintConfig, $quizConfig, $questionConfig]))
        ->assertSessionHasErrors(['enabled']);
});

it('requires instructions when enabling essay questions', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderBlueprintOrganization();
    $environment = aiGraderBlueprintEnvironment($organization);
    aiGraderEnableClient($organization);
    $blueprintConfig = aiGraderBlueprintConfig($organization, $environment);
    $quizConfig = AiGraderQuizConfig::query()->create([
        'ai_grader_blueprint_config_id' => $blueprintConfig->id,
        'canvas_quiz_id' => '10',
        'canvas_assignment_id' => '50',
    ]);
    $questionConfig = AiGraderQuestionConfig::query()->create([
        'ai_grader_quiz_config_id' => $quizConfig->id,
        'canvas_question_id' => '29',
        'canvas_question_type' => AiGraderQuestionConfig::TYPE_ESSAY,
        'enabled' => false,
    ]);

    $this->actingAs($user)
        ->from(route('ai-grader.blueprints.questions.edit', [$blueprintConfig, $quizConfig, $questionConfig]))
        ->put(route('ai-grader.blueprints.questions.update', [$blueprintConfig, $quizConfig, $questionConfig]), [
            'enabled' => '1',
            'ai_grading_instructions' => '',
        ])
        ->assertRedirect(route('ai-grader.blueprints.questions.edit', [$blueprintConfig, $quizConfig, $questionConfig]))
        ->assertSessionHasErrors(['ai_grading_instructions']);
});

it('increments instructions version when AI grading instructions change', function (): void {
    $user = User::factory()->create();
    $organization = aiGraderBlueprintOrganization();
    $environment = aiGraderBlueprintEnvironment($organization);
    aiGraderEnableClient($organization);
    $blueprintConfig = aiGraderBlueprintConfig($organization, $environment);
    $quizConfig = AiGraderQuizConfig::query()->create([
        'ai_grader_blueprint_config_id' => $blueprintConfig->id,
        'canvas_quiz_id' => '10',
        'canvas_assignment_id' => '50',
    ]);
    $questionConfig = AiGraderQuestionConfig::query()->create([
        'ai_grader_quiz_config_id' => $quizConfig->id,
        'canvas_question_id' => '29',
        'canvas_question_type' => AiGraderQuestionConfig::TYPE_ESSAY,
        'enabled' => false,
        'ai_grading_instructions' => 'Orientação anterior com tamanho suficiente para ser válida.',
        'instructions_version' => 3,
    ]);

    $this->actingAs($user)
        ->put(route('ai-grader.blueprints.questions.update', [$blueprintConfig, $quizConfig, $questionConfig]), [
            'enabled' => '1',
            'ai_grading_instructions' => 'Nova rubrica privada com critérios claros, resposta esperada e feedback.',
        ])
        ->assertRedirect(route('ai-grader.blueprints.quizzes.show', [$blueprintConfig, $quizConfig]));

    expect($questionConfig->fresh()->instructions_version)->toBe(4)
        ->and($questionConfig->fresh()->instructions_updated_at)->not->toBeNull();
});

it('calculates ready configuration only when quiz and essay question are fully enabled', function (): void {
    $organization = aiGraderBlueprintOrganization();
    $environment = aiGraderBlueprintEnvironment($organization);
    aiGraderEnableClient($organization);
    $blueprintConfig = aiGraderBlueprintConfig($organization, $environment);
    $quizConfig = AiGraderQuizConfig::query()->create([
        'ai_grader_blueprint_config_id' => $blueprintConfig->id,
        'canvas_quiz_id' => '10',
        'canvas_assignment_id' => '50',
        'enabled' => true,
        'correction_enabled' => true,
    ]);
    AiGraderQuestionConfig::query()->create([
        'ai_grader_quiz_config_id' => $quizConfig->id,
        'canvas_question_id' => '29',
        'canvas_question_type' => AiGraderQuestionConfig::TYPE_ESSAY,
        'enabled' => true,
        'ai_grading_instructions' => 'Rubrica privada com resposta esperada, critérios de avaliação e feedback.',
    ]);

    $status = app(AiGraderConfigurationStatusService::class)->forQuiz($quizConfig);

    expect($status['ready'])->toBeTrue();
});
