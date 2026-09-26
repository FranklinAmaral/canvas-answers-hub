<?php

declare(strict_types=1);

use App\Models\AiGraderAiProvider;
use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderClientSetting;
use App\Models\AiGraderCorrectionItem;
use App\Models\AiGraderQuestionConfig;
use App\Models\AiGraderQuizConfig;
use App\Models\CanvasEnvironment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

function aiGraderLtiContext(string $courseId = '11', string $role = 'Instructor'): array
{
    $environment = CanvasEnvironment::query()->latest('id')->first();
    $roles = trim($role) !== '' ? [$role] : [];
    $context = [
        'lti_authenticated' => true,
        'lti_installation_id' => $environment?->id,
        'issuer' => $environment?->lti_issuer ?? 'https://canvas.test',
        'client_id' => $environment?->lti_client_id ?? 'client-123',
        'deployment_id' => $environment?->lti_deployment_id ?? 'deployment-123',
        'canvas_environment_id' => $environment?->id,
        'organization_id' => $environment?->organization_id,
        'course_id' => $courseId,
        'account_id' => '77',
        'canvas_user_id' => '2001',
        'name' => 'Teacher One',
        'login' => 'teacher.one',
        'email' => 'teacher@example.test',
        'roles' => $roles,
        'launch_id' => 'validated-launch',
        'context_title' => 'Canvas Course '.$courseId,
        'expires_at' => now()->addHour()->timestamp,
    ];

    session(['ai_grader_lti_context' => $context]);

    return [
        'canvas_course_id' => $courseId,
        'canvas_user_id' => '2001',
        'canvas_user_login_id' => 'teacher.one',
        'lis_person_name_full' => 'Teacher One',
        'lis_person_contact_email_primary' => 'teacher@example.test',
        'roles' => $role,
    ];
}

function aiGraderLtiRedirectQuery(array $context, array $overrides = []): array
{
    return [];
}

function assertAiGraderLtiRedirect(TestResponse $response, array $expectedQuery, ?string $expectedFragment = null): void
{
    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    $query = [];

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith(route('lti.ai-grader.index'));

    foreach ($expectedQuery as $key => $value) {
        if (is_array($value)) {
            expect($query[$key] ?? null)->toEqual($value);

            continue;
        }

        expect((string) ($query[$key] ?? ''))->toBe((string) $value);
    }

    expect(parse_url($location, PHP_URL_FRAGMENT))->toBe($expectedFragment);
}

function aiGraderLtiOrganization(): Organization
{
    $organization = Organization::query()->create([
        'name' => 'LTI Org',
        'slug' => 'lti-org-'.uniqid(),
        'timezone' => 'America/Sao_Paulo',
        'is_active' => true,
    ]);

    AiGraderClientSetting::query()->create([
        'organization_id' => $organization->id,
        'enabled' => true,
        'status' => AiGraderClientSetting::STATUS_TRIAL,
        'trial_quota_total' => 50,
        'purchased_quota_total' => 10,
        'quota_used' => 3,
        'quota_reserved' => 1,
    ]);

    return $organization;
}

function aiGraderLtiEnvironment(Organization $organization): CanvasEnvironment
{
    config(['app.key' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);

    return CanvasEnvironment::query()->create([
        'organization_id' => $organization->id,
        'name' => 'LTI Canvas',
        'slug' => 'lti-canvas-'.uniqid(),
        'environment_type' => 'test',
        'base_url' => 'https://canvas.test',
        'api_token' => 'secret-token',
        'is_active' => true,
        'is_default' => true,
        'lti_enabled' => true,
        'lti_issuer' => 'https://canvas.test',
        'lti_client_id' => 'client-'.$organization->id,
        'lti_deployment_id' => 'deployment-'.$organization->id,
        'lti_authorization_endpoint' => 'https://canvas.test/api/lti/authorize_redirect',
        'lti_jwks_uri' => 'https://canvas.test/api/lti/security/jwks',
    ]);
}

function aiGraderLtiProvider(Organization $organization): AiGraderAiProvider
{
    return AiGraderAiProvider::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Mock LTI Provider',
        'provider_type' => AiGraderAiProvider::TYPE_MOCK,
        'enabled' => true,
        'is_default' => true,
        'settings' => ['endpoint_path' => '/v1/chat/completions'],
    ]);
}

/**
 * @return array{organization: Organization, environment: CanvasEnvironment, blueprint: AiGraderBlueprintConfig, quiz: AiGraderQuizConfig, question: AiGraderQuestionConfig}
 */
function aiGraderLtiBlueprintFixture(array $quizSettings = []): array
{
    $organization = aiGraderLtiOrganization();
    $environment = aiGraderLtiEnvironment($organization);
    $blueprint = AiGraderBlueprintConfig::query()->create([
        'organization_id' => $organization->id,
        'canvas_environment_id' => $environment->id,
        'blueprint_course_id' => '3',
        'blueprint_course_name' => 'LTI Blueprint Course',
        'enabled' => true,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH,
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_AFTER_SUBMISSION,
        'default_feedback_language' => 'pt_BR',
    ]);
    $quiz = AiGraderQuizConfig::query()->create([
        'ai_grader_blueprint_config_id' => $blueprint->id,
        'canvas_quiz_id' => '1',
        'canvas_assignment_id' => '1',
        'canvas_quiz_title' => 'LTI Essay Quiz',
        'canvas_quiz_type' => 'assignment',
        'points_possible' => 10,
        'question_count' => 1,
        'enabled' => true,
        'correction_enabled' => true,
        'settings' => $quizSettings,
    ]);
    $question = AiGraderQuestionConfig::query()->create([
        'ai_grader_quiz_config_id' => $quiz->id,
        'canvas_question_id' => '4',
        'canvas_question_name' => 'Blueprint Essay',
        'canvas_question_type' => AiGraderQuestionConfig::TYPE_ESSAY,
        'canvas_question_text' => '<p>Explique.</p>',
        'canvas_points_possible' => 10,
        'enabled' => true,
        'ai_grading_instructions' => 'Rubrica privada para avaliar a resposta discursiva com critérios claros.',
        'instructions_version' => 1,
    ]);

    return compact('organization', 'environment', 'blueprint', 'quiz', 'question');
}

