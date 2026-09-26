<?php

declare(strict_types=1);

use App\Jobs\AiGrader\AiGraderRunPipelineJob;
use App\Models\AiGraderAiProvider;
use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderClientSetting;
use App\Models\AiGraderQuestionConfig;
use App\Models\AiGraderQuizConfig;
use App\Models\CanvasEnvironment;
use App\Models\Organization;
use App\Services\AiGrader\AiGraderCanvasPublisher;
use App\Services\AiGrader\AiGraderPendingProcessor;
use App\Services\AiGrader\AiGraderSubmissionCollector;
use Illuminate\Support\Facades\Bus;

function aiGraderSchedulerOrganization(bool $withEnabledSetting = true): Organization
{
    $organization = Organization::query()->create([
        'name' => 'Scheduler Org',
        'slug' => 'scheduler-org-' . uniqid(),
        'timezone' => 'America/Sao_Paulo',
        'is_active' => true,
    ]);

    if ($withEnabledSetting) {
        AiGraderClientSetting::query()->create([
            'organization_id' => $organization->id,
            'enabled' => true,
            'status' => AiGraderClientSetting::STATUS_TRIAL,
            'trial_quota_total' => 50,
            'purchased_quota_total' => 0,
            'quota_used' => 0,
            'quota_reserved' => 0,
        ]);

        AiGraderAiProvider::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Scheduler Mock',
            'provider_type' => AiGraderAiProvider::TYPE_MOCK,
            'enabled' => true,
            'is_default' => true,
        ]);
    }

    return $organization;
}

function aiGraderSchedulerEnvironment(Organization $organization): CanvasEnvironment
{
    config(['app.key' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);

    return CanvasEnvironment::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Scheduler Canvas',
        'slug' => 'scheduler-canvas-' . uniqid(),
        'environment_type' => 'test',
        'base_url' => 'https://canvas.test',
        'api_token' => 'secret-token',
        'is_active' => true,
        'is_default' => true,
    ]);
}

function aiGraderSchedulerBlueprint(array $overrides = [], bool $withEnabledSetting = true): AiGraderBlueprintConfig
{
    $organization = aiGraderSchedulerOrganization($withEnabledSetting);
    $environment = aiGraderSchedulerEnvironment($organization);

    return AiGraderBlueprintConfig::query()->create(array_merge([
        'organization_id' => $organization->id,
        'canvas_environment_id' => $environment->id,
        'blueprint_course_id' => '3',
        'blueprint_course_name' => 'Scheduler Blueprint',
        'enabled' => true,
        'publication_mode' => AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH,
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_AFTER_SUBMISSION,
        'default_feedback_language' => 'pt_BR',
    ], $overrides));
}

function aiGraderSchedulerQuiz(AiGraderBlueprintConfig $blueprintConfig, array $settings = [], array $overrides = []): AiGraderQuizConfig
{
    $quizConfig = AiGraderQuizConfig::query()->create(array_merge([
        'ai_grader_blueprint_config_id' => $blueprintConfig->id,
        'canvas_quiz_id' => '1',
        'canvas_assignment_id' => '1',
        'canvas_quiz_title' => 'Scheduler Quiz',
        'canvas_quiz_type' => 'assignment',
        'points_possible' => 10,
        'question_count' => 1,
        'enabled' => true,
        'correction_enabled' => true,
        'settings' => $settings,
    ], $overrides));

    AiGraderQuestionConfig::query()->create([
        'ai_grader_quiz_config_id' => $quizConfig->id,
        'canvas_question_id' => '4',
        'canvas_question_name' => 'Essay',
        'canvas_question_type' => AiGraderQuestionConfig::TYPE_ESSAY,
        'canvas_question_text' => '<p>Explique.</p>',
        'canvas_points_possible' => 10,
        'enabled' => true,
        'ai_grading_instructions' => 'Rubrica privada suficientemente detalhada para avaliação automática.',
        'instructions_version' => 1,
    ]);

    return $quizConfig;
}

function aiGraderSchedulerChildCourses(): array
{
    return [
        [
            'child_course_id' => 11,
            'child_quiz_id' => 4,
            'child_assignment_id' => 12,
        ],
    ];
}

