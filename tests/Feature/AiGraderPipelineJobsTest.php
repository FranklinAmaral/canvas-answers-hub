<?php

declare(strict_types=1);

use App\Jobs\AiGrader\AiGraderCollectSubmissionsJob;
use App\Jobs\AiGrader\AiGraderProcessPendingCorrectionsJob;
use App\Jobs\AiGrader\AiGraderPublishCorrectedItemsJob;
use App\Jobs\AiGrader\AiGraderRunPipelineJob;
use App\Models\AiGraderBlueprintConfig;
use App\Models\CanvasEnvironment;
use App\Models\Organization;
use App\Services\AiGrader\AiGraderCanvasPublisher;
use App\Services\AiGrader\AiGraderPendingProcessor;
use App\Services\AiGrader\AiGraderSubmissionCollector;
use Illuminate\Support\Facades\Bus;

function aiGraderPipelineOrganization(): Organization
{
    return Organization::query()->create([
        'name' => 'Pipeline Org',
        'slug' => 'pipeline-org-' . uniqid(),
        'timezone' => 'America/Sao_Paulo',
        'is_active' => true,
    ]);
}

function aiGraderPipelineEnvironment(Organization $organization): CanvasEnvironment
{
    config(['app.key' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);

    return CanvasEnvironment::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Pipeline Canvas',
        'slug' => 'pipeline-canvas-' . uniqid(),
        'environment_type' => 'test',
        'base_url' => 'https://canvas.test',
        'api_token' => 'secret-token',
        'is_active' => true,
        'is_default' => true,
    ]);
}

function aiGraderPipelineBlueprint(string $publicationMode = AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH): AiGraderBlueprintConfig
{
    $organization = aiGraderPipelineOrganization();
    $environment = aiGraderPipelineEnvironment($organization);

    return AiGraderBlueprintConfig::query()->create([
        'organization_id' => $organization->id,
        'canvas_environment_id' => $environment->id,
        'blueprint_course_id' => '3',
        'blueprint_course_name' => 'Pipeline Blueprint',
        'enabled' => true,
        'publication_mode' => $publicationMode,
        'trigger_mode' => AiGraderBlueprintConfig::TRIGGER_MANUAL,
        'default_feedback_language' => 'pt_BR',
    ]);
}

it('collect submissions job calls collector with filters', function (): void {
    $collector = Mockery::mock(AiGraderSubmissionCollector::class);
    $collector->shouldReceive('collect')
        ->once()
        ->with([
            'organization_id' => 1,
            'environment_id' => 2,
            'blueprint_config_id' => 3,
            'quiz_config_id' => 4,
            'child_course_id' => '11',
            'child_quiz_id' => '4',
            'child_assignment_id' => '12',
            'limit' => 5,
        ])
        ->andReturn(['collected' => 1]);

    $job = new AiGraderCollectSubmissionsJob(
        organizationId: 1,
        environmentId: 2,
        blueprintConfigId: 3,
        quizConfigId: 4,
        childCourseId: '11',
        childQuizId: '4',
        childAssignmentId: '12',
        limit: 5,
    );

    expect($job->handle($collector))->toBe(['collected' => 1]);
});

it('process pending corrections job calls processor with filters', function (): void {
    $processor = Mockery::mock(AiGraderPendingProcessor::class);
    $processor->shouldReceive('process')
        ->once()
        ->with([
            'organization_id' => 1,
            'environment_id' => 2,
            'blueprint_config_id' => 3,
            'quiz_config_id' => 4,
            'provider_id' => 9,
            'limit' => 5,
        ])
        ->andReturn(['processed' => 1]);

    $job = new AiGraderProcessPendingCorrectionsJob(
        organizationId: 1,
        environmentId: 2,
        blueprintConfigId: 3,
        quizConfigId: 4,
        providerId: 9,
        limit: 5,
    );

    expect($job->handle($processor))->toBe(['processed' => 1]);
});

it('publish corrected items job calls publisher with filters', function (): void {
    $publisher = Mockery::mock(AiGraderCanvasPublisher::class);
    $publisher->shouldReceive('publish')
        ->once()
        ->with([
            'organization_id' => 1,
            'environment_id' => 2,
            'blueprint_config_id' => 3,
            'quiz_config_id' => 4,
            'limit' => 5,
        ])
        ->andReturn(['published' => 1]);

    $job = new AiGraderPublishCorrectedItemsJob(
        organizationId: 1,
        environmentId: 2,
        blueprintConfigId: 3,
        quizConfigId: 4,
        limit: 5,
    );

    expect($job->handle($publisher))->toBe(['published' => 1]);
});