function aiGraderLtiCorrectionItem(array $overrides = []): AiGraderCorrectionItem
{
    $fixture = aiGraderLtiBlueprintFixture([
        'child_courses' => [
            [
                'child_course_id' => '11',
                'child_quiz_id' => '4',
                'child_assignment_id' => '12',
                'last_seen_at' => '2026-06-08 10:00:00',
                'source' => 'collector',
            ],
        ],
    ]);

    return AiGraderCorrectionItem::query()->create(array_merge([
        'organization_id' => $fixture['organization']->id,
        'canvas_environment_id' => $fixture['environment']->id,
        'ai_grader_blueprint_config_id' => $fixture['blueprint']->id,
        'ai_grader_quiz_config_id' => $fixture['quiz']->id,
        'ai_grader_question_config_id' => $fixture['question']->id,
        'blueprint_course_id' => '3',
        'child_course_id' => '11',
        'child_course_name' => 'Child Course 11',
        'canvas_quiz_id' => '4',
        'canvas_assignment_id' => '12',
        'canvas_quiz_submission_id' => '44',
        'canvas_assignment_submission_id' => '900',
        'canvas_user_id' => '501',
        'canvas_user_name' => 'Maria Student',
        'canvas_user_login_id' => 'maria.student',
        'attempt' => 1,
        'canvas_question_id' => '29',
        'canvas_question_name' => 'Child Essay',
        'canvas_question_text' => '<p>Explique o conceito.</p>',
        'canvas_points_possible' => 10,
        'answer_html' => '<p>Resposta discursiva da Maria.</p>',
        'answer_text' => 'Resposta discursiva da Maria.',
        'ai_grading_instructions_snapshot' => 'Rubrica privada para avaliar a resposta discursiva com critérios claros.',
        'instructions_version' => 1,
        'status' => AiGraderCorrectionItem::STATUS_AI_CORRECTED,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH,
        'ai_score' => 8,
        'ai_feedback' => 'Feedback da IA para Maria.',
        'canvas_published_score' => 8,
        'canvas_published_feedback' => 'Feedback publicado no Canvas.',
        'published_at' => now(),
        'metadata' => [
            'blueprint_question_id' => '4',
            'child_question_id' => '29',
            'question_mapping_strategy' => 'position_type',
        ],
    ], $overrides));
}

beforeEach(function (): void {
    $this->courseResponses = [];
    $this->courseStatuses = [];
    $test = $this;

    Http::fake(function (Request $request) use ($test) {
        if (preg_match('#^https://canvas\.test/api/v1/courses/([^/?]+)$#', $request->url(), $matches) === 1) {
            $courseId = (string) $matches[1];
            $response = $test->courseResponses[$courseId] ?? [
                'id' => (int) $courseId,
                'name' => 'Canvas Course '.$courseId,
                'blueprint' => in_array($courseId, ['3', '300'], true),
            ];
            $status = $test->courseStatuses[$courseId] ?? 200;

            return Http::response($response, $status);
        }

        return Http::response([], 200);
    });
});

it('launch in blueprint shows administrative summary', function (): void {
    aiGraderLtiBlueprintFixture([
        'child_courses' => [
            ['child_course_id' => '11', 'child_quiz_id' => '4', 'child_assignment_id' => '12', 'source' => 'collector'],
        ],
    ]);

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('3')))
        ->assertOk()
        ->assertSee('Resumo da blueprint GraderAI')
        ->assertSee('LTI Essay Quiz')
        ->assertSee('Configurar Classic Quizzes')
        ->assertDontSee('Disciplinas filhas conhecidas');
});

it('launch in child course shows teacher correction list', function (): void {
    aiGraderLtiCorrectionItem();

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('11')))
        ->assertOk()
        ->assertSee('Correcoes do GraderAI')
        ->assertSee('Maria Student')
        ->assertSee('Child Essay')
        ->assertSee('ai_corrected')
        ->assertSee('Detalhes')
        ->assertDontSee('Configurar GraderAI nesta blueprint')
        ->assertDontSee('Configuracao da blueprint')
        ->assertDontSee('Configurar Classic Quizzes');
});

it('launch uses legacy is_blueprint true when blueprint field is absent', function (): void {
    aiGraderLtiBlueprintFixture();

    $this->courseResponses['3'] = [
        'id' => 3,
        'name' => 'Legacy Blueprint 3',
        'is_blueprint' => true,
    ];

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('3')))
        ->assertOk()
        ->assertSee('Resumo da blueprint GraderAI')
        ->assertSee('course_id 3');
});

it('launch uses legacy is_blueprint false when blueprint field is absent', function (): void {
    aiGraderLtiCorrectionItem();

    $this->courseResponses['11'] = [
        'id' => 11,
        'name' => 'Legacy Child 11',
        'is_blueprint' => false,
    ];

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('11')))
        ->assertOk()
        ->assertSee('Correcoes do GraderAI')
        ->assertDontSee('Configurar GraderAI nesta blueprint');
});

