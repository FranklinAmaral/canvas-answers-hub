<?php

declare(strict_types=1);

use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderCorrectionItem;
use App\Models\AiGraderQuestionConfig;
use App\Models\AiGraderQuizConfig;
use App\Models\CanvasEnvironment;
use App\Models\Organization;
use App\Models\User;

function aiGraderCorrectionItemsOrganization(array $overrides = []): Organization
{
    return Organization::query()->create(array_merge([
        'name' => 'Correction Org',
        'slug' => 'correction-org-' . uniqid(),
        'timezone' => 'America/Sao_Paulo',
        'is_active' => true,
    ], $overrides));
}

function aiGraderCorrectionItemsEnvironment(Organization $organization, array $overrides = []): CanvasEnvironment
{
    config(['app.key' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);

    return CanvasEnvironment::query()->create(array_merge([
        'organization_id' => $organization->id,
        'name' => 'Correction Canvas',
        'slug' => 'correction-canvas-' . uniqid(),
        'environment_type' => 'test',
        'base_url' => 'https://canvas.test',
        'api_token' => 'secret-token',
        'is_active' => true,
        'is_default' => true,
    ], $overrides));
}

/**
 * @return array{organization: Organization, environment: CanvasEnvironment, blueprint: AiGraderBlueprintConfig, quiz: AiGraderQuizConfig, question: AiGraderQuestionConfig, item: AiGraderCorrectionItem}
 */
function aiGraderCorrectionItemsFixture(array $itemOverrides = [], array $organizationOverrides = []): array
{
    $organization = aiGraderCorrectionItemsOrganization($organizationOverrides);
    $environment = aiGraderCorrectionItemsEnvironment($organization);
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
    $quiz = AiGraderQuizConfig::query()->create([
        'ai_grader_blueprint_config_id' => $blueprint->id,
        'canvas_quiz_id' => '1',
        'canvas_assignment_id' => '1',
        'canvas_quiz_title' => 'Blueprint Essay Quiz',
        'canvas_quiz_type' => 'assignment',
        'points_possible' => 10,
        'question_count' => 1,
        'enabled' => true,
        'correction_enabled' => true,
    ]);
    $question = AiGraderQuestionConfig::query()->create([
        'ai_grader_quiz_config_id' => $quiz->id,
        'canvas_question_id' => '4',
        'canvas_question_name' => 'Blueprint Essay',
        'canvas_question_type' => AiGraderQuestionConfig::TYPE_ESSAY,
        'canvas_question_text' => '<p>Explique.</p>',
        'canvas_points_possible' => 10,
        'enabled' => true,
        'ai_grading_instructions' => 'Rubrica privada com critérios claros para avaliar a resposta discursiva.',
        'instructions_version' => 2,
    ]);

    $item = AiGraderCorrectionItem::query()->create(array_merge([
        'organization_id' => $organization->id,
        'canvas_environment_id' => $environment->id,
        'ai_grader_blueprint_config_id' => $blueprint->id,
        'ai_grader_quiz_config_id' => $quiz->id,
        'ai_grader_question_config_id' => $question->id,
        'blueprint_course_id' => '3',
        'child_course_id' => '11',
        'child_course_name' => 'Child Course',
        'canvas_quiz_id' => '4',
        'canvas_assignment_id' => '12',
        'canvas_quiz_submission_id' => '44',
        'canvas_assignment_submission_id' => '900',
        'canvas_user_id' => '11',
        'canvas_user_name' => 'Maria Student',
        'canvas_user_login_id' => 'maria.student',
        'attempt' => 1,
        'canvas_question_id' => '29',
        'canvas_question_name' => 'Child Essay',
        'canvas_question_text' => '<p>Explique o conceito.</p>',
        'canvas_points_possible' => 10,
        'answer_html' => '<p>Resposta discursiva da Maria.</p>',
        'answer_text' => 'Resposta discursiva da Maria.',
        'ai_grading_instructions_snapshot' => 'Rubrica privada com critérios claros para avaliar a resposta discursiva.',
        'instructions_version' => 2,
        'status' => AiGraderCorrectionItem::STATUS_PENDING,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL,
        'metadata' => [
            'blueprint_question_id' => '4',
            'child_question_id' => '29',
            'question_mapping_strategy' => 'position_type',
        ],
    ], $itemOverrides));

    return compact('organization', 'environment', 'blueprint', 'quiz', 'question', 'item');
}

it('allows authenticated user to access correction items index', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ai-grader.correction-items.index'))
        ->assertOk()
        ->assertSee('Itens de correção do GraderAI');
});

