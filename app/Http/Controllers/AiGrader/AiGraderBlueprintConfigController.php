<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiGrader;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiGrader\UpdateAiGraderQuestionConfigRequest;
use App\Http\Requests\AiGrader\UpdateAiGraderQuizConfigRequest;
use App\Http\Requests\AiGrader\UpsertAiGraderBlueprintConfigRequest;
use App\Models\AiGraderAiProvider;
use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderQuestionConfig;
use App\Models\AiGraderQuizConfig;
use App\Models\CanvasEnvironment;
use App\Models\Organization;
use App\Services\AiGrader\AiGraderCanvasSyncService;
use App\Services\AiGrader\AiGraderConfigurationStatusService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class AiGraderBlueprintConfigController extends Controller
{
    public function __construct(
        private readonly AiGraderCanvasSyncService $syncService,
        private readonly AiGraderConfigurationStatusService $statusService,
    ) {
    }

    public function index(Request $request): View
    {
        $configs = AiGraderBlueprintConfig::query()
            ->with(['organization', 'canvasEnvironment', 'quizConfigs.questionConfigs'])
            ->withCount('quizConfigs')
            ->when($request->filled('organization_id'), fn ($query) => $query->where('organization_id', $request->integer('organization_id')))
            ->when($request->filled('canvas_environment_id'), fn ($query) => $query->where('canvas_environment_id', $request->integer('canvas_environment_id')))
            ->when($request->filled('enabled'), fn ($query) => $query->where('enabled', $request->boolean('enabled')))
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        return view('ai-grader.blueprints.index', [
            'configs' => $configs,
            'organizations' => Organization::query()->orderBy('name')->get(),
            'environments' => CanvasEnvironment::query()->with('organization')->orderBy('name')->get(),
            'statuses' => $configs->getCollection()->mapWithKeys(fn (AiGraderBlueprintConfig $config): array => [
                $config->id => $this->statusService->forBlueprint($config),
            ]),
        ]);
    }

    public function create(): View
    {
        return view('ai-grader.blueprints.create', $this->formData(new AiGraderBlueprintConfig()));
    }

    public function store(UpsertAiGraderBlueprintConfigRequest $request): RedirectResponse
    {
        $data = $this->blueprintData($request);
        $data['created_by'] = $request->user()?->id;
        $data['updated_by'] = $request->user()?->id;

        $blueprintConfig = AiGraderBlueprintConfig::query()->create($data);

        return redirect()
            ->route('ai-grader.blueprints.show', $blueprintConfig)
            ->with('success', 'Configuração de blueprint criada com sucesso.');
    }

    public function show(AiGraderBlueprintConfig $blueprintConfig): View
    {
        $blueprintConfig->load(['organization', 'canvasEnvironment', 'aiProvider', 'quizConfigs.questionConfigs']);

        return view('ai-grader.blueprints.show', [
            'blueprintConfig' => $blueprintConfig,
            'status' => $this->statusService->forBlueprint($blueprintConfig),
        ]);
    }

    public function edit(AiGraderBlueprintConfig $blueprintConfig): View
    {
        $blueprintConfig->load(['organization', 'canvasEnvironment']);

        return view('ai-grader.blueprints.edit', $this->formData($blueprintConfig));
    }

    public function update(UpsertAiGraderBlueprintConfigRequest $request, AiGraderBlueprintConfig $blueprintConfig): RedirectResponse
    {
        $data = $this->blueprintData($request);
        $data['updated_by'] = $request->user()?->id;

        $blueprintConfig->update($data);

        return redirect()
            ->route('ai-grader.blueprints.show', $blueprintConfig)
            ->with('success', 'Configuração de blueprint atualizada com sucesso.');
    }

    public function syncQuizzes(AiGraderBlueprintConfig $blueprintConfig): RedirectResponse
    {
        try {
            $result = $this->syncService->syncQuizzes($blueprintConfig);
        } catch (Throwable $exception) {
            return back()->with('error', 'Falha ao sincronizar quizzes do Canvas: ' . $exception->getMessage());
        }

        return back()->with(
            'success',
            "{$result['created']} quizzes sincronizados. {$result['updated']} quizzes atualizados. {$result['ignored']} ignorados."
        );
    }

    public function showQuiz(AiGraderBlueprintConfig $blueprintConfig, AiGraderQuizConfig $quizConfig): View
    {
        $this->assertQuizBelongsToBlueprint($blueprintConfig, $quizConfig);
        $quizConfig->load(['blueprintConfig.organization', 'questionConfigs']);

        return view('ai-grader.blueprints.quizzes.show', [
            'blueprintConfig' => $blueprintConfig,
            'quizConfig' => $quizConfig,
            'status' => $this->statusService->forQuiz($quizConfig),
        ]);
    }

    public function updateQuiz(
        UpdateAiGraderQuizConfigRequest $request,
        AiGraderBlueprintConfig $blueprintConfig,
        AiGraderQuizConfig $quizConfig,
    ): RedirectResponse {
        $this->assertQuizBelongsToBlueprint($blueprintConfig, $quizConfig);

        $quizConfig->update([
            'enabled' => $request->boolean('enabled'),
            'correction_enabled' => $request->boolean('correction_enabled'),
        ]);

        return redirect()
            ->route('ai-grader.blueprints.quizzes.show', [$blueprintConfig, $quizConfig])
            ->with('success', 'Configuração do quiz atualizada com sucesso.');
    }

    public function syncQuestions(AiGraderBlueprintConfig $blueprintConfig, AiGraderQuizConfig $quizConfig): RedirectResponse
    {
        $this->assertQuizBelongsToBlueprint($blueprintConfig, $quizConfig);

        try {
            $result = $this->syncService->syncQuestions($quizConfig);
        } catch (Throwable $exception) {
            return back()->with('error', 'Falha ao sincronizar questões do Canvas: ' . $exception->getMessage());
        }

        return back()->with(
            'success',
            "{$result['synced']} questões sincronizadas. {$result['essay']} discursivas encontradas. {$result['objective_ignored']} objetivas ignoradas para correção."
        );
    }

    public function editQuestion(
        AiGraderBlueprintConfig $blueprintConfig,
        AiGraderQuizConfig $quizConfig,
        AiGraderQuestionConfig $questionConfig,
    ): View {
        $this->assertQuestionBelongsToQuiz($blueprintConfig, $quizConfig, $questionConfig);

        return view('ai-grader.blueprints.questions.edit', [
            'blueprintConfig' => $blueprintConfig,
            'quizConfig' => $quizConfig,
            'questionConfig' => $questionConfig,
        ]);
    }

    public function updateQuestion(
        UpdateAiGraderQuestionConfigRequest $request,
        AiGraderBlueprintConfig $blueprintConfig,
        AiGraderQuizConfig $quizConfig,
        AiGraderQuestionConfig $questionConfig,
    ): RedirectResponse {
        $this->assertQuestionBelongsToQuiz($blueprintConfig, $quizConfig, $questionConfig);

        $instructions = trim((string) ($request->validated()['ai_grading_instructions'] ?? ''));
        $previousInstructions = trim((string) $questionConfig->ai_grading_instructions);
        $instructionsChanged = $instructions !== $previousInstructions;

        $data = [
            'enabled' => $request->boolean('enabled') && $questionConfig->canvas_question_type === AiGraderQuestionConfig::TYPE_ESSAY,
            'ai_grading_instructions' => $instructions !== '' ? $instructions : null,
        ];

        if ($instructionsChanged) {
            $data['instructions_version'] = max(1, (int) $questionConfig->instructions_version) + 1;
            $data['instructions_updated_at'] = now();
        }

        $questionConfig->update($data);

        return redirect()
            ->route('ai-grader.blueprints.quizzes.show', [$blueprintConfig, $quizConfig])
            ->with('success', 'Questão discursiva atualizada com sucesso.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(AiGraderBlueprintConfig $blueprintConfig): array
    {
        return [
            'blueprintConfig' => $blueprintConfig,
            'organizations' => Organization::query()->orderBy('name')->get(),
            'environments' => CanvasEnvironment::query()->with('organization')->orderBy('name')->get(),
            'providers' => AiGraderAiProvider::query()
                ->where('enabled', true)
                ->whereIn('provider_type', AiGraderAiProvider::supportedTypes())
                ->orderBy('name')
                ->get(),
            'publicationModes' => collect(AiGraderBlueprintConfig::publicationModes())
                ->mapWithKeys(fn (string $mode): array => [$mode => __('ai_grader.publication_modes.' . $mode)])
                ->all(),
            'triggerModes' => [
                AiGraderBlueprintConfig::TRIGGER_AFTER_SUBMISSION => 'after_submission',
                AiGraderBlueprintConfig::TRIGGER_AFTER_DATE => 'after_date',
                AiGraderBlueprintConfig::TRIGGER_MANUAL => 'manual',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blueprintData(UpsertAiGraderBlueprintConfigRequest $request): array
    {
        $validated = $request->validated();

        return [
            'organization_id' => (int) $validated['organization_id'],
            'canvas_environment_id' => (int) $validated['canvas_environment_id'],
            'blueprint_course_id' => trim((string) $validated['blueprint_course_id']),
            'blueprint_course_name' => $validated['blueprint_course_name'] ?? null,
            'enabled' => $request->boolean('enabled'),
            'publication_mode' => AiGraderBlueprintConfig::normalizePublicationMode($validated['publication_mode']),
            'trigger_mode' => $validated['trigger_mode'],
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'teacher_can_adjust_score' => $request->boolean('teacher_can_adjust_score'),
            'teacher_can_adjust_feedback' => $request->boolean('teacher_can_adjust_feedback'),
            'teacher_can_publish' => $request->boolean('teacher_can_publish'),
            'teacher_can_republish' => $request->boolean('teacher_can_republish'),
            'teacher_can_export' => $request->boolean('teacher_can_export'),
            'teacher_can_view_grading_prompt' => $request->boolean('teacher_can_view_grading_prompt'),
            'default_feedback_language' => $validated['default_feedback_language'],
            'ai_provider_id' => $validated['ai_provider_id'] ?? null,
        ];
    }

    private function assertQuizBelongsToBlueprint(AiGraderBlueprintConfig $blueprintConfig, AiGraderQuizConfig $quizConfig): void
    {
        abort_if($quizConfig->ai_grader_blueprint_config_id !== $blueprintConfig->id, 404);
    }

    private function assertQuestionBelongsToQuiz(
        AiGraderBlueprintConfig $blueprintConfig,
        AiGraderQuizConfig $quizConfig,
        AiGraderQuestionConfig $questionConfig,
    ): void {
        $this->assertQuizBelongsToBlueprint($blueprintConfig, $quizConfig);
        abort_if($questionConfig->ai_grader_quiz_config_id !== $quizConfig->id, 404);
    }
}