it('child course lists only correction items from current course', function (): void {
    aiGraderLtiCorrectionItem(['canvas_user_name' => 'Current Course Student']);
    aiGraderLtiCorrectionItem([
        'child_course_id' => '22',
        'canvas_user_name' => 'Other Course Student',
        'canvas_user_id' => '502',
        'canvas_quiz_submission_id' => '45',
    ]);

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('11')))
        ->assertOk()
        ->assertSee('Current Course Student')
        ->assertDontSee('Other Course Student');
});

it('does not allow viewing item from another child course', function (): void {
    $item = aiGraderLtiCorrectionItem(['child_course_id' => '22']);

    $this->get(route('lti.ai-grader.show', array_merge(['correctionItem' => $item], aiGraderLtiContext('11'))))
        ->assertForbidden()
        ->assertSee('Você não tem permissão');
});

it('blocks learner role', function (): void {
    aiGraderLtiCorrectionItem();

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('11', 'Learner')))
        ->assertForbidden()
        ->assertSee('role_not_allowed')
        ->assertSee('Learner');
});

it('blocks student role', function (): void {
    aiGraderLtiCorrectionItem();

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('11', 'Student')))
        ->assertForbidden()
        ->assertSee('role_not_allowed')
        ->assertSee('Student');
});

it('blocks missing roles', function (): void {
    aiGraderLtiCorrectionItem();

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('11', '')))
        ->assertForbidden()
        ->assertSee('missing_roles')
        ->assertSee('Nenhuma role recebida.');
});

it('does not accept an unsigned id token or a caller-supplied state', function (): void {
    aiGraderLtiCorrectionItem();

    $token = 'eyJhbGciOiJub25lIn0.eyJub25jZSI6Im5vbmNlLTEyMyJ9.';

    $this->post('http://graderai.example.test/lti/launch', [
        'id_token' => $token,
        'state' => 'state-123',
    ])
        ->assertBadRequest()
        ->assertSee('invalid_or_replayed_state')
        ->assertDontSee($token);
});

it('does not use flat custom claims from an unverified token', function (): void {
    aiGraderLtiBlueprintFixture();

    $token = 'eyJhbGciOiJub25lIn0.eyJodHRwczovL3B1cmwuaW1zZ2xvYmFsLm9yZy9zcGVjL2x0aS9jbGFpbS9jdXN0b20uY2FudmFzX2NvdXJzZV9pZCI6IjMifQ.';

    $this->post('http://graderai.example.test/lti/launch', ['id_token' => $token])
        ->assertBadRequest()
        ->assertSee('missing_state')
        ->assertDontSee($token);
});

it('allows administrator role', function (): void {
    aiGraderLtiCorrectionItem();

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('11', 'Administrator')))
        ->assertOk()
        ->assertSee('Maria Student');
});

it('allows admin role', function (): void {
    aiGraderLtiCorrectionItem();

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('11', 'Admin')))
        ->assertOk()
        ->assertSee('Maria Student');
});

it('launch ignores course id from Canvas request fields', function (): void {
    aiGraderLtiCorrectionItem();

    $this->post('http://graderai.example.test/lti/launch', [
        'canvas_course_id' => '11',
        'canvas_user_id' => '2001',
        'canvas_user_login_id' => 'teacher.canvas',
        'canvas_membership_roles' => 'TeacherEnrollment',
    ])
        ->assertBadRequest()
        ->assertSee('missing_state')
        ->assertDontSee('teacher.canvas');
});

it('launch ignores course id from custom Canvas request fields', function (): void {
    aiGraderLtiCorrectionItem();

    $this->post('http://graderai.example.test/lti/launch', [
        'custom_canvas_course_id' => '11',
        'custom_canvas_user_id' => '2001',
        'custom_canvas_user_login_id' => 'teacher.custom',
        'custom_canvas_membership_roles' => 'TeacherEnrollment',
    ])
        ->assertBadRequest()
        ->assertSee('missing_state')
        ->assertDontSee('teacher.custom');
});

it('launch ignores course id from nested custom fields', function (): void {
    aiGraderLtiCorrectionItem();

    $this->post('http://graderai.example.test/lti/launch', [
        'custom' => [
            'canvas_course_id' => '11',
            'canvas_user_id' => '2001',
            'canvas_user_login_id' => 'teacher.nested',
            'canvas_membership_roles' => 'TeacherEnrollment',
        ],
    ])
        ->assertBadRequest()
        ->assertSee('missing_state')
        ->assertDontSee('teacher.nested');
});

it('does not authorize a role supplied outside validated claims', function (): void {
    aiGraderLtiCorrectionItem();

    $this->post('http://graderai.example.test/lti/launch', [
        'custom_canvas_course_id' => '11',
        'custom_canvas_user_id' => '2001',
        'custom_canvas_user_login_id' => 'student.enrollment',
        'custom_canvas_membership_roles' => 'StudentEnrollment',
    ])
        ->assertBadRequest()
        ->assertSee('missing_state')
        ->assertDontSee('student.enrollment')
        ->assertDontSee('StudentEnrollment');
});

it('blocks an id token launch without a bound state', function (): void {
    $token = 'unverified-id-token';

    $this->post('http://graderai.example.test/lti/launch', ['id_token' => $token])
        ->assertBadRequest()
        ->assertSee('missing_state')
        ->assertDontSee($token);
});

it('blocks an authenticated LTI context without roles', function (): void {
    aiGraderLtiCorrectionItem();

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('11', '')))
        ->assertForbidden()
        ->assertSee('missing_roles')
        ->assertSee('teacher.one');
});