it('lists correction items in index', function (): void {
    $user = User::factory()->create();
    aiGraderCorrectionItemsFixture();

    $this->actingAs($user)
        ->get(route('ai-grader.correction-items.index'))
        ->assertOk()
        ->assertSee('Maria Student')
        ->assertSee('Child Essay')
        ->assertSee(AiGraderCorrectionItem::STATUS_PENDING);
});

it('filters correction items by status', function (): void {
    $user = User::factory()->create();
    aiGraderCorrectionItemsFixture([
        'canvas_user_name' => 'Pending Student',
        'status' => AiGraderCorrectionItem::STATUS_PENDING,
    ]);
    aiGraderCorrectionItemsFixture([
        'canvas_user_name' => 'Quota Student',
        'canvas_user_id' => '12',
        'canvas_quiz_submission_id' => '45',
        'status' => AiGraderCorrectionItem::STATUS_PENDING_QUOTA,
    ]);

    $this->actingAs($user)
        ->get(route('ai-grader.correction-items.index', ['status' => AiGraderCorrectionItem::STATUS_PENDING]))
        ->assertOk()
        ->assertSee('Pending Student')
        ->assertDontSee('Quota Student');
});

it('filters correction items by organization', function (): void {
    $user = User::factory()->create();
    $first = aiGraderCorrectionItemsFixture([
        'canvas_user_name' => 'First Org Student',
    ], ['name' => 'First Correction Org']);
    aiGraderCorrectionItemsFixture([
        'canvas_user_name' => 'Second Org Student',
        'canvas_user_id' => '12',
        'canvas_quiz_submission_id' => '45',
    ], ['name' => 'Second Correction Org']);

    $this->actingAs($user)
        ->get(route('ai-grader.correction-items.index', ['organization_id' => $first['organization']->id]))
        ->assertOk()
        ->assertSee('First Org Student')
        ->assertDontSee('Second Org Student');
});

it('filters correction items by student search', function (): void {
    $user = User::factory()->create();
    aiGraderCorrectionItemsFixture(['canvas_user_name' => 'Maria Student']);
    aiGraderCorrectionItemsFixture([
        'canvas_user_name' => 'Joao Student',
        'canvas_user_id' => '12',
        'canvas_user_login_id' => 'joao.student',
        'canvas_quiz_submission_id' => '45',
    ]);

    $this->actingAs($user)
        ->get(route('ai-grader.correction-items.index', ['canvas_user_id' => 'Maria']))
        ->assertOk()
        ->assertSee('Maria Student')
        ->assertDontSee('Joao Student');
});

it('shows correction item main data', function (): void {
    $user = User::factory()->create();
    $fixture = aiGraderCorrectionItemsFixture();

    $this->actingAs($user)
        ->get(route('ai-grader.correction-items.show', $fixture['item']))
        ->assertOk()
        ->assertSee('Item de correção #' . $fixture['item']->id)
        ->assertSee('Maria Student')
        ->assertSee('Child Essay')
        ->assertSee('44')
        ->assertSee('29');
});

it('shows answer text and grading instructions snapshot', function (): void {
    $user = User::factory()->create();
    $fixture = aiGraderCorrectionItemsFixture([
        'answer_text' => 'Texto discursivo auditável.',
        'ai_grading_instructions_snapshot' => 'Snapshot da rubrica configurada na blueprint.',
    ]);

    $this->actingAs($user)
        ->get(route('ai-grader.correction-items.show', $fixture['item']))
        ->assertOk()
        ->assertSee('Texto discursivo auditável.')
        ->assertSee('Snapshot da rubrica configurada na blueprint.');
});

it('redirects guest from correction items index', function (): void {
    $this->get(route('ai-grader.correction-items.index'))
        ->assertRedirect(route('login'));
});

it('does not expose sensitive tokens from metadata', function (): void {
    $user = User::factory()->create();
    $fixture = aiGraderCorrectionItemsFixture([
        'metadata' => [
            'safe_value' => 'safe-metadata-value',
            'validation_token' => 'secret-validation-token-value',
            'nested' => [
                'api_token' => 'secret-api-token-value',
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('ai-grader.correction-items.show', $fixture['item']))
        ->assertOk()
        ->assertSee('safe-metadata-value')
        ->assertSee('[hidden]')
        ->assertDontSee('secret-validation-token-value')
        ->assertDontSee('secret-api-token-value');
});