it('does not dispatch disabled blueprint', function (): void {
    $blueprint = aiGraderSchedulerBlueprint(['enabled' => false]);
    aiGraderSchedulerQuiz($blueprint, ['child_courses' => aiGraderSchedulerChildCourses()]);
    Bus::fake();

    $this->artisan('ai-grader:dispatch-scheduled-pipelines')
        ->expectsOutputToContain('GraderAI scheduled pipeline dispatch summary')
        ->assertExitCode(0);

    Bus::assertNotDispatched(AiGraderRunPipelineJob::class);
});

it('does not dispatch organization without active GraderAI setting', function (): void {
    $blueprint = aiGraderSchedulerBlueprint([], false);
    aiGraderSchedulerQuiz($blueprint, ['child_courses' => aiGraderSchedulerChildCourses()]);
    Bus::fake();

    $this->artisan('ai-grader:dispatch-scheduled-pipelines --blueprint-config-id=' . $blueprint->id)
        ->expectsOutputToContain('not_eligible')
        ->assertExitCode(0);

    Bus::assertNotDispatched(AiGraderRunPipelineJob::class);
});

it('does not dispatch manual trigger mode', function (): void {
    $blueprint = aiGraderSchedulerBlueprint(['trigger_mode' => AiGraderBlueprintConfig::TRIGGER_MANUAL]);
    aiGraderSchedulerQuiz($blueprint, ['child_courses' => aiGraderSchedulerChildCourses()]);
    Bus::fake();

    $this->artisan('ai-grader:dispatch-scheduled-pipelines --blueprint-config-id=' . $blueprint->id)
        ->expectsOutputToContain('Skipped manual')
        ->expectsOutputToContain('manual')
        ->assertExitCode(0);

    Bus::assertNotDispatched(AiGraderRunPipelineJob::class);
});

it('dispatches after submission when child courses exist in settings', function (): void {
    $blueprint = aiGraderSchedulerBlueprint();
    aiGraderSchedulerQuiz($blueprint, ['child_courses' => aiGraderSchedulerChildCourses()]);
    Bus::fake();

    $this->artisan('ai-grader:dispatch-scheduled-pipelines --blueprint-config-id=' . $blueprint->id)
        ->expectsOutputToContain('Pipelines despachados')
        ->assertExitCode(0);

    Bus::assertDispatched(AiGraderRunPipelineJob::class, fn (AiGraderRunPipelineJob $job): bool => $job->blueprintConfigId === $blueprint->id
        && $job->childCourseId === '11'
        && $job->childQuizId === '4'
        && $job->childAssignmentId === '12'
        && $job->providerId === null
        && $job->metadata['trigger_mode'] === AiGraderBlueprintConfig::TRIGGER_AFTER_SUBMISSION
        && $job->metadata['dispatched_by'] === 'scheduler');
});

it('reports an explicit error instead of dispatching without a provider', function (): void {
    $blueprint = aiGraderSchedulerBlueprint();
    AiGraderAiProvider::query()->where('organization_id', $blueprint->organization_id)->delete();
    aiGraderSchedulerQuiz($blueprint, ['child_courses' => aiGraderSchedulerChildCourses()]);
    Bus::fake();

    $this->artisan('ai-grader:dispatch-scheduled-pipelines --blueprint-config-id=' . $blueprint->id)
        ->expectsOutputToContain('No enabled AI provider is configured for this organization')
        ->assertExitCode(1);

    Bus::assertNotDispatched(AiGraderRunPipelineJob::class);
});

it('skips after submission without child course mapping', function (): void {
    $blueprint = aiGraderSchedulerBlueprint();
    aiGraderSchedulerQuiz($blueprint);
    Bus::fake();

    $this->artisan('ai-grader:dispatch-scheduled-pipelines --blueprint-config-id=' . $blueprint->id)
        ->expectsOutputToContain('skipped_missing_child_course_mapping')
        ->assertExitCode(0);

    Bus::assertNotDispatched(AiGraderRunPipelineJob::class);
});

it('does not dispatch after date before scheduled at', function (): void {
    $blueprint = aiGraderSchedulerBlueprint([
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_AFTER_DATE,
        'scheduled_at' => now()->addHour(),
    ]);
    aiGraderSchedulerQuiz($blueprint, ['child_courses' => aiGraderSchedulerChildCourses()]);
    Bus::fake();

    $this->artisan('ai-grader:dispatch-scheduled-pipelines --blueprint-config-id=' . $blueprint->id)
        ->expectsOutputToContain('scheduled_at_not_reached')
        ->assertExitCode(0);

    Bus::assertNotDispatched(AiGraderRunPipelineJob::class);
});