it('allows a teaching assistant to review but not publish', function (): void {
    $item = aiGraderLtiCorrectionItem();
    aiGraderLtiContext('11', 'TeachingAssistant');

    $this->post(route('lti.ai-grader.review.save-draft', ['correctionItem' => $item]), [
        'final_score' => 7,
        'final_feedback' => 'Revisão do assistente.',
    ])->assertRedirect();

    $this->assertDatabaseHas('ai_grader_correction_items', [
        'id' => $item->id,
        'review_status' => AiGraderCorrectionItem::REVIEW_STATUS_DRAFT,
    ]);

    $this->post(route('lti.ai-grader.review.publish', ['correctionItem' => $item]), [
        'final_score' => 7,
        'final_feedback' => 'Tentativa de publicação.',
    ])
        ->assertForbidden()
        ->assertSee('role_not_allowed');
});

it('allows instructor role without administrative login', function (): void {
    aiGraderLtiCorrectionItem();

    $this->assertGuest();

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('11', 'Instructor')))
        ->assertOk()
        ->assertSee('Correcoes do GraderAI');
});

it('shows setup screen when blueprint course has no GraderAI configuration', function (): void {
    $organization = aiGraderLtiOrganization();
    aiGraderLtiEnvironment($organization);

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('300')))
        ->assertOk()
        ->assertSee('Configurar GraderAI nesta blueprint')
        ->assertSee('course_id 300')
        ->assertSee('Esta blueprint ainda nao esta habilitada para correcao por IA.');
});

it('validated session context renders setup screen for blueprint course without configuration', function (): void {
    $organization = aiGraderLtiOrganization();
    aiGraderLtiEnvironment($organization);

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('300', 'TeacherEnrollment')))
        ->assertOk()
        ->assertSee('Configurar GraderAI nesta blueprint')
        ->assertSee('teacher.one');
});

it('shows empty child course state when a child course mapping already exists', function (): void {
    aiGraderLtiBlueprintFixture([
        'child_courses' => [
            [
                'child_course_id' => '99',
                'child_quiz_id' => '4',
                'child_assignment_id' => '12',
                'last_seen_at' => '2026-06-08 10:00:00',
                'source' => 'collector',
            ],
        ],
    ]);

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('99')))
        ->assertOk()
        ->assertSee('Nenhuma correcao encontrada para esta disciplina.')
        ->assertDontSee('Configurar GraderAI nesta blueprint');
});

it('blueprint screen lists configured quizzes without child course grid', function (): void {
    aiGraderLtiBlueprintFixture([
        'child_courses' => [
            [
                'child_course_id' => '11',
                'child_quiz_id' => '4',
                'child_assignment_id' => '12',
                'last_seen_at' => '2026-06-08 10:00:00',
                'source' => 'collector',
            ],
        ],
    ]);

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('3')))
        ->assertOk()
        ->assertSee('Quizzes configurados')
        ->assertSee('LTI Essay Quiz')
        ->assertDontSee('Disciplinas filhas conhecidas')
        ->assertSee('Configurar Classic Quizzes');
});

it('allows privileged LTI roles to see blueprint setup screen', function (string $role): void {
    $organization = aiGraderLtiOrganization();
    aiGraderLtiEnvironment($organization);

    $this->courseResponses['300'] = [
        'id' => 300,
        'name' => 'Blueprint 300 From Canvas',
        'blueprint' => true,
    ];

    $this->assertGuest();

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('300', $role)))
        ->assertOk()
        ->assertSee('Configurar GraderAI nesta blueprint')
        ->assertSee('course_id 300');
})->with([
    'Administrator',
    'DesignerEnrollment',
    'ContentDeveloper',
]);

it('creates blueprint config from LTI setup without administrative login', function (): void {
    $organization = aiGraderLtiOrganization();
    $environment = aiGraderLtiEnvironment($organization);
    $provider = aiGraderLtiProvider($organization);

    $this->assertGuest();

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('300', 'TeacherEnrollment')))
        ->assertOk()
        ->assertSee('Configurar GraderAI nesta blueprint');

    $this->post(route('lti.ai-grader.blueprint.enable'), [
        'organization_id' => $organization->id,
        'canvas_environment_id' => $environment->id,
        'ai_provider_id' => $provider->id,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL,
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_AFTER_SUBMISSION,
    ])
        ->assertRedirect();

    $this->assertDatabaseHas('ai_grader_blueprint_configs', [
        'organization_id' => $organization->id,
        'canvas_environment_id' => $environment->id,
        'ai_provider_id' => $provider->id,
        'blueprint_course_id' => '300',
        'blueprint_course_name' => 'Canvas Course 300',
        'enabled' => true,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL,
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_AFTER_SUBMISSION,
    ]);
});

it('configured blueprint screen shows provider and modes', function (): void {
    $fixture = aiGraderLtiBlueprintFixture();
    $provider = aiGraderLtiProvider($fixture['organization']);
    $fixture['blueprint']->update([
        'ai_provider_id' => $provider->id,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL,
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_MANUAL,
    ]);

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('3')))
        ->assertOk()
        ->assertSee('Mock LTI Provider')
        ->assertSee(AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL)
        ->assertSee(AiGraderBlueprintConfig::TRIGGER_MANUAL)
        ->assertSee('Configuracao da blueprint');
});

it('saving blueprint settings again updates without 404', function (): void {
    $fixture = aiGraderLtiBlueprintFixture();
    $provider = aiGraderLtiProvider($fixture['organization']);
    $context = array_merge(aiGraderLtiContext('3', 'DesignerEnrollment'), [
        'canvas_environment_id' => $fixture['environment']->id,
        'organization_id' => $fixture['organization']->id,
    ]);

    $response = $this->post(route('lti.ai-grader.blueprint.settings', $fixture['blueprint']), array_merge($context, [
        'ai_provider_id' => $provider->id,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL,
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_MANUAL,
    ]));

    assertAiGraderLtiRedirect($response, aiGraderLtiRedirectQuery($context));

    $response->assertSessionHas('success', 'Configuracoes da blueprint atualizadas.');

    $this->assertDatabaseHas('ai_grader_blueprint_configs', [
        'id' => $fixture['blueprint']->id,
        'blueprint_course_id' => '3',
        'ai_provider_id' => $provider->id,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL,
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_MANUAL,
    ]);
});