it('run pipeline collects processes and publishes when auto publish', function (): void {
    $blueprint = aiGraderPipelineBlueprint(AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH);

    $collector = Mockery::mock(AiGraderSubmissionCollector::class);
    $processor = Mockery::mock(AiGraderPendingProcessor::class);
    $publisher = Mockery::mock(AiGraderCanvasPublisher::class);

    $collector->shouldReceive('collect')
        ->once()
        ->with(Mockery::on(fn (array $filters): bool => $filters['blueprint_config_id'] === $blueprint->id
            && $filters['organization_id'] === $blueprint->organization_id
            && $filters['environment_id'] === $blueprint->canvas_environment_id
            && $filters['child_course_id'] === '11'
            && $filters['child_quiz_id'] === '4'
            && $filters['child_assignment_id'] === '12'
            && $filters['limit'] === 10
            && ! array_key_exists('dry_run', $filters)))
        ->andReturn(['pending_created' => 2]);

    $processor->shouldReceive('process')
        ->once()
        ->with(Mockery::on(fn (array $filters): bool => $filters['blueprint_config_id'] === $blueprint->id
            && $filters['provider_id'] === 7
            && $filters['limit'] === 10
            && ! array_key_exists('dry_run', $filters)))
        ->andReturn(['ai_corrected' => 2]);

    $publisher->shouldReceive('publish')
        ->once()
        ->with(Mockery::on(fn (array $filters): bool => $filters['blueprint_config_id'] === $blueprint->id
            && $filters['limit'] === 10
            && ! array_key_exists('dry_run', $filters)))
        ->andReturn(['published' => 2]);

    $summary = (new AiGraderRunPipelineJob(
        blueprintConfigId: $blueprint->id,
        childCourseId: '11',
        childQuizId: '4',
        childAssignmentId: '12',
        providerId: 7,
        limit: 10,
    ))->handle($collector, $processor, $publisher);

    expect($summary['published'])->toBeTrue()
        ->and($summary['publish'])->toBe(['published' => 2]);
});

it('run pipeline collects and processes without publishing when teacher approval', function (): void {
    $blueprint = aiGraderPipelineBlueprint(AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL);

    $collector = Mockery::mock(AiGraderSubmissionCollector::class);
    $processor = Mockery::mock(AiGraderPendingProcessor::class);
    $publisher = Mockery::mock(AiGraderCanvasPublisher::class);

    $collector->shouldReceive('collect')->once()->andReturn(['pending_created' => 1]);
    $processor->shouldReceive('process')
        ->once()
        ->with(Mockery::on(fn (array $filters): bool => $filters['blueprint_config_id'] === $blueprint->id
            && ! array_key_exists('provider_id', $filters)))
        ->andReturn(['pending_teacher_approval' => 1]);
    $publisher->shouldNotReceive('publish');

    $summary = (new AiGraderRunPipelineJob(blueprintConfigId: $blueprint->id))->handle($collector, $processor, $publisher);

    expect($summary['published'])->toBeFalse()
        ->and($summary['publish'])->toBeNull();
});

it('run pipeline publishes for auto publish with review flags', function (): void {
    $blueprint = aiGraderPipelineBlueprint(AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH_WITH_REVIEW_FLAGS);

    $collector = Mockery::mock(AiGraderSubmissionCollector::class);
    $processor = Mockery::mock(AiGraderPendingProcessor::class);
    $publisher = Mockery::mock(AiGraderCanvasPublisher::class);

    $collector->shouldReceive('collect')->once()->andReturn(['pending_created' => 2]);
    $processor->shouldReceive('process')->once()->andReturn(['ai_corrected' => 1, 'pending_teacher_approval' => 1]);
    $publisher->shouldReceive('publish')->once()->andReturn(['published' => 1]);

    $summary = (new AiGraderRunPipelineJob(blueprintConfigId: $blueprint->id))->handle($collector, $processor, $publisher);

    expect($summary['published'])->toBeTrue()
        ->and($summary['publish'])->toBe(['published' => 1]);
});

it('run pipeline command dispatches job', function (): void {
    $blueprint = aiGraderPipelineBlueprint();
    Bus::fake();

    $this->artisan('ai-grader:run-pipeline --blueprint-config-id=' . $blueprint->id)
        ->expectsOutput('GraderAI pipeline job dispatched.')
        ->assertExitCode(0);

    Bus::assertDispatched(AiGraderRunPipelineJob::class, fn (AiGraderRunPipelineJob $job): bool => $job->blueprintConfigId === $blueprint->id);
});

it('run pipeline command executes synchronously', function (): void {
    $blueprint = aiGraderPipelineBlueprint(AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL);

    $collector = Mockery::mock(AiGraderSubmissionCollector::class);
    $processor = Mockery::mock(AiGraderPendingProcessor::class);
    $publisher = Mockery::mock(AiGraderCanvasPublisher::class);

    $collector->shouldReceive('collect')->once()->andReturn(['pending_created' => 1, 'essay_answers_found' => 1]);
    $processor->shouldReceive('process')->once()->andReturn(['ai_corrected' => 0, 'pending_teacher_approval' => 1, 'failed' => 0]);
    $publisher->shouldNotReceive('publish');

    $this->app->instance(AiGraderSubmissionCollector::class, $collector);
    $this->app->instance(AiGraderPendingProcessor::class, $processor);
    $this->app->instance(AiGraderCanvasPublisher::class, $publisher);

    $this->artisan('ai-grader:run-pipeline --blueprint-config-id=' . $blueprint->id . ' --sync')
        ->expectsOutput('GraderAI pipeline summary')
        ->assertExitCode(0);
});