it('skips after date without scheduled at', function (): void {
    $blueprint = aiGraderSchedulerBlueprint([
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_AFTER_DATE,
        'scheduled_at' => null,
    ]);
    aiGraderSchedulerQuiz($blueprint, ['child_courses' => aiGraderSchedulerChildCourses()]);
    Bus::fake();

    $this->artisan('ai-grader:dispatch-scheduled-pipelines --blueprint-config-id=' . $blueprint->id)
        ->expectsOutputToContain('skipped_missing_scheduled_at')
        ->assertExitCode(0);

    Bus::assertNotDispatched(AiGraderRunPipelineJob::class);
});

it('dispatches after date when scheduled at was reached and marks settings', function (): void {
    $blueprint = aiGraderSchedulerBlueprint([
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_AFTER_DATE,
        'scheduled_at' => now()->subMinute(),
    ]);
    $quizConfig = aiGraderSchedulerQuiz($blueprint, ['child_courses' => aiGraderSchedulerChildCourses()]);
    Bus::fake();

    $this->artisan('ai-grader:dispatch-scheduled-pipelines --blueprint-config-id=' . $blueprint->id)
        ->assertExitCode(0);

    Bus::assertDispatched(AiGraderRunPipelineJob::class);

    expect($quizConfig->fresh()->settings['last_scheduled_pipeline_dispatched_at'] ?? null)->not->toBeNull();
});

it('does not dispatch after date again when already dispatched after scheduled at', function (): void {
    $blueprint = aiGraderSchedulerBlueprint([
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_AFTER_DATE,
        'scheduled_at' => now()->subHour(),
    ]);
    aiGraderSchedulerQuiz($blueprint, [
        'child_courses' => aiGraderSchedulerChildCourses(),
        'last_scheduled_pipeline_dispatched_at' => now()->toIso8601String(),
    ]);
    Bus::fake();

    $this->artisan('ai-grader:dispatch-scheduled-pipelines --blueprint-config-id=' . $blueprint->id)
        ->expectsOutputToContain('after_date_already_dispatched')
        ->assertExitCode(0);

    Bus::assertNotDispatched(AiGraderRunPipelineJob::class);
});

it('executes pipeline synchronously when sync option is used', function (): void {
    $blueprint = aiGraderSchedulerBlueprint();
    aiGraderSchedulerQuiz($blueprint, ['child_courses' => aiGraderSchedulerChildCourses()]);

    $collector = Mockery::mock(AiGraderSubmissionCollector::class);
    $processor = Mockery::mock(AiGraderPendingProcessor::class);
    $publisher = Mockery::mock(AiGraderCanvasPublisher::class);

    $collector->shouldReceive('collect')->once()->andReturn(['pending_created' => 1]);
    $processor->shouldReceive('process')->once()->andReturn(['ai_corrected' => 1]);
    $publisher->shouldReceive('publish')->once()->andReturn(['published' => 1]);

    $this->app->instance(AiGraderSubmissionCollector::class, $collector);
    $this->app->instance(AiGraderPendingProcessor::class, $processor);
    $this->app->instance(AiGraderCanvasPublisher::class, $publisher);

    $this->artisan('ai-grader:dispatch-scheduled-pipelines --blueprint-config-id=' . $blueprint->id . ' --sync')
        ->expectsOutputToContain('Pipelines executados sync')
        ->assertExitCode(0);
});

it('dispatches job to queue without sync option', function (): void {
    $blueprint = aiGraderSchedulerBlueprint();
    aiGraderSchedulerQuiz($blueprint, ['child_courses' => aiGraderSchedulerChildCourses()]);
    Bus::fake();

    $this->artisan('ai-grader:dispatch-scheduled-pipelines --blueprint-config-id=' . $blueprint->id)
        ->expectsOutputToContain('Blueprints avaliadas')
        ->expectsOutputToContain('Pipelines despachados')
        ->assertExitCode(0);

    Bus::assertDispatched(AiGraderRunPipelineJob::class);
});