it('configuring classic quizzes redirects back to the LTI blueprint screen', function (): void {
    $fixture = aiGraderLtiBlueprintFixture();
    $context = array_merge(aiGraderLtiContext('3', 'DesignerEnrollment'), [
        'canvas_environment_id' => $fixture['environment']->id,
        'organization_id' => $fixture['organization']->id,
    ]);

    $response = $this->post(route('lti.ai-grader.blueprint.sync-quizzes', $fixture['blueprint']), $context);

    assertAiGraderLtiRedirect($response, aiGraderLtiRedirectQuery($context));

    $this->get((string) $response->headers->get('Location'))
        ->assertOk()
        ->assertSee('Resumo da blueprint GraderAI')
        ->assertSee($fixture['quiz']->canvas_quiz_title);
});

it('configuring essay questions redirects back to the current quiz and ignores objective questions', function (): void {
    $fixture = aiGraderLtiBlueprintFixture();
    $context = array_merge(aiGraderLtiContext('3', 'DesignerEnrollment'), [
        'canvas_environment_id' => $fixture['environment']->id,
        'organization_id' => $fixture['organization']->id,
    ]);
    $test = $this;

    AiGraderQuestionConfig::query()->create([
        'ai_grader_quiz_config_id' => $fixture['quiz']->id,
        'canvas_question_id' => '999',
        'canvas_question_name' => 'Old Objective Question',
        'canvas_question_type' => 'multiple_choice_question',
        'canvas_question_text' => '<p>Objetiva</p>',
        'canvas_points_possible' => 1,
        'enabled' => true,
    ]);

    Http::fake(function (Request $request) use ($test) {
        if (preg_match('#^https://canvas\.test/api/v1/courses/([^/?]+)$#', $request->url(), $matches) === 1) {
            $courseId = (string) $matches[1];
            $response = $test->courseResponses[$courseId] ?? [
                'id' => (int) $courseId,
                'name' => 'Canvas Course '.$courseId,
                'blueprint' => in_array($courseId, ['3', '300'], true),
            ];
            $status = $test->courseStatuses[$courseId] ?? 200;

            return Http::response($response, $status);
        }

        if (preg_match('#^https://canvas\.test/api/v1/courses/3/quizzes/1/questions(?:\?.*)?$#', $request->url()) === 1) {
            return Http::response([
                [
                    'id' => 4,
                    'question_name' => 'Blueprint Essay',
                    'question_type' => 'essay_question',
                    'question_text' => '<p>Explique o conceito.</p>',
                    'points_possible' => 10,
                    'position' => 1,
                ],
                [
                    'id' => 222,
                    'question_name' => 'Ignored Objective',
                    'question_type' => 'multiple_choice_question',
                    'question_text' => '<p>Escolha uma alternativa.</p>',
                    'points_possible' => 1,
                    'position' => 2,
                ],
            ], 200);
        }

        return Http::response([], 200);
    });

    $response = $this->post(route('lti.ai-grader.quiz.sync-essay-questions', $fixture['quiz']), $context);

    assertAiGraderLtiRedirect($response, aiGraderLtiRedirectQuery($context), 'quiz-'.$fixture['quiz']->id);

    $this->assertDatabaseHas('ai_grader_question_configs', [
        'ai_grader_quiz_config_id' => $fixture['quiz']->id,
        'canvas_question_id' => '4',
        'canvas_question_type' => 'essay_question',
    ]);

    $this->assertDatabaseMissing('ai_grader_question_configs', [
        'ai_grader_quiz_config_id' => $fixture['quiz']->id,
        'canvas_question_id' => '222',
    ]);

    $this->assertDatabaseMissing('ai_grader_question_configs', [
        'ai_grader_quiz_config_id' => $fixture['quiz']->id,
        'canvas_question_id' => '999',
    ]);

    $this->get((string) $response->headers->get('Location'))
        ->assertOk()
        ->assertSee('Blueprint Essay')
        ->assertDontSee('Ignored Objective')
        ->assertDontSee('Old Objective Question');
});

it('saving essay question instructions redirects back to the current question without 404', function (): void {
    $fixture = aiGraderLtiBlueprintFixture();
    $context = array_merge(aiGraderLtiContext('3', 'DesignerEnrollment'), [
        'canvas_environment_id' => $fixture['environment']->id,
        'organization_id' => $fixture['organization']->id,
    ]);

    $response = $this->post(route('lti.ai-grader.question.update', $fixture['question']), array_merge($context, [
        'enabled' => '1',
        'ai_grading_instructions' => 'Nova orientacao da questao discursiva.',
    ]));

    assertAiGraderLtiRedirect($response, aiGraderLtiRedirectQuery($context), 'question-'.$fixture['question']->id);
    $response->assertSessionHas('success', 'Questao discursiva atualizada.');

    $this->assertDatabaseHas('ai_grader_question_configs', [
        'id' => $fixture['question']->id,
        'ai_grading_instructions' => 'Nova orientacao da questao discursiva.',
        'enabled' => true,
    ]);

    $this->get((string) $response->headers->get('Location'))
        ->assertOk()
        ->assertSee('Nova orientacao da questao discursiva.');
});

it('blueprint screen exposes classic quizzes configuration action', function (): void {
    $fixture = aiGraderLtiBlueprintFixture();

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('3')))
        ->assertOk()
        ->assertSee('Configurar Classic Quizzes')
        ->assertSee('Selecione quais Classic quizzes desta blueprint deseja habilitar para correcao pelo GraderAI.')
        ->assertSee('Configure as questoes discursivas que serao corrigidas pelo GraderAI e informe as orientacoes de correcao para cada uma.')
        ->assertSee('Configurar discursivas')
        ->assertDontSee('Sincronizar discursivas')
        ->assertSee('Ver exemplo de orientacoes')
        ->assertSee('Usar este exemplo')
        ->assertSee($fixture['quiz']->canvas_quiz_title);
});

it('does not call blueprint_templates without template id during launch detection', function (): void {
    aiGraderLtiBlueprintFixture();

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('3')))
        ->assertOk()
        ->assertSee('Resumo da blueprint GraderAI');

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), '/blueprint_templates');
    });
});

it('shows diagnostic when canvas course response has no blueprint indicators', function (): void {
    $organization = aiGraderLtiOrganization();
    aiGraderLtiEnvironment($organization);

    $this->courseResponses['77'] = [
        'id' => 77,
        'name' => 'Course 77',
    ];
    $this->courseStatuses['77'] = 200;

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('77')))
        ->assertOk()
        ->assertSee('Nao foi possivel identificar se este curso e uma blueprint.')
        ->assertSee('/api/v1/courses/77')
        ->assertSee('200')
        ->assertSee('campo blueprint')
        ->assertSee('ausente')
        ->assertSee('campo is_blueprint')
        ->assertDontSee('Configurar GraderAI nesta blueprint');
});

it('shows safe diagnostic when canvas course api fails', function (): void {
    $organization = aiGraderLtiOrganization();
    aiGraderLtiEnvironment($organization);

    $this->courseResponses['88'] = [
        'errors' => [['message' => 'Canvas unavailable']],
    ];
    $this->courseStatuses['88'] = 500;

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('88')))
        ->assertOk()
        ->assertSee('Nao foi possivel identificar se este curso e uma blueprint.')
        ->assertSee('/api/v1/courses/88')
        ->assertSee('500')
        ->assertDontSee('secret-token');
});

it('shows friendly message when classic quiz configuration hits invalid token', function (): void {
    $fixture = aiGraderLtiBlueprintFixture();
    $context = array_merge(aiGraderLtiContext('3'), [
        'canvas_environment_id' => $fixture['environment']->id,
        'organization_id' => $fixture['organization']->id,
    ]);

    DB::table('canvas_environments')
        ->where('id', $fixture['environment']->id)
        ->update(['api_token' => 'not-encrypted']);

    $response = $this->from(route('lti.ai-grader.index', aiGraderLtiContext('3')))
        ->post(route('lti.ai-grader.blueprint.sync-quizzes', $fixture['blueprint']), $context);

    assertAiGraderLtiRedirect($response, aiGraderLtiRedirectQuery($context));

    $response->assertSessionHas('error', 'Nao foi possivel acessar o Canvas. Verifique se o token da instancia esta configurado corretamente no GraderAI.');
});

it('blueprint screen does not show objective questions', function (): void {
    $fixture = aiGraderLtiBlueprintFixture();

    AiGraderQuestionConfig::query()->create([
        'ai_grader_quiz_config_id' => $fixture['quiz']->id,
        'canvas_question_id' => '99',
        'canvas_question_name' => 'Objective From Previous Sync',
        'canvas_question_type' => 'multiple_choice_question',
        'canvas_question_text' => '<p>Objetiva</p>',
        'canvas_points_possible' => 1,
        'enabled' => true,
    ]);

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('3')))
        ->assertOk()
        ->assertSee('Blueprint Essay')
        ->assertDontSee('Objective From Previous Sync');
});

it('blocks LTI blueprint settings updates from another course context', function (): void {
    $fixture = aiGraderLtiBlueprintFixture();

    $this->post(route('lti.ai-grader.blueprint.settings', $fixture['blueprint']), array_merge(aiGraderLtiContext('99', 'DesignerEnrollment'), [
        'canvas_environment_id' => $fixture['environment']->id,
        'organization_id' => $fixture['organization']->id,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL,
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_MANUAL,
    ]))
        ->assertForbidden()
        ->assertSee('Esta acao LTI nao pertence a esta blueprint.');
});

it('shows a friendly message when the internal LTI context has expired', function (): void {
    $fixture = aiGraderLtiBlueprintFixture();

    $this->post(route('lti.ai-grader.blueprint.settings', $fixture['blueprint']), [
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL,
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_MANUAL,
    ])
        ->assertForbidden()
        ->assertSee('Sessao LTI expirada. Abra novamente o GraderAI pelo menu do curso no Canvas.');
});

it('blocks LTI question updates from another course context', function (): void {
    $fixture = aiGraderLtiBlueprintFixture();

    $this->post(route('lti.ai-grader.question.update', $fixture['question']), array_merge(aiGraderLtiContext('99'), [
        'enabled' => '1',
        'ai_grading_instructions' => 'Instrucao atualizada a partir de outro contexto.',
    ]))
        ->assertForbidden()
        ->assertSee('deployment_not_allowed');
});

it('shows correction detail for current child course', function (): void {
    $item = aiGraderLtiCorrectionItem();

    $this->get(route('lti.ai-grader.show', array_merge(['correctionItem' => $item], aiGraderLtiContext('11'))))
        ->assertOk()
        ->assertSee('Detalhe da correção #'.$item->id)
        ->assertSee('Resposta discursiva da Maria.')
        ->assertSee('Feedback da IA para Maria.')
        ->assertSee('Feedback publicado no Canvas.')
        ->assertSee('position_type');
});

it('responds to LTI routes on grader domain', function (): void {
    aiGraderLtiCorrectionItem();

    $this->get(route('lti.ai-grader.index', aiGraderLtiContext('11')))
        ->assertOk()
        ->assertSee('Correcoes do GraderAI')
        ->assertSee('Maria Student');

    $this->get('http://graderai.example.test/lti/jwks')
        ->assertServiceUnavailable()
        ->assertJson(['error' => 'lti_signing_key_not_configured']);
});

it('shows informational page for bare LTI URL without context', function (): void {
    $this->get('http://graderai.example.test/lti')
        ->assertOk()
        ->assertSee('Esta URL e da ferramenta GraderAI')
        ->assertSee('acesse pelo menu do curso no Canvas')
        ->assertDontSee('missing_course_context')
        ->assertDontSee('Acesso nao permitido');
});

it('generates LTI config URLs with grader URL', function (): void {
    $this->get('http://graderai.example.test/lti/config')
        ->assertOk()
        ->assertJsonPath('oidc_initiation_url', 'https://graderai.example.test/lti/login')
        ->assertJsonPath('target_link_uri', 'https://graderai.example.test/lti/launch')
        ->assertJsonMissingPath('public_jwk_url')
        ->assertJsonPath('config_url', 'https://graderai.example.test/lti/config')
        ->assertJsonPath('extensions.0.settings.placements.0.target_link_uri', 'https://graderai.example.test/lti/launch')
        ->assertJsonPath('extensions.0.settings.placements.0.enabled', true)
        ->assertJsonPath('custom_fields.canvas_course_id', '$Canvas.course.id')
        ->assertJsonPath('custom_fields.canvas_user_id', '$Canvas.user.id')
        ->assertJsonPath('custom_fields.canvas_user_login_id', '$Canvas.user.loginId')
        ->assertJsonPath('custom_fields.canvas_membership_roles', '$Canvas.membership.roles')
        ->assertJsonPath('extensions.0.settings.custom_fields.canvas_course_id', '$Canvas.course.id')
        ->assertJsonPath('extensions.0.settings.custom_fields.canvas_user_id', '$Canvas.user.id')
        ->assertJsonPath('extensions.0.settings.custom_fields.canvas_user_login_id', '$Canvas.user.loginId')
        ->assertJsonPath('extensions.0.settings.custom_fields.canvas_membership_roles', '$Canvas.membership.roles');
});

it('loads public LTI installation page on grader domain', function (): void {
    $this->assertGuest();

    $this->get('http://graderai.example.test/lti/install')
        ->assertOk()
        ->assertSee('Instalacao LTI do GraderAI')
        ->assertSee('https://graderai.example.test/lti/login')
        ->assertSee('https://graderai.example.test/lti/launch')
        ->assertSee('https://graderai.example.test/lti/jwks')
        ->assertSee('https://graderai.example.test/lti/config')
        ->assertSee('JSON de configuracao da LTI')
        ->assertSee('canvas_course_id')
        ->assertSee('Admin &gt; Developer Keys', false);
});

it('loads admin LTI installation page', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('ai-grader.lti-installation.index'))
        ->assertOk()
        ->assertSee('Instalacao LTI do GraderAI')
        ->assertSee('https://graderai.example.test/lti/login')
        ->assertSee('https://graderai.example.test/lti/launch')
        ->assertSee('https://graderai.example.test/lti/jwks')
        ->assertSee('https://graderai.example.test/lti/config')
        ->assertSee('JSON de configuracao da LTI')
        ->assertSee('"target_link_uri"')
        ->assertSee('Admin &gt; Developer Keys', false)
        ->assertSee(route('ai-grader.lti-installation.index'));
});

it('exposes explicit GraderAI domain configuration', function (): void {
    expect(config('graderai.domain'))->toBe('graderai.example.test')
        ->and(config('graderai.url'))->toBe('https://graderai.example.test');
});

it('keeps administrative routes outside grader domain', function (): void {
    $this->get('/ai-grader/blueprints')
        ->assertRedirect('/login');

    $this->get('http://panel.example.test/ai-grader/blueprints')
        ->assertRedirect('http://panel.example.test/login');

    $this->get('http://graderai.example.test/ai-grader/blueprints')
        ->assertNotFound();
});

it('does not expose authenticated administrative pages on grader domain', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('http://graderai.example.test/ai-grader/ai-providers')
        ->assertNotFound();
});

it('redirects valid Canvas POST login initiation to authorize redirect without CSRF token', function (): void {
    $organization = aiGraderLtiOrganization();
    $environment = aiGraderLtiEnvironment($organization);
    $environment->update([
        'lti_issuer' => 'https://canvas.instructure.com',
        'lti_client_id' => '10000000000005',
        'lti_deployment_id' => 'deployment-123',
        'lti_authorization_endpoint' => 'https://canvas.example.test/api/lti/authorize_redirect',
        'lti_jwks_uri' => 'https://canvas.example.test/api/lti/security/jwks',
    ]);
    $messageHint = 'opaque-message-hint';

    $response = $this->withMiddleware(PreventRequestForgery::class)
        ->post('http://graderai.example.test/lti/login', [
            'iss' => 'https://canvas.instructure.com',
            'login_hint' => 'opaque-login-hint',
            'client_id' => '10000000000005',
            'target_link_uri' => 'https://graderai.example.test/lti/launch',
            'lti_message_hint' => $messageHint,
            'lti_deployment_id' => 'deployment-123',
        ])
        ->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toStartWith('https://canvas.example.test/api/lti/authorize_redirect?');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($query['scope'] ?? null)->toBe('openid')
        ->and($query['response_type'] ?? null)->toBe('id_token')
        ->and($query['response_mode'] ?? null)->toBe('form_post')
        ->and($query['prompt'] ?? null)->toBe('none')
        ->and($query['client_id'] ?? null)->toBe('10000000000005')
        ->and($query['redirect_uri'] ?? null)->toBe('https://graderai.example.test/lti/launch')
        ->and($query['login_hint'] ?? null)->toBe('opaque-login-hint')
        ->and($query['lti_message_hint'] ?? null)->toBe($messageHint)
        ->and($query['state'] ?? null)->not->toBeEmpty()
        ->and($query['nonce'] ?? null)->not->toBeEmpty();

    $cached = cache()->get('ai_grader_lti_state:'.hash('sha256', $query['state']));

    expect($cached)->toBeArray()
        ->and($cached['nonce'] ?? null)->toBe($query['nonce'])
        ->and($cached['iss'] ?? null)->toBe('https://canvas.instructure.com')
        ->and($cached['client_id'] ?? null)->toBe('10000000000005')
        ->and($cached['target_link_uri'] ?? null)->toBe('https://graderai.example.test/lti/launch')
        ->and($cached['deployment_id'] ?? null)->toBe('deployment-123')
        ->and($cached['canvas_environment_id'] ?? null)->toBe($environment->id)
        ->and($cached)->not->toHaveKey('canvas_domain')
        ->and($cached)->not->toHaveKey('authorize_redirect_url');
});

it('does not resolve an authorization endpoint from an unverified message hint', function (): void {
    $this->post('http://graderai.example.test/lti/login', [
        'iss' => 'https://canvas.instructure.com',
        'login_hint' => 'opaque-login-hint',
        'client_id' => '10000000000005',
        'target_link_uri' => 'https://graderai.example.test/lti/launch',
        'lti_message_hint' => 'eyJjYW52YXNfZG9tYWluIjoiYXR0YWNrZXIuZXhhbXBsZSJ9',
        'lti_deployment_id' => 'deployment-123',
    ])
        ->assertUnprocessable()
        ->assertSee('unknown_installation')
        ->assertSee('lti_message_hint recebido')
        ->assertSee('sim')
        ->assertSee('10000000000005')
        ->assertSee('https://graderai.example.test/lti/launch')
        ->assertDontSee('attacker.example');
});

it('shows sanitized diagnostics for GET login initiation', function (): void {
    $this->get('http://graderai.example.test/lti/login?iss=https://canvas.example.test&login_hint=teacher&client_id=client-123&id_token=hidden-token&api_key=secret')
        ->assertUnprocessable()
        ->assertSee('Login initiation recebido')
        ->assertSee('Parametros obrigatorios ausentes')
        ->assertSee('https://canvas.example.test')
        ->assertSee('login_hint recebido')
        ->assertSee('sim')
        ->assertSee('client-123')
        ->assertDontSee('id_token')
        ->assertDontSee('hidden-token')
        ->assertDontSee('api_key')
        ->assertDontSee('secret');
});

it('shows technical diagnostic for launch without id token or Canvas context', function (): void {
    $this->post('http://graderai.example.test/lti/launch', [
        'foo' => 'bar',
        'api_key' => 'secret-api-key',
        'Authorization' => 'Bearer secret-authorization',
    ])
        ->assertBadRequest()
        ->assertSee('Launch LTI inválido ou expirado.')
        ->assertSee('missing_state')
        ->assertSee('foo')
        ->assertDontSee('missing_course_context')
        ->assertDontSee('api_key')
        ->assertDontSee('secret-api-key')
        ->assertDontSee('Authorization')
        ->assertDontSee('secret-authorization');
});

it('keeps CSRF exceptions scoped to LTI paths', function (): void {
    $excludedPaths = app(PreventRequestForgery::class)->getExcludedPaths();
    $routes = app('router')->getRoutes();

    expect($excludedPaths)->toBe([])
        ->and($routes->getByName('lti.ai-grader.login')->excludedMiddleware())->toContain(PreventRequestForgery::class)
        ->and($routes->getByName('lti.ai-grader.launch')->excludedMiddleware())->toContain(PreventRequestForgery::class)
        ->and($routes->getByName('lti.ai-grader.force-refresh')->excludedMiddleware())->not->toContain(PreventRequestForgery::class);
});

it('forbidden diagnostics do not expose sensitive launch fields', function (): void {
    aiGraderLtiCorrectionItem();
    aiGraderLtiContext('11', 'StudentEnrollment');

    $this->withHeaders([
        'Authorization' => 'Bearer secret-authorization',
    ])->get('http://graderai.example.test/lti?api_key=secret-api-key')
        ->assertForbidden()
        ->assertSee('StudentEnrollment')
        ->assertSee('role_not_allowed')
        ->assertSee('graderai.example.test')
        ->assertSee('GET')
        ->assertDontSee('Authorization')
        ->assertDontSee('secret-api-key')
        ->assertDontSee('api_key')
        ->assertDontSee('secret-authorization');
});

it('launch without course id shows sanitized received keys', function (): void {
    $this->post('http://graderai.example.test/lti/launch', [
        'roles' => 'Instructor',
        'canvas_user_id' => '2001',
        'canvas_user_login_id' => 'teacher.no.course',
    ])
        ->assertBadRequest()
        ->assertSee('missing_state')
        ->assertSee('canvas_user_id')
        ->assertSee('canvas_user_login_id')
        ->assertDontSee('id_token')
        ->assertDontSee('hidden-token');
});
