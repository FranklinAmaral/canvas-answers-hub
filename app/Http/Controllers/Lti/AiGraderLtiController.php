<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lti;

use App\Http\Controllers\Controller;
use App\Jobs\AiGrader\AiGraderRunPipelineJob;
use App\Models\AiGraderAiProvider;
use App\Models\AiGraderBlueprintConfig;
use App\Models\AiGraderClientSetting;
use App\Models\AiGraderCorrectionItem;
use App\Models\AiGraderQuestionConfig;
use App\Models\AiGraderQuizConfig;
use App\Models\CanvasEnvironment;
use App\Models\Organization;
use App\Services\AiGrader\AiGraderCanvasSyncService;
use App\Services\AiGrader\AiGraderChildCourseResolver;
use App\Services\AiGrader\AiGraderCorrectionRetryService;
use App\Services\AiGrader\AiGraderLtiConfigService;
use App\Services\AiGrader\AiGraderLtiSecurityException;
use App\Services\AiGrader\AiGraderLtiSecurityService;
use App\Services\AiGrader\AiGraderSanitizer;
use App\Services\AiGrader\AiGraderTeacherReviewService;
use App\Services\Canvas\CanvasApiClient;
use App\Services\Canvas\CanvasApiException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AiGraderLtiController extends Controller
{
    private const LOCALE_COOKIE = 'graderai_locale';

    /**
     * @var array<int, string>
     */
    private const ALLOWED_LOCALES = ['pt_BR', 'en'];

    public function __construct(
        private readonly AiGraderSanitizer $sanitizer,
        private readonly AiGraderLtiConfigService $ltiConfigService,
        private readonly AiGraderLtiSecurityService $ltiSecurityService,
        private readonly AiGraderCanvasSyncService $syncService,
        private readonly AiGraderChildCourseResolver $childCourseResolver,
        private readonly AiGraderCorrectionRetryService $retryService,
        private readonly AiGraderTeacherReviewService $teacherReviewService,
    ) {}

    public function login(Request $request): View|RedirectResponse|Response
    {
        $diagnostics = $this->loginDiagnosticsFor($request);

        $validator = Validator::make($request->all(), [
            'iss' => ['required', 'string', 'max:255'],
            'login_hint' => ['required', 'string', 'max:4000'],
            'client_id' => ['required', 'string', 'max:255'],
            'target_link_uri' => ['required', 'url:https', 'max:2000'],
            'lti_message_hint' => ['required', 'string', 'max:8000'],
            'lti_deployment_id' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            Log::warning('GraderAI LTI login initiation rejected.', [
                'reason' => 'missing_or_invalid_parameters',
                'received_keys' => $this->diagnosticsFor($request)['received_keys'],
            ]);

            return response()->view('lti.ai-grader.login-diagnostic', [
                'message' => 'Login initiation recebido sem parametros obrigatorios validos.',
                'diagnostics' => $diagnostics,
                'missing' => array_keys($validator->errors()->toArray()),
                'urls' => $this->ltiConfigService->urls(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            /** @var array<string, string> $validated */
            $validated = $validator->validated();
            $result = $this->ltiSecurityService->initiate($validated);
        } catch (AiGraderLtiSecurityException $exception) {
            Log::warning('GraderAI LTI login initiation rejected.', [
                'reason' => $exception->reason,
                'issuer' => $this->stringOrNull($request->input('iss')),
                'client_id' => $this->stringOrNull($request->input('client_id')),
                'deployment_id' => $this->stringOrNull($request->input('lti_deployment_id')),
            ]);

            return response()->view('lti.ai-grader.login-diagnostic', [
                'message' => 'A instalação LTI não pôde ser validada.',
                'diagnostics' => array_merge($diagnostics, ['reason' => $exception->reason]),
                'missing' => [],
                'urls' => $this->ltiConfigService->urls(),
            ], $exception->status);
        }

        Log::info('GraderAI LTI OIDC redirect prepared.', [
            'canvas_environment_id' => $result['environment']->id,
            'organization_id' => $result['environment']->organization_id,
            'issuer' => $result['environment']->lti_issuer,
            'client_id' => $result['environment']->lti_client_id,
            'deployment_id' => $result['environment']->lti_deployment_id,
        ]);

        return redirect()->away($result['authorization_url']);
    }

    public function launch(Request $request): View|Response
    {
        try {
            $context = $this->ltiSecurityService->validateLaunch(
                (string) $request->input('state', ''),
                (string) $request->input('id_token', ''),
            );
        } catch (AiGraderLtiSecurityException $exception) {
            $request->session()->forget('ai_grader_lti_context');

            Log::warning('GraderAI LTI launch rejected.', [
                'reason' => $exception->reason,
                'host' => $request->getHost(),
                'method' => $request->method(),
                'received_keys' => $this->diagnosticsFor($request)['received_keys'],
            ]);

            return response()->view('lti.ai-grader.launch-diagnostic', [
                'message' => 'Launch LTI inválido ou expirado.',
                'diagnostics' => array_merge($this->diagnosticsFor($request), ['reason' => $exception->reason]),
                'urls' => $this->ltiConfigService->urls(),
            ], $exception->status);
        }

        $request->session()->regenerate(true);
        $request->session()->put('ai_grader_lti_context', $context);
        $request->attributes->set('ai_grader_lti_context', $context);

        Log::info('GraderAI LTI launch validated.', [
            'canvas_environment_id' => $context['canvas_environment_id'],
            'organization_id' => $context['organization_id'],
            'course_id' => $context['course_id'],
            'canvas_user_id' => $context['canvas_user_id'],
            'roles' => $context['roles'],
            'deployment_id' => $context['deployment_id'],
        ]);

        return $this->index($request);
    }

    public function jwks(): JsonResponse
    {
        $jwks = $this->ltiConfigService->publicJwks();

        if ($jwks === null) {
            return response()->json([
                'error' => 'lti_signing_key_not_configured',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json($jwks);
    }

    public function config(): JsonResponse
    {
        return response()->json($this->ltiConfigService->payload());
    }

    public function install(): View
    {
        return view('lti.ai-grader.install', [
            'urls' => $this->ltiConfigService->urls(),
            'configJson' => $this->ltiConfigService->prettyJson(),
        ]);
    }

    public function setLocale(Request $request): RedirectResponse
    {
        $validated = Validator::make($request->all(), [
            'locale' => ['required', 'in:'.implode(',', self::ALLOWED_LOCALES)],
            'redirect_to' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $context = $this->context($request);
        $redirectUrl = $this->safeLocaleRedirect($request, $validated['redirect_to'] ?? null, $context);

        return redirect()
            ->to($redirectUrl)
            ->withCookie(cookie()->make(
                self::LOCALE_COOKIE,
                $validated['locale'],
                60 * 24 * 365,
                '/',
                null,
                $request->isSecure(),
                false,
                false,
                'lax'
            ));
    }

    public function index(Request $request): View|Response
    {
        if ($this->isInformationalRequest($request)) {
            return view('lti.ai-grader.info', [
                'urls' => $this->ltiConfigService->urls(),
            ]);
        }

        $context = $this->context($request);
        $canvasEnvironment = $this->resolveCanvasEnvironment($request, $context);
        $canvasCourse = $this->resolveCanvasCourse($canvasEnvironment, $context['course_id']);
        $context = $this->mergeContextEnvironment($context, $canvasEnvironment);
        $this->rememberContext($context);

        Log::info('GraderAI LTI launch received.', [
            'course_id' => $context['course_id'],
            'canvas_user_id' => $context['canvas_user_id'],
            'login' => $context['login'],
            'roles' => $context['roles'],
            'deployment_id' => $context['deployment_id'],
            'context_title' => $context['context_title'],
            'host' => $request->getHost(),
        ]);

        $access = $this->baseAccessDecision($context);

        if (! $access['allowed']) {
            return $this->forbidden($context, $access['reason'], $request);
        }

        if ($canvasCourse['resolved'] !== true) {
            $this->logLaunchMode($request, $context, 'course_detection_failed', $canvasCourse['reason']);

            return $this->courseDiagnosticView(
                $request,
                $context,
                $canvasEnvironment,
                $canvasCourse['message'],
                $canvasCourse['details'],
                $canvasCourse['diagnostics']
            );
        }

        if ($canvasCourse['is_blueprint'] === true) {
            $access = $this->modeAccessDecision($context, 'blueprint');

            if (! $access['allowed']) {
                return $this->forbidden($context, $access['reason'], $request);
            }

            $blueprintConfig = $this->blueprintForCourse($context['course_id']);

            if (! $blueprintConfig instanceof AiGraderBlueprintConfig) {
                $this->logLaunchMode($request, $context, 'blueprint_enable');

                return $this->enableBlueprintView($context, $canvasEnvironment, $canvasCourse['course']);
            }

            $this->logLaunchMode($request, $context, 'blueprint');

            return $this->blueprintView($request, $blueprintConfig, $context, $canvasCourse['course']);
        }

        $access = $this->modeAccessDecision($context, 'course');

        if (! $access['allowed']) {
            return $this->forbidden($context, $access['reason'], $request);
        }

        $this->logLaunchMode($request, $context, 'course');

        return $this->courseView($request, $context, $canvasCourse['course']);
    }

    public function enableBlueprint(Request $request): RedirectResponse|Response
    {
        $context = $this->context($request);
        $this->rememberContext($context);
        $access = $this->baseAccessDecision($context);

        if (! $access['allowed']) {
            return $this->forbidden($context, $access['reason'], $request);
        }

        $access = $this->modeAccessDecision($context, 'blueprint', 'configure');

        if (! $access['allowed']) {
            return $this->forbidden($context, $access['reason'], $request);
        }

        abort_if($context['course_id'] === null, 403);

        $organizationId = (int) ($context['organization_id'] ?? 0);
        $environmentId = (int) ($context['canvas_environment_id'] ?? 0);
        abort_if($organizationId <= 0 || $environmentId <= 0, 403);

        $validator = Validator::make($request->all(), [
            'ai_provider_id' => ['nullable', 'integer', 'exists:ai_grader_ai_providers,id'],
            'publication_mode' => ['required', 'in:'.implode(',', AiGraderBlueprintConfig::publicationModes())],
            'trigger_mode' => ['required', 'in:after_submission,after_date,manual'],
            'scheduled_at' => ['nullable', 'date', 'required_if:trigger_mode,after_date'],
            'canvas_course_name' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $environment = CanvasEnvironment::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('lti_enabled', true)
            ->findOrFail($environmentId);

        $providerId = $validator['ai_provider_id'] ?? null;
        if ($providerId !== null && $providerId !== '') {
            $provider = AiGraderAiProvider::query()
                ->where('enabled', true)
                ->whereIn('provider_type', AiGraderAiProvider::supportedTypes())
                ->findOrFail((int) $providerId);
            abort_if((int) $provider->organization_id !== $organizationId, 422);
        }

        $blueprintConfig = AiGraderBlueprintConfig::query()->firstOrCreate(
            ['blueprint_course_id' => (string) $context['course_id']],
            [
                'organization_id' => $organizationId,
                'canvas_environment_id' => $environment->id,
                'blueprint_course_name' => $this->stringOrNull($validator['canvas_course_name'] ?? null)
                    ?? $context['context_title']
                    ?? null,
                'enabled' => true,
                'publication_mode' => AiGraderBlueprintConfig::normalizePublicationMode($validator['publication_mode']),
                'trigger_mode' => $validator['trigger_mode'],
                'scheduled_at' => $validator['trigger_mode'] === AiGraderBlueprintConfig::TRIGGER_AFTER_DATE
                    ? ($validator['scheduled_at'] ?? null)
                    : null,
                'teacher_can_adjust_score' => false,
                'teacher_can_adjust_feedback' => false,
                'teacher_can_publish' => false,
                'teacher_can_republish' => false,
                'teacher_can_export' => false,
                'default_feedback_language' => 'pt_BR',
                'ai_provider_id' => $providerId !== null && $providerId !== '' ? (int) $providerId : null,
            ],
        );

        if (! $blueprintConfig->wasRecentlyCreated) {
            $blueprintConfig->update([
                'organization_id' => $organizationId,
                'canvas_environment_id' => $environment->id,
                'blueprint_course_name' => $this->stringOrNull($validator['canvas_course_name'] ?? null)
                    ?? $blueprintConfig->blueprint_course_name
                    ?? $context['context_title']
                    ?? null,
                'enabled' => true,
                'publication_mode' => AiGraderBlueprintConfig::normalizePublicationMode($validator['publication_mode']),
                'trigger_mode' => $validator['trigger_mode'],
                'scheduled_at' => $validator['trigger_mode'] === AiGraderBlueprintConfig::TRIGGER_AFTER_DATE
                    ? ($validator['scheduled_at'] ?? null)
                    : null,
                'ai_provider_id' => $providerId !== null && $providerId !== '' ? (int) $providerId : null,
            ]);
        }

        Log::info('GraderAI LTI blueprint enabled.', [
            'course_id' => $context['course_id'],
            'canvas_user_id' => $context['canvas_user_id'],
            'login' => $context['login'],
            'blueprint_config_id' => $blueprintConfig->id,
        ]);

        return $this->redirectToLtiIndex(
            $context,
            'success',
            __('ai-grader.lti.blueprint_enabled'),
            [
                'canvas_environment_id' => $blueprintConfig->canvas_environment_id,
                'organization_id' => $blueprintConfig->organization_id,
            ]
        );
    }

    public function updateBlueprintSettings(Request $request, AiGraderBlueprintConfig $blueprintConfig): RedirectResponse|Response
    {
        $context = $this->authorizedBlueprintContext($request, $blueprintConfig);
        if ($context instanceof Response) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'ai_provider_id' => ['nullable', 'integer', 'exists:ai_grader_ai_providers,id'],
            'publication_mode' => ['required', 'in:'.implode(',', AiGraderBlueprintConfig::publicationModes())],
            'trigger_mode' => ['required', 'in:after_submission,after_date,manual'],
            'scheduled_at' => ['nullable', 'date', 'required_if:trigger_mode,after_date'],
            'teacher_can_view_grading_prompt' => ['nullable', 'boolean'],
        ])->validate();

        $providerId = $validator['ai_provider_id'] ?? null;
        if ($providerId !== null && $providerId !== '') {
            $provider = AiGraderAiProvider::query()
                ->where('enabled', true)
                ->whereIn('provider_type', AiGraderAiProvider::supportedTypes())
                ->findOrFail((int) $providerId);
            abort_if((int) $provider->organization_id !== (int) $blueprintConfig->organization_id, 422);
        }

        $blueprintConfig->update([
            'ai_provider_id' => $providerId !== null && $providerId !== '' ? (int) $providerId : null,
            'publication_mode' => AiGraderBlueprintConfig::normalizePublicationMode($validator['publication_mode']),
            'trigger_mode' => $validator['trigger_mode'],
            'scheduled_at' => $validator['trigger_mode'] === AiGraderBlueprintConfig::TRIGGER_AFTER_DATE
                ? ($validator['scheduled_at'] ?? null)
                : null,
            'teacher_can_view_grading_prompt' => $request->boolean('teacher_can_view_grading_prompt'),
        ]);

        Log::info('GraderAI LTI blueprint settings updated.', [
            'course_id' => $context['course_id'],
            'canvas_user_id' => $context['canvas_user_id'],
            'blueprint_config_id' => $blueprintConfig->id,
        ]);

        return $this->redirectToLtiIndex(
            $context,
            'success',
            __('ai-grader.lti.blueprint_settings_updated'),
            [
                'canvas_environment_id' => $blueprintConfig->canvas_environment_id,
                'organization_id' => $blueprintConfig->organization_id,
            ]
        );
    }

    public function toggleBlueprintEnabled(Request $request, AiGraderBlueprintConfig $blueprintConfig): RedirectResponse|Response
    {
        $context = $this->authorizedBlueprintContext($request, $blueprintConfig);
        if ($context instanceof Response) {
            return $context;
        }

        $enabled = $request->boolean('enabled');

        $blueprintConfig->update([
            'enabled' => $enabled,
        ]);

        return $this->redirectToLtiIndex(
            $context,
            'success',
            $enabled ? __('ai-grader.lti.blueprint_activated') : __('ai-grader.lti.blueprint_deactivated'),
            [
                'canvas_environment_id' => $blueprintConfig->canvas_environment_id,
                'organization_id' => $blueprintConfig->organization_id,
            ]
        );
    }

    public function syncBlueprintQuizzes(Request $request, AiGraderBlueprintConfig $blueprintConfig): RedirectResponse|Response
    {
        $context = $this->authorizedBlueprintContext($request, $blueprintConfig);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $result = $this->syncService->syncQuizzes($blueprintConfig);
        } catch (Throwable $exception) {
            $this->logCanvasFailure('sync_blueprint_quizzes', $exception, [
                'blueprint_config_id' => $blueprintConfig->id,
                'course_id' => $context['course_id'],
            ]);

            return $this->redirectToLtiIndex(
                $context,
                'error',
                $this->friendlyCanvasAccessMessage($exception, __('ai-grader.lti.sync_quizzes_failed')),
                [
                    'canvas_environment_id' => $blueprintConfig->canvas_environment_id,
                    'organization_id' => $blueprintConfig->organization_id,
                ]
            );
        }

        return $this->redirectToLtiIndex(
            $context,
            'success',
            __('ai-grader.lti.sync_quizzes_result', [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'ignored' => $result['ignored'],
            ]),
            [
                'canvas_environment_id' => $blueprintConfig->canvas_environment_id,
                'organization_id' => $blueprintConfig->organization_id,
            ]
        );
    }

    public function toggleQuiz(Request $request, AiGraderQuizConfig $quizConfig): RedirectResponse|Response
    {
        $context = $this->authorizedQuizContext($request, $quizConfig);
        if ($context instanceof Response) {
            return $context;
        }

        $quizConfig->update([
            'enabled' => $request->boolean('enabled'),
            'correction_enabled' => $request->boolean('correction_enabled'),
        ]);

        return $this->redirectToLtiIndex(
            $context,
            'success',
            __('ai-grader.lti.quiz_updated'),
            [
                'canvas_environment_id' => $quizConfig->blueprintConfig->canvas_environment_id,
                'organization_id' => $quizConfig->blueprintConfig->organization_id,
            ],
            'quiz-'.$quizConfig->id
        );
    }

    public function syncEssayQuestions(Request $request, AiGraderQuizConfig $quizConfig): RedirectResponse|Response
    {
        $context = $this->authorizedQuizContext($request, $quizConfig);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $result = $this->syncService->syncQuestions($quizConfig);
        } catch (Throwable $exception) {
            $this->logCanvasFailure('sync_essay_questions', $exception, [
                'quiz_config_id' => $quizConfig->id,
                'course_id' => $context['course_id'],
            ]);

            return $this->redirectToLtiIndex(
                $context,
                'error',
                $this->friendlyCanvasAccessMessage($exception, __('ai-grader.lti.sync_questions_failed')),
                [
                    'canvas_environment_id' => $quizConfig->blueprintConfig->canvas_environment_id,
                    'organization_id' => $quizConfig->blueprintConfig->organization_id,
                ],
                'quiz-'.$quizConfig->id
            );
        }

        return $this->redirectToLtiIndex(
            $context,
            'success',
            "{$result['essay']} questões discursivas sincronizadas. {$result['objective_ignored']} objetivas ignoradas.",
            [
                'canvas_environment_id' => $quizConfig->blueprintConfig->canvas_environment_id,
                'organization_id' => $quizConfig->blueprintConfig->organization_id,
            ],
            'quiz-'.$quizConfig->id
        );
    }

    public function updateQuestion(Request $request, AiGraderQuestionConfig $questionConfig): RedirectResponse|Response
    {
        $questionConfig->loadMissing('quizConfig.blueprintConfig');
        $context = $this->authorizedQuizContext($request, $questionConfig->quizConfig);
        if ($context instanceof Response) {
            return $context;
        }

        abort_if($questionConfig->canvas_question_type !== AiGraderQuestionConfig::TYPE_ESSAY, 404);

        $validated = Validator::make($request->all(), [
            'enabled' => ['nullable', 'boolean'],
            'ai_grading_instructions' => ['nullable', 'string', 'max:10000'],
            'correction_mode' => ['nullable', 'string', Rule::in(AiGraderQuestionConfig::correctionModes())],
            'canvas_rubric_id' => ['nullable', 'string', 'max:100'],
        ])->validate();

        $instructions = trim((string) ($validated['ai_grading_instructions'] ?? ''));
        $previousInstructions = trim((string) $questionConfig->ai_grading_instructions);
        $correctionMode = in_array($validated['correction_mode'] ?? null, AiGraderQuestionConfig::correctionModes(), true)
            ? (string) $validated['correction_mode']
            : AiGraderQuestionConfig::CORRECTION_MODE_SIMPLE;
        $rubricId = $this->stringOrNull($validated['canvas_rubric_id'] ?? null);
        $rubricSnapshot = null;
        $rubricTitle = null;

        if ($correctionMode === AiGraderQuestionConfig::CORRECTION_MODE_CANVAS_RUBRIC) {
            if ($rubricId === null) {
                throw ValidationException::withMessages([
                    'canvas_rubric_id' => __('ai-grader.lti.question_rubric_required'),
                ]);
            }

            try {
                $rubricSnapshot = $this->syncService->rubricSnapshotForCourse($questionConfig->quizConfig->blueprintConfig, $rubricId);
            } catch (Throwable $exception) {
                throw ValidationException::withMessages([
                    'canvas_rubric_id' => $this->friendlyCanvasAccessMessage($exception, __('ai-grader.lti.blueprint.question_rubric_load_failed')),
                ]);
            }

            if ($rubricSnapshot === null) {
                throw ValidationException::withMessages([
                    'canvas_rubric_id' => __('ai-grader.lti.question_rubric_not_found'),
                ]);
            }

            $rubricTitle = $this->stringOrNull($rubricSnapshot['title'] ?? null) ?? $rubricId;
        } else {
            $rubricId = null;
        }

        $configurationChanged = $instructions !== $previousInstructions
            || $correctionMode !== $questionConfig->normalizedCorrectionMode()
            || $rubricId !== $this->stringOrNull($questionConfig->canvas_rubric_id)
            || $this->jsonEncodeComparable($rubricSnapshot) !== $this->jsonEncodeComparable($questionConfig->rubric_snapshot);

        $data = [
            'enabled' => $request->boolean('enabled'),
            'ai_grading_instructions' => $instructions !== '' ? $instructions : null,
            'correction_mode' => $correctionMode,
            'canvas_rubric_id' => $rubricId,
            'rubric_title' => $rubricTitle,
            'rubric_snapshot' => $rubricSnapshot,
            'rubric_synced_at' => $rubricSnapshot !== null ? now() : null,
        ];

        if ($configurationChanged) {
            $data['instructions_version'] = max(1, (int) $questionConfig->instructions_version) + 1;
            $data['instructions_updated_at'] = now();
        }

        $questionConfig->update($data);

        return $this->redirectToLtiIndex(
            $context,
            'success',
            __('ai-grader.lti.question_updated'),
            [
                'canvas_environment_id' => $questionConfig->quizConfig->blueprintConfig->canvas_environment_id,
                'organization_id' => $questionConfig->quizConfig->blueprintConfig->organization_id,
            ],
            'question-'.$questionConfig->id
        );
    }

    /**
     * @return array{method: string, host: string, iss: string|null, has_login_hint: bool, target_link_uri: string|null, has_lti_message_hint: bool, client_id: string|null, lti_deployment_id: string|null, query_params: array<string, mixed>, post_params: array<string, mixed>}
     */
    private function loginDiagnosticsFor(Request $request): array
    {
        return [
            'method' => $request->method(),
            'host' => $request->getHost(),
            'iss' => $this->stringOrNull($request->input('iss')),
            'has_login_hint' => $request->filled('login_hint'),
            'target_link_uri' => $this->stringOrNull($request->input('target_link_uri')),
            'has_lti_message_hint' => $request->filled('lti_message_hint'),
            'client_id' => $this->stringOrNull($request->input('client_id')),
            'lti_deployment_id' => $this->stringOrNull($request->input('lti_deployment_id')),
            'query_params' => $this->sanitizedParameters($request->query()),
            'post_params' => $this->sanitizedParameters($request->request->all()),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveCanvasEnvironment(Request $request, array $context): ?CanvasEnvironment
    {
        return $this->ltiSecurityService->environmentForContext($context);
    }

    /**
     * @return array{resolved: bool, course: array<string, mixed>, is_blueprint: bool|null, message: string, details: string|null, reason: string, diagnostics: array<string, mixed>}
     */
    private function resolveCanvasCourse(?CanvasEnvironment $canvasEnvironment, ?string $courseId): array
    {
        $endpoint = $courseId !== null && $courseId !== ''
            ? "/api/v1/courses/{$courseId}"
            : null;

        if ($courseId === null || $courseId === '') {
            return [
                'resolved' => false,
                'course' => [],
                'is_blueprint' => null,
                'message' => 'Nao foi possivel identificar se este curso e uma blueprint.',
                'details' => null,
                'reason' => 'missing_course_context',
                'diagnostics' => $this->canvasCourseDiagnostics($endpoint),
            ];
        }

        if (! $canvasEnvironment instanceof CanvasEnvironment) {
            return [
                'resolved' => false,
                'course' => [],
                'is_blueprint' => null,
                'message' => 'Nao foi possivel identificar se este curso e uma blueprint.',
                'details' => 'Nao foi possivel identificar o Canvas Environment deste launch LTI.',
                'reason' => 'canvas_environment_not_resolved',
                'diagnostics' => $this->canvasCourseDiagnostics($endpoint),
            ];
        }

        try {
            $course = (new CanvasApiClient($canvasEnvironment))->getCourse($courseId);
        } catch (Throwable $exception) {
            $this->logCanvasFailure('resolve_canvas_course', $exception, [
                'course_id' => $courseId,
                'canvas_environment_id' => $canvasEnvironment->id,
            ]);

            return [
                'resolved' => false,
                'course' => [],
                'is_blueprint' => null,
                'message' => 'Nao foi possivel identificar se este curso e uma blueprint.',
                'details' => $this->isCanvasAccessFailure($exception)
                    ? 'Nao foi possivel acessar o Canvas. Verifique se o token da instancia esta configurado corretamente no GraderAI.'
                    : 'O GraderAI nao conseguiu consultar os dados deste curso no Canvas neste momento.',
                'reason' => 'canvas_course_lookup_failed',
                'diagnostics' => $this->canvasCourseDiagnostics(
                    $endpoint,
                    $this->httpStatusFromException($exception)
                ),
            ];
        }

        $hasBlueprint = array_key_exists('blueprint', $course);
        $hasLegacyBlueprint = array_key_exists('is_blueprint', $course);

        if (! $hasBlueprint && ! $hasLegacyBlueprint) {
            return [
                'resolved' => false,
                'course' => $course,
                'is_blueprint' => null,
                'message' => 'Nao foi possivel identificar se este curso e uma blueprint.',
                'details' => null,
                'reason' => 'missing_blueprint_flag',
                'diagnostics' => $this->canvasCourseDiagnostics($endpoint, 200, $course),
            ];
        }

        return [
            'resolved' => true,
            'course' => $course,
            'is_blueprint' => $hasBlueprint
                ? (bool) $course['blueprint']
                : (bool) $course['is_blueprint'],
            'message' => '',
            'details' => null,
            'reason' => 'resolved',
            'diagnostics' => $this->canvasCourseDiagnostics($endpoint, 200, $course),
        ];
    }

    private function isInformationalRequest(Request $request): bool
    {
        return $request->isMethod('GET') && $this->context($request)['lti_authenticated'] !== true;
    }

    /**
     * @param  array<string|int, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitizedParameters(array $values): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;

            if ($this->isSensitiveKey($key)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizedParameters($value);

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    public function show(Request $request, AiGraderCorrectionItem $correctionItem): View|Response
    {
        $context = $this->authorizedCorrectionItemContext($request, $correctionItem, 'view');

        if ($context instanceof Response) {
            return $context;
        }

        $correctionItem->load([
            'blueprintConfig.aiProvider',
            'canvasEnvironment',
            'quizConfig',
            'questionConfig',
            'publicationLogs',
            'teacherActions',
        ]);

        Log::info('GraderAI LTI correction item viewed.', [
            'course_id' => $context['course_id'],
            'canvas_user_id' => $context['canvas_user_id'],
            'correction_item_id' => $correctionItem->id,
        ]);

        return view('lti.ai-grader.show', array_merge(
            $this->correctionItemStatusViewData($context, $correctionItem),
            [
                'metadataJson' => $this->prettyJson($this->sanitizer->sanitizeArray($correctionItem->metadata ?? [])),
                'canReviewActions' => $this->modeAccessDecision($context, (string) $context['launch_mode'], 'review')['allowed'],
                'canPublishAction' => $this->modeAccessDecision($context, (string) $context['launch_mode'], 'publish')['allowed'],
            ]
        ));
    }

    public function showStatus(Request $request, AiGraderCorrectionItem $correctionItem): View|Response
    {
        $context = $this->authorizedCorrectionItemContext($request, $correctionItem, 'view');

        if ($context instanceof Response) {
            return $context;
        }

        $correctionItem->load([
            'canvasEnvironment',
            'quizConfig',
            'questionConfig',
        ]);

        return view('lti.ai-grader._show-status', $this->correctionItemStatusViewData($context, $correctionItem));
    }

    public function saveReviewDraft(Request $request, AiGraderCorrectionItem $correctionItem): RedirectResponse|Response
    {
        $context = $this->authorizedCorrectionItemContext($request, $correctionItem, 'review');

        if ($context instanceof Response) {
            return $context;
        }

        if (! in_array($correctionItem->status, AiGraderCorrectionItem::reviewableStatuses(), true)) {
            return $this->redirectToCorrectionItem(
                $context,
                $correctionItem,
                'error',
                __('ai-grader.lti.review_forbidden')
            );
        }

        $validated = $this->reviewValidator($request, $correctionItem);
        $this->teacherReviewService->saveDraft($correctionItem, $validated, $this->reviewerContext($request, $context));

        return $this->redirectToCorrectionItem(
            $context,
            $correctionItem->fresh(),
            'success',
            __('ai-grader.lti.review_saved')
        );
    }

    public function publishReview(Request $request, AiGraderCorrectionItem $correctionItem): RedirectResponse|Response
    {
        $context = $this->authorizedCorrectionItemContext($request, $correctionItem, 'publish');

        if ($context instanceof Response) {
            return $context;
        }

        if (! in_array($correctionItem->status, AiGraderCorrectionItem::teacherPublishableStatuses(), true)) {
            return $this->redirectToCorrectionItem(
                $context,
                $correctionItem,
                'error',
                __('ai-grader.lti.review_forbidden')
            );
        }

        $validated = $this->reviewValidator($request, $correctionItem);
        $result = $this->teacherReviewService->publish($correctionItem, $validated, $this->reviewerContext($request, $context));

        return $this->redirectToCorrectionItem(
            $context,
            $correctionItem->fresh(),
            ($result['result'] ?? null) === 'published' ? 'success' : 'error',
            ($result['result'] ?? null) === 'published'
                ? __('ai-grader.lti.review_published')
                : __('ai-grader.lti.review_publish_failed').' '.($result['message'] ?? '')
        );
    }

    public function retryCorrectionItem(Request $request, AiGraderCorrectionItem $correctionItem): RedirectResponse|Response|View
    {
        $context = $this->authorizedCorrectionItemContext($request, $correctionItem, 'retry');

        if ($context instanceof Response) {
            return $context;
        }

        $result = $this->retryService->retryAndDispatch(
            $correctionItem,
            $this->retryMetadata($context, [
                'trigger' => 'lti_retry_single_item',
                'correction_item_id' => $correctionItem->id,
            ])
        );

        if (! $result['queued']) {
            return $this->retryResponse(
                $request,
                $context,
                __('ai-grader.lti.retry_not_available'),
                'error',
                $correctionItem
            );
        }

        return $this->retryResponse(
            $request,
            $context,
            __('ai-grader.lti.retry_item_queued', [
                'type' => __('ai-grader.lti.retry_labels.'.$this->retryService->retryLabel((string) $result['retry_type'])),
            ]),
            'success',
            $correctionItem
        );
    }

    public function retryFailed(Request $request): RedirectResponse|Response|View
    {
        $context = $this->context($request);
        $this->rememberContext($context);
        $originMode = $this->retryOriginMode($request, $context);

        if ($originMode === 'blueprint') {
            $courseId = (string) ($context['course_id'] ?? '');
            $blueprintConfig = $courseId !== '' ? $this->blueprintForCourse($courseId) : null;

            if (! $blueprintConfig instanceof AiGraderBlueprintConfig) {
                return $this->redirectToLtiIndex(
                    $context,
                    'error',
                    __('ai-grader.lti.retry_bulk_empty')
                );
            }

            $context = $this->authorizedBlueprintContext($request, $blueprintConfig, 'retry');

            if ($context instanceof Response) {
                return $context;
            }

            $summary = $this->retryService->retryManyAndDispatch(
                $this->retryableBlueprintItemsQuery($blueprintConfig, $request)->get(),
                $this->retryMetadata($context, [
                    'trigger' => 'lti_retry_failed_blueprint',
                    'blueprint_config_id' => $blueprintConfig->id,
                ])
            );

            return $this->retryFailedResponse($request, $context, $summary, 'blueprint', $blueprintConfig);
        }

        $access = $this->modeAccessDecision($context, 'course', 'retry');

        if (! $access['allowed']) {
            return $this->forbidden($context, $access['reason'], $request);
        }

        if ($context['course_id'] === null) {
            return response()
                ->view('lti.ai-grader.course-diagnostic', [
                    'context' => $context,
                    'canvasEnvironment' => null,
                    'message' => __('ai-grader.lti.course_not_identified'),
                    'details' => null,
                    'diagnostics' => $this->diagnosticsFor($request),
                    'courseDiagnostics' => [],
                ], Response::HTTP_OK);
        }

        $summary = $this->retryService->retryManyAndDispatch(
            $this->retryableCourseItemsQuery($context, $request)->get(),
            $this->retryMetadata($context, [
                'trigger' => 'lti_retry_failed_course',
                'child_course_id' => $context['course_id'],
            ])
        );

        return $this->retryFailedResponse($request, $context, $summary, 'course');
    }

    public function courseStatus(Request $request): View|Response
    {
        $context = $this->context($request);
        $this->rememberContext($context);

        $access = $this->modeAccessDecision($context, 'course');

        if (! $access['allowed']) {
            return $this->forbidden($context, $access['reason'], $request);
        }

        if ($context['course_id'] === null) {
            return response()
                ->view('lti.ai-grader.course-diagnostic', [
                    'context' => $context,
                    'canvasEnvironment' => null,
                    'message' => __('ai-grader.lti.course_not_identified'),
                    'details' => null,
                    'diagnostics' => $this->diagnosticsFor($request),
                    'courseDiagnostics' => [],
                ], Response::HTTP_OK);
        }

        return $this->courseStatusView($request, $context);
    }

    public function exportCourse(Request $request): StreamedResponse|Response
    {
        $context = $this->context($request);
        $this->rememberContext($context);

        $access = $this->modeAccessDecision($context, 'course');

        if (! $access['allowed']) {
            return $this->forbidden($context, $access['reason'], $request);
        }

        if ($context['course_id'] === null) {
            return response()
                ->view('lti.ai-grader.course-diagnostic', [
                    'context' => $context,
                    'canvasEnvironment' => null,
                    'message' => __('ai-grader.lti.course_not_identified'),
                    'details' => null,
                    'diagnostics' => $this->diagnosticsFor($request),
                    'courseDiagnostics' => [],
                ], Response::HTTP_OK);
        }

        $filters = $this->courseListFilters($request);
        $query = AiGraderCorrectionItem::query()
            ->with(['quizConfig'])
            ->where('child_course_id', (string) $context['course_id'])
            ->latest();

        return $this->streamCorrectionItemsCsv(
            $this->applyCourseListFilters($query, $filters),
            'graderai-child-course'
        );
    }

    public function forceRefresh(Request $request): View|Response
    {
        $context = $this->context($request);
        $this->rememberContext($context);

        $access = $this->modeAccessDecision($context, 'course', 'retry');

        if (! $access['allowed']) {
            return $this->forbidden($context, $access['reason'], $request);
        }

        if ($context['course_id'] === null) {
            return response()
                ->view('lti.ai-grader.course-diagnostic', [
                    'context' => $context,
                    'canvasEnvironment' => null,
                    'message' => __('ai-grader.lti.course_not_identified'),
                    'details' => null,
                    'diagnostics' => $this->diagnosticsFor($request),
                    'courseDiagnostics' => [],
                ], Response::HTTP_OK);
        }

        return $this->courseStatusView($request, $context, true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function blueprintView(Request $request, AiGraderBlueprintConfig $blueprintConfig, array $context, array $canvasCourse = []): View
    {
        $blueprintConfig->load([
            'organization.aiGraderClientSetting',
            'canvasEnvironment',
            'aiProvider',
            'quizConfigs.questionConfigs',
        ]);

        $itemsQuery = AiGraderCorrectionItem::query()
            ->where('ai_grader_blueprint_config_id', $blueprintConfig->id);

        return view('lti.ai-grader.blueprint', [
            'context' => $context,
            'blueprintConfig' => $blueprintConfig,
            'summary' => $this->blueprintSummary($blueprintConfig, $itemsQuery),
            'canvasCourseName' => $this->canvasCourseName($canvasCourse, $context, $blueprintConfig->blueprint_course_name),
            ...$this->blueprintFormOptions($blueprintConfig->organization_id),
            ...$this->blueprintRubricData($blueprintConfig),
            ...$this->blueprintGridData($request, $blueprintConfig, $context),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function enableBlueprintView(array $context, ?CanvasEnvironment $canvasEnvironment = null, array $canvasCourse = []): View
    {
        return view('lti.ai-grader.enable-blueprint', [
            'context' => $context,
            'resolvedEnvironment' => $canvasEnvironment,
            'canvasCourseName' => $this->canvasCourseName($canvasCourse, $context),
            ...$this->blueprintFormOptions(),
        ]);
    }

    /**
     * @return array{organizations: Collection<int, Organization>, environments: Collection<int, CanvasEnvironment>, providers: Collection<int, AiGraderAiProvider>, publicationModes: array<string, string>, triggerModes: array<string, string>}
     */
    private function blueprintFormOptions(?int $organizationId = null): array
    {
        $organizations = Organization::query()
            ->orderBy('name')
            ->get();

        $environments = CanvasEnvironment::query()
            ->with('organization')
            ->when($organizationId !== null, fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $providers = AiGraderAiProvider::query()
            ->with('organization')
            ->when($organizationId !== null, fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->where('enabled', true)
            ->whereIn('provider_type', AiGraderAiProvider::supportedTypes())
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return [
            'organizations' => $organizations,
            'environments' => $environments,
            'providers' => $providers,
            'publicationModes' => [
                AiGraderBlueprintConfig::PUBLICATION_AUTO_PUBLISH => __('ai-grader.publication_modes.auto_publish'),
                AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL => __('ai-grader.publication_modes.teacher_approval'),
            ],
            'triggerModes' => [
                AiGraderBlueprintConfig::TRIGGER_AFTER_SUBMISSION => __('ai-grader.trigger_modes.after_submission'),
                AiGraderBlueprintConfig::TRIGGER_AFTER_DATE => __('ai-grader.trigger_modes.after_date'),
                AiGraderBlueprintConfig::TRIGGER_MANUAL => __('ai-grader.trigger_modes.manual'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function courseView(Request $request, array $context, array $canvasCourse = []): View
    {
        $data = $this->courseStatusData($request, $context, false);
        $filters = $this->courseFilterParameters($this->courseListFilters($request));
        $pageParameters = $this->coursePageParameters($request);

        return view('lti.ai-grader.course', array_merge($data, [
            'context' => $context,
            'canvasCourseName' => $this->canvasCourseName($canvasCourse, $context),
            'statusUrl' => route('lti.ai-grader.course-status', $this->ltiIndexParameters($context)),
            'pollUrl' => route('lti.ai-grader.course-status', array_merge($this->ltiIndexParameters($context), $filters, $pageParameters)),
            'forceRefreshUrl' => route('lti.ai-grader.force-refresh', array_merge($this->ltiIndexParameters($context), $filters, $pageParameters)),
            'pageUrl' => route('lti.ai-grader.index', array_merge($this->ltiIndexParameters($context), $filters, $pageParameters)),
        ]));
    }

    private function courseStatusView(Request $request, array $context, bool $dispatchProcessing = false): View
    {
        $filters = $this->courseFilterParameters($this->courseListFilters($request));
        $pageParameters = $this->coursePageParameters($request);

        return view('lti.ai-grader._course-status', array_merge($this->courseStatusData($request, $context, $dispatchProcessing), [
            'context' => $context,
            'statusUrl' => route('lti.ai-grader.course-status', $this->ltiIndexParameters($context)),
            'pollUrl' => route('lti.ai-grader.course-status', array_merge($this->ltiIndexParameters($context), $filters, $pageParameters)),
            'forceRefreshUrl' => route('lti.ai-grader.force-refresh', array_merge($this->ltiIndexParameters($context), $filters, $pageParameters)),
            'pageUrl' => route('lti.ai-grader.index', array_merge($this->ltiIndexParameters($context), $filters, $pageParameters)),
        ]));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function correctionItemStatusViewData(array $context, AiGraderCorrectionItem $correctionItem): array
    {
        $routeParameters = array_merge(
            ['correctionItem' => $correctionItem],
            $this->ltiIndexParameters($context)
        );

        return [
            'context' => $context,
            'correctionItem' => $correctionItem,
            'backUrl' => route('lti.ai-grader.index', $this->ltiIndexParameters($context)),
            'showUrl' => route('lti.ai-grader.show', $routeParameters),
            'statusUrl' => route('lti.ai-grader.show-status', $routeParameters),
            'shouldPollStatus' => $this->shouldPollCorrectionItemStatus($correctionItem),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{items: LengthAwarePaginator, summary: array<string, int>, processingDispatch: array<string, mixed>}
     */
    private function courseStatusData(Request $request, array $context, bool $dispatchProcessing = false): array
    {
        $filters = $this->courseListFilters($request);
        $filterParameters = $this->courseFilterParameters($filters);
        $ltiParameters = $this->ltiIndexParameters($context);
        $pageParameters = $this->coursePageParameters($request);
        $statusPath = route('lti.ai-grader.course-status');
        $statusParameters = array_merge($ltiParameters, $filterParameters);
        $baseQuery = AiGraderCorrectionItem::query()
            ->with(['blueprintConfig', 'quizConfig', 'questionConfig'])
            ->where('child_course_id', (string) $context['course_id'])
            ->latest();
        $query = $this->applyCourseListFilters(clone $baseQuery, $filters);
        $retryFailedCount = $this->retryableCourseItemsQuery($context, $request)->count();

        $summaryQuery = $this->summaryQueryForCourseList(clone $baseQuery, $filters);
        $summary = $this->itemsSummary($summaryQuery);
        $processingDispatch = $dispatchProcessing
            ? $this->dispatchCourseProcessingIfNeeded($context, clone $baseQuery, $summary)
            : $this->currentCourseProcessingState($context, clone $baseQuery, $summary);
        $items = (clone $query)
            ->paginate(20)
            ->withPath($statusPath)
            ->appends($statusParameters);
        $activeFilterLabel = $this->courseActiveFilterLabel($filters);

        return [
            'items' => $items,
            'summary' => $summary,
            'processingDispatch' => $processingDispatch,
            'filters' => $filters,
            'statusOptions' => $this->courseStatusOptions(),
            'activeFilterLabel' => $activeFilterLabel,
            'ltiParameters' => $ltiParameters,
            'filterParameters' => $filterParameters,
            'pageParameters' => $pageParameters,
            'retryFailedCount' => $retryFailedCount,
        ];
    }

    /**
     * Consulta o estado atual da disciplina filha sem disparar novo job.
     *
     * A rota HTMX deve apenas consultar o banco, evitando novo dispatch a cada polling.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, int>|null  $summary
     * @return array<string, mixed>
     */
    private function currentCourseProcessingState(array $context, Builder $query, ?array $summary = null): array
    {
        $courseId = (string) ($context['course_id'] ?? '');

        if ($courseId === '') {
            return $this->emptyCourseProcessingState();
        }

        return $this->buildCourseProcessingState(
            courseId: $courseId,
            counts: $this->courseProcessingCounts($query, $summary),
            checkingActive: $this->courseCheckInProgress($courseId),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, int>|null  $summary
     * @return array<string, mixed>
     */
    private function dispatchCourseProcessingIfNeeded(array $context, Builder $query, ?array $summary = null): array
    {
        $courseId = (string) ($context['course_id'] ?? '');

        if ($courseId === '') {
            return $this->emptyCourseProcessingState();
        }

        $counts = $this->courseProcessingCounts($query, $summary);
        $pendingCount = $counts['pending'];

        $resolution = $this->pipelineGroupsForChildCourse($courseId, $query, $context);
        $dispatchGroups = $resolution['groups'];
        $resolutionMessages = $resolution['messages'];
        $autoMappedCount = $resolution['auto_mapped'];

        if ($dispatchGroups->isEmpty()) {
            return $this->buildCourseProcessingState(
                courseId: $courseId,
                counts: $counts,
                checkingActive: false,
                resolutionMessages: $resolutionMessages,
            );
        }

        $lockKey = $this->coursePipelineLockKey($courseId);

        if (! Cache::add($lockKey, now()->toDateTimeString(), now()->addSeconds(75))) {
            return $this->buildCourseProcessingState(
                courseId: $courseId,
                counts: $counts,
                checkingActive: $this->courseCheckInProgress($courseId),
                groups: $dispatchGroups->count(),
            );
        }

        foreach ($dispatchGroups as $group) {
            $blueprintConfigId = (int) $group['blueprint_config_id'];
            $childCourseId = (string) $group['child_course_id'];
            $canvasQuizId = (string) $group['canvas_quiz_id'];
            $canvasAssignmentId = (string) $group['canvas_assignment_id'];
            dispatch(new AiGraderRunPipelineJob(
                blueprintConfigId: $blueprintConfigId,
                childCourseId: $childCourseId,
                childQuizId: $canvasQuizId,
                childAssignmentId: $canvasAssignmentId,
                limit: null,
                metadata: [
                    'trigger' => 'lti_manual_force_refresh',
                    'course_id' => $courseId,
                    'pending_items' => $pendingCount,
                    'canvas_user_id' => $context['canvas_user_id'] ?? null,
                    'canvas_user_login_id' => $context['login'] ?? null,
                ],
            ));
        }

        return $this->buildCourseProcessingState(
            courseId: $courseId,
            counts: $counts,
            checkingActive: true,
            dispatched: true,
            groups: $dispatchGroups->count(),
            autoMappedCount: $autoMappedCount,
        );
    }

    private function coursePipelineLockKey(string $courseId): string
    {
        return 'ai-grader:lti-course-pipeline:'.$courseId;
    }

    /**
     * @param  array<string, int>|null  $summary
     * @return array{pending:int, processing:int, pending_teacher_approval:int, failed:int, pending_quota:int, pending_configuration:int}
     */
    private function courseProcessingCounts(Builder $query, ?array $summary = null): array
    {
        $counts = $summary ?? $this->itemsSummary($query);

        return [
            'pending' => (int) ($counts['pending'] ?? 0),
            'processing' => (int) ($counts['processing'] ?? 0),
            'pending_teacher_approval' => (int) ($counts['pending_teacher_approval'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'pending_quota' => (int) ($counts['pending_quota'] ?? 0),
            'pending_configuration' => (int) ($counts['pending_configuration'] ?? 0),
        ];
    }

    /**
     * @param  array{pending:int, processing:int, pending_teacher_approval:int, failed:int, pending_quota:int, pending_configuration:int}  $counts
     * @param  array<int, string>  $resolutionMessages
     * @return array<string, mixed>
     */
    private function buildCourseProcessingState(
        string $courseId,
        array $counts,
        bool $checkingActive,
        bool $dispatched = false,
        int $groups = 0,
        array $resolutionMessages = [],
        int $autoMappedCount = 0,
    ): array {
        $pendingProcessing = $counts['pending'] + $counts['processing'];
        $pendingConfiguration = $counts['pending_quota'] + $counts['pending_configuration'];
        $hasProcessingWork = $pendingProcessing > 0;
        $hasConfigurationAttention = $pendingConfiguration > 0;
        $hasTeacherApproval = $counts['pending_teacher_approval'] > 0;
        $hasFailures = $counts['failed'] > 0;
        $checkingOnly = $checkingActive && ! $hasProcessingWork && ! $hasTeacherApproval && ! $hasFailures && ! $hasConfigurationAttention;
        $showCards = ! $checkingOnly && ($hasProcessingWork || $hasTeacherApproval || $hasFailures || $resolutionMessages !== []);
        $cards = [];

        if ($hasProcessingWork) {
            $cards[] = [
                'tone' => $dispatched ? 'success' : 'info',
                'title' => $dispatched ? __('ai-grader.lti.new_submissions_found') : __('ai-grader.lti.corrections_in_progress'),
                'message' => $dispatched
                    ? __('ai-grader.lti.corrections_in_progress')
                    : ($checkingActive ? __('ai-grader.lti.force_refresh_active') : __('ai-grader.lti.force_refresh_pending_only')),
                'count' => $pendingProcessing,
                'auto_refresh' => true,
            ];
        }

        if ($hasTeacherApproval) {
            $cards[] = [
                'tone' => 'primary',
                'title' => __('ai-grader.lti.teacher_action_required'),
                'message' => __('ai-grader.lti.pending_teacher_approval_message', [
                    'count' => $counts['pending_teacher_approval'],
                ]),
                'count' => $counts['pending_teacher_approval'],
                'status_filter' => 'pending_teacher_approval',
            ];
        }

        if ($hasFailures) {
            $cards[] = [
                'tone' => 'danger',
                'title' => __('ai-grader.lti.failures_found'),
                'message' => __('ai-grader.lti.failures_pending_message', [
                    'count' => $counts['failed'],
                ]),
                'count' => $counts['failed'],
                'retry_action' => true,
            ];
        }

        if ($resolutionMessages !== []) {
            $cards[] = [
                'tone' => 'warning',
                'title' => __('ai-grader.lti.processing_title'),
                'message' => $this->processingResolutionMessage($counts['pending'], $resolutionMessages),
                'count' => null,
            ];
        }

        return [
            'dispatched' => $dispatched,
            'active' => $checkingActive || $hasProcessingWork,
            'pending' => $counts['pending'],
            'processing' => $counts['processing'],
            'groups' => $groups,
            'message' => $checkingOnly ? __('ai-grader.lti.checking_new_submissions') : ($cards[0]['message'] ?? null),
            'level' => $hasFailures ? 'danger' : ($dispatched ? 'success' : 'info'),
            'completed_without_new_items' => ! $checkingActive && ! $hasProcessingWork && ! $hasTeacherApproval && ! $hasFailures && ! $hasConfigurationAttention,
            'checking_only' => $checkingOnly,
            'show_cards' => $showCards,
            'should_poll' => $checkingActive || $hasProcessingWork,
            'cards' => $cards,
            'job_label' => __('ai-grader.lti.job_checking_label'),
            'checking_message' => __('ai-grader.lti.checking_new_submissions'),
            'finished_message' => ! $checkingActive && ! $hasProcessingWork && ! $hasTeacherApproval && ! $hasFailures
                ? __('ai-grader.lti.processing_finished')
                : null,
            'mapped_message' => $autoMappedCount > 0
                ? __('ai-grader.lti.force_refresh_mapped', ['count' => $autoMappedCount])
                : null,
            'course_id' => $courseId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCourseProcessingState(): array
    {
        return [
            'dispatched' => false,
            'active' => false,
            'pending' => 0,
            'processing' => 0,
            'groups' => 0,
            'message' => null,
            'level' => 'info',
            'completed_without_new_items' => true,
            'checking_only' => false,
            'show_cards' => false,
            'should_poll' => false,
            'cards' => [],
            'job_label' => __('ai-grader.lti.job_checking_label'),
            'checking_message' => __('ai-grader.lti.checking_new_submissions'),
            'finished_message' => __('ai-grader.lti.processing_finished'),
            'mapped_message' => null,
            'course_id' => null,
        ];
    }

    private function shouldPollCorrectionItemStatus(AiGraderCorrectionItem $correctionItem): bool
    {
        return in_array($correctionItem->status, [
            AiGraderCorrectionItem::STATUS_PENDING,
            AiGraderCorrectionItem::STATUS_PROCESSING,
            AiGraderCorrectionItem::STATUS_AI_CORRECTED,
        ], true);
    }

    private function courseCheckInProgress(string $courseId): bool
    {
        $lockTimestamp = $this->coursePipelineLockTimestamp($courseId);

        if ($lockTimestamp === null) {
            return false;
        }

        if ($this->courseHasRunningBatch($courseId)) {
            return true;
        }

        return ! AiGraderBatch::query()
            ->where('child_course_id', $courseId)
            ->where('started_at', '>=', $lockTimestamp)
            ->exists();
    }

    private function courseHasRunningBatch(string $courseId): bool
    {
        return AiGraderBatch::query()
            ->where('child_course_id', $courseId)
            ->where('status', AiGraderBatch::STATUS_PROCESSING)
            ->exists();
    }

    private function coursePipelineLockTimestamp(string $courseId): ?Carbon
    {
        $value = Cache::get($this->coursePipelineLockKey($courseId));

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{groups: Collection<int, array{blueprint_config_id:int, child_course_id:string, canvas_quiz_id:string, canvas_assignment_id:string, provider_id:int|null}>, auto_mapped: int, messages: array<int, string>}
     */
    private function pipelineGroupsForChildCourse(string $courseId, Builder $query, array $context): array
    {
        $resolution = $this->childCourseResolver->resolvePipelineGroups(
            $courseId,
            $this->integerOrNull($context['canvas_environment_id'] ?? null)
        );
        $groups = $resolution['groups'];

        if ($groups->isEmpty()) {
            $existingItemGroups = (clone $query)
                ->whereNotNull('ai_grader_blueprint_config_id')
                ->whereNotNull('canvas_quiz_id')
                ->whereNotNull('canvas_assignment_id')
                ->get([
                    'ai_grader_blueprint_config_id',
                    'child_course_id',
                    'canvas_quiz_id',
                    'canvas_assignment_id',
                ])
                ->groupBy(fn (AiGraderCorrectionItem $item): string => implode('|', [
                    (string) $item->ai_grader_blueprint_config_id,
                    (string) $item->child_course_id,
                    (string) $item->canvas_quiz_id,
                    (string) $item->canvas_assignment_id,
                ]));

            foreach ($existingItemGroups as $existingGroup) {
                /** @var Collection<int, AiGraderCorrectionItem> $existingGroup */
                $item = $existingGroup->first();

                if (! $item instanceof AiGraderCorrectionItem) {
                    continue;
                }

                $blueprintConfig = AiGraderBlueprintConfig::query()->find($item->ai_grader_blueprint_config_id);

                if (! $blueprintConfig instanceof AiGraderBlueprintConfig || ! $blueprintConfig->enabled) {
                    continue;
                }

                $groups->push([
                    'blueprint_config_id' => (int) $blueprintConfig->id,
                    'child_course_id' => (string) $item->child_course_id,
                    'canvas_quiz_id' => (string) $item->canvas_quiz_id,
                    'canvas_assignment_id' => (string) $item->canvas_assignment_id,
                    'provider_id' => $blueprintConfig->ai_provider_id !== null ? (int) $blueprintConfig->ai_provider_id : null,
                ]);
            }
        }

        return [
            'groups' => $groups
                ->filter(fn (array $group): bool => $group['child_course_id'] !== ''
                    && $group['canvas_quiz_id'] !== ''
                    && $group['canvas_assignment_id'] !== '')
                ->unique(fn (array $group): string => implode('|', [
                    $group['blueprint_config_id'],
                    $group['child_course_id'],
                    $group['canvas_quiz_id'],
                    $group['canvas_assignment_id'],
                ]))
                ->values(),
            'auto_mapped' => (int) $resolution['auto_mapped'],
            'messages' => $resolution['messages'],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function courseDiagnosticView(
        Request $request,
        array $context,
        ?CanvasEnvironment $canvasEnvironment,
        string $message,
        ?string $details = null,
        array $courseDiagnostics = []
    ): Response {
        return response()
            ->view('lti.ai-grader.course-diagnostic', [
                'context' => $context,
                'canvasEnvironment' => $canvasEnvironment,
                'message' => $message,
                'details' => $details,
                'diagnostics' => $this->diagnosticsFor($request),
                'courseDiagnostics' => $courseDiagnostics,
            ], Response::HTTP_OK);
    }

    /**
     * @param  array<string, mixed>  $course
     * @return array{endpoint: string|null, status_http: int|null, has_blueprint: bool, has_is_blueprint: bool}
     */
    private function canvasCourseDiagnostics(?string $endpoint, ?int $status = null, array $course = []): array
    {
        return [
            'endpoint' => $endpoint,
            'status_http' => $status,
            'has_blueprint' => array_key_exists('blueprint', $course),
            'has_is_blueprint' => array_key_exists('is_blueprint', $course),
        ];
    }

    private function httpStatusFromException(Throwable $exception): ?int
    {
        if ($exception instanceof CanvasApiException) {
            return $exception->status;
        }

        $code = $exception->getCode();

        return is_int($code) && $code >= 100 && $code <= 599
            ? $code
            : null;
    }

    private function blueprintForCourse(string $courseId): ?AiGraderBlueprintConfig
    {
        return AiGraderBlueprintConfig::query()
            ->where('blueprint_course_id', $courseId)
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function retryMetadata(array $context, array $extra = []): array
    {
        return array_filter(array_merge([
            'course_id' => $context['course_id'] ?? null,
            'canvas_user_id' => $context['canvas_user_id'] ?? null,
            'canvas_user_login_id' => $context['login'] ?? null,
            'organization_id' => $context['organization_id'] ?? null,
            'canvas_environment_id' => $context['canvas_environment_id'] ?? null,
            'launch_mode' => $context['launch_mode'] ?? null,
        ], $extra), static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function retryResponse(
        Request $request,
        array $context,
        string $message,
        string $flashKey,
        AiGraderCorrectionItem $correctionItem,
    ): RedirectResponse|Response|View {
        if ($this->isHtmxRequest($request)) {
            session()->flash($flashKey, $message);

            return $this->retryHtmxView($request, $context, $correctionItem);
        }

        return $this->redirectToCorrectionItem($context, $correctionItem->fresh(), $flashKey, $message);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array{queued:int, ai:int, publication:int, ignored:int}  $summary
     */
    private function retryFailedResponse(
        Request $request,
        array $context,
        array $summary,
        string $originMode = 'course',
        ?AiGraderBlueprintConfig $blueprintConfig = null,
    ): RedirectResponse|Response|View {
        $flashKey = $summary['queued'] > 0 ? 'success' : 'error';
        $message = $summary['queued'] > 0
            ? __('ai-grader.lti.retry_bulk_queued', ['count' => $summary['queued']])
            : __('ai-grader.lti.retry_bulk_empty');

        if ($this->isHtmxRequest($request)) {
            session()->flash($flashKey, $message);

            return $originMode === 'blueprint' && $blueprintConfig instanceof AiGraderBlueprintConfig
                ? $this->blueprintView($request, $blueprintConfig, $context)
                : $this->courseStatusView($request, $context);
        }

        return $this->redirectToLtiIndex($context, $flashKey, $message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function retryHtmxView(Request $request, array $context, AiGraderCorrectionItem $correctionItem): View|Response
    {
        if (($context['launch_mode'] ?? null) === 'blueprint') {
            $correctionItem->loadMissing('blueprintConfig');

            if ($correctionItem->blueprintConfig instanceof AiGraderBlueprintConfig) {
                return $this->blueprintView($request, $correctionItem->blueprintConfig, $context);
            }
        }

        return $this->courseStatusView($request, $context);
    }

    private function isHtmxRequest(Request $request): bool
    {
        return $request->header('HX-Request') === 'true';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function retryOriginMode(Request $request, array $context): string
    {
        $canvasCourse = $this->resolveCanvasCourse(
            $this->resolveCanvasEnvironment($request, $context),
            $context['course_id']
        );

        return ($canvasCourse['resolved'] ?? false) === true && ($canvasCourse['is_blueprint'] ?? false) === true
            ? 'blueprint'
            : 'course';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function retryableCourseItemsQuery(array $context, Request $request): Builder
    {
        return $this->applyCourseListFilters(
            AiGraderCorrectionItem::query()
                ->where('child_course_id', (string) $context['course_id'])
                ->latest(),
            $this->courseListFilters($request)
        )->whereIn('status', AiGraderCorrectionItem::retryableStatuses());
    }

    private function retryableBlueprintItemsQuery(AiGraderBlueprintConfig $blueprintConfig, Request $request): Builder
    {
        return $this->applyBlueprintListFilters(
            AiGraderCorrectionItem::query()
                ->where('ai_grader_blueprint_config_id', $blueprintConfig->id)
                ->latest(),
            $this->blueprintListFilters($request)
        )->whereIn('status', AiGraderCorrectionItem::retryableStatuses());
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $overrides
     */
    private function redirectToLtiIndex(
        array $context,
        ?string $flashKey = null,
        ?string $flashMessage = null,
        array $overrides = [],
        ?string $fragment = null
    ): RedirectResponse {
        $url = route('lti.ai-grader.index', $this->ltiIndexParameters($context, $overrides));

        if ($fragment !== null && $fragment !== '') {
            $url .= '#'.ltrim($fragment, '#');
        }

        $redirect = redirect()->to($url);

        if ($flashKey !== null && $flashMessage !== null) {
            return $redirect->with($flashKey, $flashMessage);
        }

        return $redirect;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function redirectToCorrectionItem(
        array $context,
        AiGraderCorrectionItem $correctionItem,
        string $flashKey,
        string $flashMessage,
    ): RedirectResponse {
        return redirect()
            ->route('lti.ai-grader.show', array_merge(
                ['correctionItem' => $correctionItem],
                $this->ltiIndexParameters($context)
            ))
            ->with($flashKey, trim($flashMessage));
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ltiIndexParameters(array $context, array $overrides = []): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function safeLocaleRedirect(Request $request, ?string $redirectTo, array $context): string
    {
        $redirectTo = $this->stringOrNull($redirectTo);

        if ($redirectTo !== null) {
            $parts = parse_url($redirectTo);
            $host = strtolower((string) ($parts['host'] ?? ''));
            $requestHost = strtolower((string) $request->getHost());
            $path = (string) ($parts['path'] ?? '');

            if (($host === '' || $host === $requestHost) && str_starts_with($path, '/lti')) {
                return $redirectTo;
            }
        }

        return route('lti.ai-grader.index', $this->ltiIndexParameters($context));
    }

    private function processingResolutionMessage(int $pendingCount, array $messages): string
    {
        if ($messages !== []) {
            return implode(' ', $messages);
        }

        return $pendingCount > 0
            ? __('ai-grader.lti.force_refresh_mapping_pending')
            : __('ai-grader.lti.force_refresh_no_mapping');
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function authorizedBlueprintContext(
        Request $request,
        AiGraderBlueprintConfig $blueprintConfig,
        string $ability = 'configure',
    ): array|Response {
        $context = $this->context($request);
        $this->rememberContext($context);

        $access = $this->modeAccessDecision($context, 'blueprint', $ability);
        if (! $access['allowed']) {
            return $this->forbidden($context, $access['reason'], $request);
        }

        if ($context['course_id'] === null || (string) $blueprintConfig->blueprint_course_id !== (string) $context['course_id']) {
            return $this->forbidden(
                $context,
                'deployment_not_allowed',
                $request,
                __('ai-grader.lti.blueprint_not_belongs')
            );
        }

        return $context;
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function authorizedCorrectionItemContext(
        Request $request,
        AiGraderCorrectionItem $correctionItem,
        string $ability = 'view',
    ): array|Response {
        $context = $this->context($request);
        $this->rememberContext($context);

        $courseId = (string) ($context['course_id'] ?? '');

        if ($courseId !== '' && (string) $correctionItem->child_course_id === $courseId) {
            $access = $this->modeAccessDecision($context, 'course', $ability);

            if (! $access['allowed']) {
                return $this->forbidden($context, $access['reason'], $request);
            }

            return array_merge($context, ['launch_mode' => 'course']);
        }

        $correctionItem->loadMissing('blueprintConfig');

        if ($courseId !== ''
            && $correctionItem->blueprintConfig instanceof AiGraderBlueprintConfig
            && (string) $correctionItem->blueprintConfig->blueprint_course_id === $courseId) {
            $access = $this->modeAccessDecision($context, 'blueprint', $ability);

            if (! $access['allowed']) {
                return $this->forbidden($context, $access['reason'], $request);
            }

            return array_merge($context, ['launch_mode' => 'blueprint']);
        }

        return response()
            ->view('lti.ai-grader.forbidden', [
                'context' => $context,
                'message' => __('ai-grader.lti.review_forbidden'),
                'blockReason' => 'deployment_not_allowed',
                'diagnostics' => $this->diagnosticsFor($request),
            ], Response::HTTP_FORBIDDEN);
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function authorizedQuizContext(Request $request, AiGraderQuizConfig $quizConfig): array|Response
    {
        $quizConfig->loadMissing('blueprintConfig');

        return $this->authorizedBlueprintContext($request, $quizConfig->blueprintConfig);
    }

    public function exportBlueprint(Request $request, AiGraderBlueprintConfig $blueprintConfig): StreamedResponse|Response
    {
        $context = $this->authorizedBlueprintContext($request, $blueprintConfig, 'view');
        if ($context instanceof Response) {
            return $context;
        }

        $filters = $this->blueprintListFilters($request);
        $query = AiGraderCorrectionItem::query()
            ->with(['quizConfig'])
            ->where('ai_grader_blueprint_config_id', $blueprintConfig->id)
            ->latest();

        return $this->streamCorrectionItemsCsv(
            $this->applyBlueprintListFilters($query, $filters),
            'graderai-blueprint'
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function blueprintGridData(Request $request, AiGraderBlueprintConfig $blueprintConfig, array $context): array
    {
        $filters = $this->blueprintListFilters($request);
        $filterParameters = $this->blueprintFilterParameters($filters);
        $ltiParameters = $this->ltiIndexParameters($context);
        $pageParameters = $this->coursePageParameters($request);
        $query = AiGraderCorrectionItem::query()
            ->with(['quizConfig', 'questionConfig'])
            ->where('ai_grader_blueprint_config_id', $blueprintConfig->id)
            ->latest();
        $retryFailedCount = $this->retryableBlueprintItemsQuery($blueprintConfig, $request)->count();

        $items = $this->applyBlueprintListFilters(clone $query, $filters)
            ->paginate(20)
            ->withPath(route('lti.ai-grader.index'))
            ->appends(array_merge($ltiParameters, $filterParameters));

        $childCourseOptions = (clone $query)
            ->reorder()
            ->select(['child_course_id', 'child_course_name'])
            ->distinct()
            ->get()
            ->filter(fn (AiGraderCorrectionItem $item): bool => (string) $item->child_course_id !== '')
            ->sortBy(fn (AiGraderCorrectionItem $item): string => mb_strtolower((string) ($item->child_course_name ?: $item->child_course_id)))
            ->mapWithKeys(fn (AiGraderCorrectionItem $item): array => [
                (string) $item->child_course_id => $item->child_course_name ?: (string) $item->child_course_id,
            ])
            ->all();

        $quizOptions = $blueprintConfig->quizConfigs
            ->sortBy(fn (AiGraderQuizConfig $quizConfig): string => mb_strtolower((string) ($quizConfig->canvas_quiz_title ?: $quizConfig->canvas_quiz_id)))
            ->mapWithKeys(fn (AiGraderQuizConfig $quizConfig): array => [
                (string) $quizConfig->id => $quizConfig->canvas_quiz_title ?: (string) $quizConfig->canvas_quiz_id,
            ])
            ->all();

        return [
            'gridItems' => $items,
            'gridFilters' => $filters,
            'gridFilterParameters' => $filterParameters,
            'gridLtiParameters' => $ltiParameters,
            'gridStatusOptions' => $this->courseStatusOptions(),
            'gridChildCourseOptions' => $childCourseOptions,
            'gridQuizOptions' => $quizOptions,
            'gridActiveFilterLabel' => $this->blueprintActiveFilterLabel($filters, $childCourseOptions, $quizOptions),
            'gridPageParameters' => $pageParameters,
            'gridRetryFailedCount' => $retryFailedCount,
            'gridExportUrl' => route('lti.ai-grader.blueprint.export', array_merge(
                ['blueprintConfig' => $blueprintConfig],
                $ltiParameters,
                $filterParameters,
            )),
            'gridBaseUrl' => route('lti.ai-grader.index', $ltiParameters),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function childCoursesFor(AiGraderBlueprintConfig $blueprintConfig): Collection
    {
        return $blueprintConfig->quizConfigs
            ->flatMap(fn ($quizConfig): array => collect(data_get($quizConfig->settings, 'child_courses', []))
                ->filter(fn (mixed $item): bool => is_array($item))
                ->map(fn (array $item): array => [
                    'quiz_config_id' => $quizConfig->id,
                    'quiz_title' => $quizConfig->canvas_quiz_title,
                    'child_course_id' => (string) ($item['child_course_id'] ?? ''),
                    'child_course_name' => $item['child_course_name'] ?? null,
                    'canvas_quiz_id' => $item['canvas_quiz_id'] ?? $item['child_quiz_id'] ?? null,
                    'canvas_assignment_id' => $item['canvas_assignment_id'] ?? $item['child_assignment_id'] ?? null,
                    'last_seen_at' => $item['last_seen_at'] ?? null,
                    'source' => $item['source'] ?? null,
                ])->all())
            ->filter(fn (array $item): bool => $item['child_course_id'] !== '')
            ->unique(fn (array $item): string => implode('|', [
                $item['child_course_id'],
                (string) $item['canvas_quiz_id'],
                (string) $item['canvas_assignment_id'],
            ]))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewValidator(Request $request, AiGraderCorrectionItem $correctionItem): array
    {
        $scoreRules = ['nullable', 'numeric', 'min:0'];

        if (is_numeric($correctionItem->canvas_points_possible)) {
            $scoreRules[] = 'max:'.(float) $correctionItem->canvas_points_possible;
        }

        return Validator::make($request->all(), [
            'final_score' => $scoreRules,
            'final_feedback' => ['nullable', 'string'],
            'teacher_rubric_feedback' => ['nullable', 'array'],
            'teacher_rubric_feedback.*.criterion_id' => ['nullable', 'string', 'max:255'],
            'teacher_rubric_feedback.*.criterion_name' => ['nullable', 'string', 'max:255'],
            'teacher_rubric_feedback.*.selected_rating' => ['nullable', 'string', 'max:255'],
            'teacher_rubric_feedback.*.percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'teacher_rubric_feedback.*.max_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'teacher_rubric_feedback.*.max_points' => ['nullable', 'numeric', 'min:0'],
            'teacher_rubric_feedback.*.feedback' => ['nullable', 'string'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function reviewerContext(Request $request, array $context): array
    {
        return [
            'canvas_user_id' => $context['canvas_user_id'] ?? null,
            'name' => $context['name'] ?? null,
            'login' => $context['login'] ?? null,
            'local_user_id' => $request->user()?->id,
        ];
    }

    /**
     * @return array{student_name: string|null, status: string|null}
     */
    private function courseListFilters(Request $request): array
    {
        $studentName = $this->stringOrNull($request->input('student_name'))
            ?? $this->stringOrNull($request->input('q'));
        $status = $this->normalizeCourseStatusFilter($this->stringOrNull($request->input('status')));

        return [
            'student_name' => $studentName,
            'status' => $status,
        ];
    }

    /**
     * @param  array{student_name: string|null, status: string|null}  $filters
     * @return array<string, string>
     */
    private function courseFilterParameters(array $filters): array
    {
        return array_filter([
            'student_name' => $filters['student_name'],
            'status' => $filters['status'],
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, int>
     */
    private function coursePageParameters(Request $request): array
    {
        $page = $request->integer('page');

        return $page > 1 ? ['page' => $page] : [];
    }

    /**
     * @param  array{student_name: string|null, status: string|null}  $filters
     */
    private function applyCourseListFilters(Builder $query, array $filters): Builder
    {
        if ($filters['student_name'] !== null) {
            $search = $filters['student_name'];

            $query->where(function (Builder $nested) use ($search): void {
                $nested->where('canvas_user_name', 'like', '%'.$search.'%')
                    ->orWhere('canvas_user_id', 'like', '%'.$search.'%')
                    ->orWhere('canvas_user_login_id', 'like', '%'.$search.'%');
            });
        }

        return $this->applyStatusFilter($query, $filters['status']);
    }

    /**
     * @param  array{student_name: string|null, status: string|null}  $filters
     */
    private function summaryQueryForCourseList(Builder $query, array $filters): Builder
    {
        if ($filters['student_name'] !== null) {
            return $this->applyCourseListFilters($query, [
                'student_name' => $filters['student_name'],
                'status' => null,
            ]);
        }

        return $query;
    }

    private function applyStatusFilter(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            'pending' => $query->where('status', AiGraderCorrectionItem::STATUS_PENDING),
            'ai_corrected' => $query->whereIn('status', [
                AiGraderCorrectionItem::STATUS_AI_CORRECTED,
                AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL,
                AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS,
            ]),
            'pending_teacher_approval' => $query->where('status', AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL),
            'published_to_canvas' => $query->where('status', AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS),
            'failed' => $query->whereIn('status', [
                AiGraderCorrectionItem::STATUS_FAILED,
                AiGraderCorrectionItem::STATUS_PUBLICATION_FAILED,
            ]),
            'skipped_blank_answer' => $query->where('status', AiGraderCorrectionItem::STATUS_SKIPPED_BLANK_ANSWER),
            default => $query,
        };
    }

    /**
     * @return array<string, string>
     */
    private function courseStatusOptions(): array
    {
        return [
            '' => __('ai-grader.common.all'),
            'pending' => $this->statusLabel(AiGraderCorrectionItem::STATUS_PENDING),
            'ai_corrected' => $this->statusLabel(AiGraderCorrectionItem::STATUS_AI_CORRECTED),
            'pending_teacher_approval' => $this->statusLabel(AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL),
            'published_to_canvas' => $this->statusLabel(AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS),
            'failed' => $this->statusLabel(AiGraderCorrectionItem::STATUS_FAILED),
            'skipped_blank_answer' => $this->statusLabel(AiGraderCorrectionItem::STATUS_SKIPPED_BLANK_ANSWER),
        ];
    }

    private function normalizeCourseStatusFilter(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        return array_key_exists($status, $this->courseStatusOptions())
            ? ($status === '' ? null : $status)
            : null;
    }

    /**
     * @param  array{student_name: string|null, status: string|null}  $filters
     */
    private function courseActiveFilterLabel(array $filters): ?string
    {
        $parts = [];

        if ($filters['student_name'] !== null) {
            $parts[] = __('ai-grader.lti.course.filters.active_student', ['value' => $filters['student_name']]);
        }

        if ($filters['status'] !== null) {
            $parts[] = __('ai-grader.lti.course.filters.active_status', [
                'value' => $this->courseStatusOptions()[$filters['status']] ?? $filters['status'],
            ]);
        }

        if ($parts === []) {
            return null;
        }

        return __('ai-grader.lti.course.filters.active_prefix').' '.implode(' | ', $parts);
    }

    /**
     * @return array{student_name: string|null, child_course_id: string|null, status: string|null, ai_grader_quiz_config_id: string|null, created_from: string|null, created_to: string|null}
     */
    private function blueprintListFilters(Request $request): array
    {
        return [
            'student_name' => $this->stringOrNull($request->input('student_name')) ?? $this->stringOrNull($request->input('q')),
            'child_course_id' => $this->stringOrNull($request->input('child_course_id')),
            'status' => $this->normalizeCourseStatusFilter($this->stringOrNull($request->input('status'))),
            'ai_grader_quiz_config_id' => $this->stringOrNull($request->input('ai_grader_quiz_config_id')),
            'created_from' => $this->validDateFilter($request->input('created_from')),
            'created_to' => $this->validDateFilter($request->input('created_to')),
        ];
    }

    /**
     * @param  array{student_name: string|null, child_course_id: string|null, status: string|null, ai_grader_quiz_config_id: string|null, created_from: string|null, created_to: string|null}  $filters
     * @return array<string, string>
     */
    private function blueprintFilterParameters(array $filters): array
    {
        return array_filter([
            'student_name' => $filters['student_name'],
            'child_course_id' => $filters['child_course_id'],
            'status' => $filters['status'],
            'ai_grader_quiz_config_id' => $filters['ai_grader_quiz_config_id'],
            'created_from' => $filters['created_from'],
            'created_to' => $filters['created_to'],
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array{student_name: string|null, child_course_id: string|null, status: string|null, ai_grader_quiz_config_id: string|null, created_from: string|null, created_to: string|null}  $filters
     */
    private function applyBlueprintListFilters(Builder $query, array $filters): Builder
    {
        if ($filters['student_name'] !== null) {
            $search = $filters['student_name'];

            $query->where(function (Builder $nested) use ($search): void {
                $nested->where('canvas_user_name', 'like', '%'.$search.'%')
                    ->orWhere('canvas_user_id', 'like', '%'.$search.'%')
                    ->orWhere('canvas_user_login_id', 'like', '%'.$search.'%');
            });
        }

        if ($filters['child_course_id'] !== null) {
            $query->where('child_course_id', $filters['child_course_id']);
        }

        if ($filters['ai_grader_quiz_config_id'] !== null) {
            $query->where('ai_grader_quiz_config_id', (int) $filters['ai_grader_quiz_config_id']);
        }

        if ($filters['created_from'] !== null) {
            $query->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', $filters['created_from'])->startOfDay());
        }

        if ($filters['created_to'] !== null) {
            $query->where('created_at', '<=', Carbon::createFromFormat('Y-m-d', $filters['created_to'])->endOfDay());
        }

        return $this->applyStatusFilter($query, $filters['status']);
    }

    /**
     * @param  array{student_name: string|null, child_course_id: string|null, status: string|null, ai_grader_quiz_config_id: string|null, created_from: string|null, created_to: string|null}  $filters
     * @param  array<string, string>  $childCourseOptions
     * @param  array<string, string>  $quizOptions
     */
    private function blueprintActiveFilterLabel(array $filters, array $childCourseOptions, array $quizOptions): ?string
    {
        $parts = [];

        if ($filters['student_name'] !== null) {
            $parts[] = __('ai-grader.lti.course.filters.active_student', ['value' => $filters['student_name']]);
        }

        if ($filters['child_course_id'] !== null) {
            $parts[] = __('ai-grader.lti.blueprint.grid.filters.child_course').': '
                .($childCourseOptions[$filters['child_course_id']] ?? $filters['child_course_id']);
        }

        if ($filters['status'] !== null) {
            $parts[] = __('ai-grader.lti.course.filters.active_status', [
                'value' => $this->courseStatusOptions()[$filters['status']] ?? $filters['status'],
            ]);
        }

        if ($filters['ai_grader_quiz_config_id'] !== null) {
            $parts[] = __('ai-grader.lti.blueprint.grid.filters.quiz').': '
                .($quizOptions[$filters['ai_grader_quiz_config_id']] ?? $filters['ai_grader_quiz_config_id']);
        }

        if ($filters['created_from'] !== null) {
            $parts[] = __('ai-grader.lti.blueprint.grid.filters.created_from').': '.$filters['created_from'];
        }

        if ($filters['created_to'] !== null) {
            $parts[] = __('ai-grader.lti.blueprint.grid.filters.created_to').': '.$filters['created_to'];
        }

        if ($parts === []) {
            return null;
        }

        return __('ai-grader.lti.course.filters.active_prefix').' '.implode(' | ', $parts);
    }

    private function validDateFilter(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function statusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return '-';
        }

        return (string) __('ai-grader.correction_statuses.'.$status);
    }

    private function blueprintSummary(AiGraderBlueprintConfig $blueprintConfig, Builder $itemsQuery): array
    {
        $itemSummary = $this->itemsSummary($itemsQuery);
        $clientSetting = $blueprintConfig->organization?->aiGraderClientSetting;

        return array_merge($itemSummary, [
            'quizzes' => $blueprintConfig->quizConfigs->count(),
            'enabled_quizzes' => $blueprintConfig->quizConfigs->where('enabled', true)->count(),
            'enabled_essay_questions' => $blueprintConfig->quizConfigs
                ->flatMap(fn ($quizConfig) => $quizConfig->questionConfigs)
                ->filter(fn ($questionConfig): bool => $questionConfig->enabled
                    && $questionConfig->canvas_question_type === 'essay_question')
                ->count(),
            'quota_used' => $clientSetting?->quota_used ?? 0,
            'quota_reserved' => $clientSetting?->quota_reserved ?? 0,
            'quota_total' => $this->quotaTotal($clientSetting),
        ]);
    }

    private function emptyBlueprintSummary(): array
    {
        return array_merge($this->emptyItemsSummary(), [
            'quizzes' => 0,
            'enabled_quizzes' => 0,
            'enabled_essay_questions' => 0,
            'quota_used' => 0,
            'quota_reserved' => 0,
            'quota_total' => 0,
        ]);
    }

    private function itemsSummary(Builder $query): array
    {
        $statuses = $query
            ->reorder()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $value): int => (int) $value);

        $pending = $statuses[AiGraderCorrectionItem::STATUS_PENDING] ?? 0;
        $processing = $statuses[AiGraderCorrectionItem::STATUS_PROCESSING] ?? 0;
        $pendingQuota = $statuses[AiGraderCorrectionItem::STATUS_PENDING_QUOTA] ?? 0;
        $pendingConfiguration = $statuses[AiGraderCorrectionItem::STATUS_PENDING_CONFIGURATION] ?? 0;
        $aiCorrected = $statuses[AiGraderCorrectionItem::STATUS_AI_CORRECTED] ?? 0;
        $pendingTeacherApproval = $statuses[AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL] ?? 0;
        $publishedToCanvas = $statuses[AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS] ?? 0;
        $failed = ($statuses[AiGraderCorrectionItem::STATUS_FAILED] ?? 0)
            + ($statuses[AiGraderCorrectionItem::STATUS_PUBLICATION_FAILED] ?? 0);
        $skippedBlankAnswer = $statuses[AiGraderCorrectionItem::STATUS_SKIPPED_BLANK_ANSWER] ?? 0;

        return [
            'total' => $statuses->sum(),
            'pending' => $pending,
            'processing' => $processing,
            'pending_quota' => $pendingQuota,
            'pending_configuration' => $pendingConfiguration,
            'ai_corrected' => $aiCorrected + $pendingTeacherApproval + $publishedToCanvas,
            'pending_teacher_approval' => $pendingTeacherApproval,
            'published_to_canvas' => $publishedToCanvas,
            'failed' => $failed,
            'skipped_blank_answer' => $skippedBlankAnswer,
        ];
    }

    private function emptyItemsSummary(): array
    {
        return [
            'total' => 0,
            'pending' => 0,
            'processing' => 0,
            'pending_quota' => 0,
            'pending_configuration' => 0,
            'ai_corrected' => 0,
            'pending_teacher_approval' => 0,
            'published_to_canvas' => 0,
            'failed' => 0,
            'skipped_blank_answer' => 0,
        ];
    }

    private function quotaTotal(?AiGraderClientSetting $clientSetting): int
    {
        if (! $clientSetting) {
            return 0;
        }

        return (int) $clientSetting->trial_quota_total + (int) $clientSetting->purchased_quota_total;
    }

    private function streamCorrectionItemsCsv(Builder $query, string $baseFilename): StreamedResponse
    {
        $headers = [
            __('ai-grader.exports.columns.id'),
            __('ai-grader.exports.columns.child_course'),
            __('ai-grader.exports.columns.student'),
            __('ai-grader.exports.columns.canvas_student_id'),
            __('ai-grader.exports.columns.quiz'),
            __('ai-grader.exports.columns.question'),
            __('ai-grader.exports.columns.attempt'),
            __('ai-grader.exports.columns.status'),
            __('ai-grader.exports.columns.correction_mode'),
            __('ai-grader.exports.columns.rubric_used'),
            __('ai-grader.exports.columns.rubric_percentage'),
            __('ai-grader.exports.columns.ai_score'),
            __('ai-grader.exports.columns.final_score'),
            __('ai-grader.exports.columns.published_score'),
            __('ai-grader.exports.columns.points'),
            __('ai-grader.exports.columns.ai_feedback'),
            __('ai-grader.exports.columns.final_feedback'),
            __('ai-grader.exports.columns.rubric_feedback'),
            __('ai-grader.exports.columns.published_feedback'),
            __('ai-grader.exports.columns.published_at'),
            __('ai-grader.exports.columns.created_at'),
            __('ai-grader.exports.columns.updated_at'),
            __('ai-grader.exports.columns.error'),
        ];

        $filename = $baseFilename.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query, $headers): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers, ';');

            foreach ((clone $query)->reorder()->orderBy('id')->cursor() as $item) {
                /** @var AiGraderCorrectionItem $item */
                fputcsv($handle, [
                    $item->id,
                    $item->child_course_name ?: $item->child_course_id,
                    $item->canvas_user_name ?: $item->canvas_user_login_id ?: $item->canvas_user_id,
                    $item->canvas_user_id,
                    $item->quizConfig?->canvas_quiz_title ?: $item->canvas_quiz_id,
                    $item->canvas_question_name ?: $item->canvas_question_id,
                    $item->attempt,
                    $this->statusLabel($item->status),
                    $this->correctionModeLabel($item->correction_mode),
                    $item->rubric_title ?: $item->canvas_rubric_id,
                    $this->rubricPercentageForExport($item),
                    $item->ai_score,
                    $item->final_score,
                    $item->canvas_published_score,
                    $item->canvas_points_possible,
                    $item->ai_feedback,
                    $item->final_feedback,
                    $this->rubricFeedbackForExport($item),
                    $item->canvas_published_feedback,
                    $item->published_at?->format('Y-m-d H:i:s'),
                    $item->created_at?->format('Y-m-d H:i:s'),
                    $item->updated_at?->format('Y-m-d H:i:s'),
                    $item->canvas_publication_error ?: $item->failure_reason,
                ], ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{course_id: string|null, canvas_user_id: string|null, name: string|null, login: string|null, email: string|null, roles: array<int, string>, launch_id: string|null, deployment_id: string|null, context_title: string|null, canvas_environment_id: int|null, organization_id: int|null}
     */
    private function context(Request $request): array
    {
        $context = $request->attributes->get('ai_grader_lti_context');

        if (! is_array($context)) {
            $context = $request->session()->get('ai_grader_lti_context', []);
        }

        if (! is_array($context)
            || ($context['lti_authenticated'] ?? false) !== true
            || ! is_numeric($context['expires_at'] ?? null)
            || (int) $context['expires_at'] <= now()->timestamp
            || ! $this->ltiSecurityService->environmentForContext($context) instanceof CanvasEnvironment) {
            $request->session()->forget('ai_grader_lti_context');

            return $this->emptyLtiContext();
        }

        return array_merge($this->emptyLtiContext(), $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{allowed: bool, reason: string}
     */
    private function baseAccessDecision(array $context): array
    {
        if (($context['lti_authenticated'] ?? false) !== true) {
            return ['allowed' => false, 'reason' => 'invalid_lti_session'];
        }

        if ($context['course_id'] === null) {
            return ['allowed' => false, 'reason' => 'missing_course_context'];
        }

        $roles = $this->normalizedRoles($context);

        if ($roles->isEmpty()) {
            return ['allowed' => false, 'reason' => 'missing_roles'];
        }

        $blocked = $roles->contains(fn (string $role): bool => in_array($role, [
            'student',
            'studentenrollment',
            'learner',
        ], true));

        if ($blocked) {
            return ['allowed' => false, 'reason' => 'role_not_allowed'];
        }

        return [
            'allowed' => true,
            'reason' => 'allowed',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{allowed: bool, reason: string}
     */
    private function modeAccessDecision(array $context, string $mode, string $ability = 'view'): array
    {
        $base = $this->baseAccessDecision($context);

        if (! $base['allowed']) {
            return $base;
        }

        $allowedRoles = match ($ability) {
            'configure' => ['admin', 'administrator', 'accountadmin', 'designer', 'designerenrollment', 'contentdeveloper', 'teacher', 'teacherenrollment', 'instructor'],
            'review', 'retry' => ['admin', 'administrator', 'accountadmin', 'teacher', 'teacherenrollment', 'instructor', 'teachingassistant', 'taenrollment'],
            'publish' => ['admin', 'administrator', 'accountadmin', 'teacher', 'teacherenrollment', 'instructor'],
            default => $mode === 'blueprint'
                ? ['admin', 'administrator', 'accountadmin', 'designer', 'designerenrollment', 'contentdeveloper', 'teacher', 'teacherenrollment', 'instructor']
                : ['admin', 'administrator', 'accountadmin', 'teacher', 'teacherenrollment', 'instructor', 'teachingassistant', 'taenrollment', 'designer', 'designerenrollment'],
        };

        $allowed = $this->normalizedRoles($context)
            ->contains(fn (string $role): bool => $this->roleMatchesAny($role, $allowedRoles));

        return [
            'allowed' => $allowed,
            'reason' => $allowed ? 'allowed' : 'role_not_allowed',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function forbidden(array $context, string $reason, Request $request, ?string $message = null): Response
    {
        $this->logLaunchMode($request, $context, 'forbidden', $reason);

        return response()
            ->view('lti.ai-grader.forbidden', [
                'context' => $context,
                'message' => $message ?? $this->forbiddenMessage($reason),
                'blockReason' => $reason,
                'diagnostics' => $this->diagnosticsFor($request),
            ], Response::HTTP_FORBIDDEN);
    }

    private function forbiddenMessage(string $reason): string
    {
        if (in_array($reason, ['missing_course_context', 'invalid_lti_session'], true)) {
            return 'Sessao LTI expirada. Abra novamente o GraderAI pelo menu do curso no Canvas.';
        }

        return 'Você não tem permissão para acessar o GraderAI nesta disciplina.';
    }

    /**
     * @param  array<string, mixed>  $context
     * @return Collection<int, string>
     */
    private function normalizedRoles(array $context): Collection
    {
        return collect($context['roles'] ?? [])
            ->filter(fn (mixed $role): bool => is_scalar($role) && trim((string) $role) !== '')
            ->map(function (mixed $role): string {
                $role = mb_strtolower(trim((string) $role));
                $parts = preg_split('~[/#]~', $role) ?: [];

                return (string) (end($parts) ?: $role);
            })
            ->values();
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function roleMatchesAny(string $role, array $needles): bool
    {
        return in_array($role, $needles, true);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function mergeContextEnvironment(array $context, ?CanvasEnvironment $canvasEnvironment): array
    {
        if (! $canvasEnvironment instanceof CanvasEnvironment) {
            return $context;
        }

        $context['canvas_environment_id'] = $canvasEnvironment->id;
        $context['organization_id'] = $canvasEnvironment->organization_id;

        return $context;
    }

    /**
     * @param  array<string, mixed>  $canvasCourse
     * @param  array<string, mixed>  $context
     */
    private function canvasCourseName(array $canvasCourse, array $context, ?string $fallback = null): ?string
    {
        return $this->stringOrNull($canvasCourse['name'] ?? null)
            ?? $context['context_title']
            ?? $fallback;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logCanvasFailure(string $action, Throwable $exception, array $context = []): void
    {
        Log::warning('GraderAI Canvas access failed.', array_merge($context, [
            'action' => $action,
            'exception' => $exception::class,
            'message' => $this->sanitizer->sanitizeString($exception->getMessage()),
        ]));
    }

    private function isCanvasAccessFailure(Throwable $exception): bool
    {
        if ($exception instanceof CanvasApiException || $exception instanceof DecryptException) {
            return true;
        }

        return str_contains($exception->getMessage(), 'The MAC is invalid');
    }

    private function friendlyCanvasAccessMessage(Throwable $exception, string $fallback): string
    {
        if ($this->isCanvasAccessFailure($exception)) {
            return 'Nao foi possivel acessar o Canvas. Verifique se o token da instancia esta configurado corretamente no GraderAI.';
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logLaunchMode(Request $request, array $context, string $mode, ?string $reason = null): void
    {
        Log::info('GraderAI LTI context resolved.', [
            'course_id' => $context['course_id'],
            'canvas_user_id' => $context['canvas_user_id'],
            'login' => $context['login'],
            'roles' => $context['roles'],
            'deployment_id' => $context['deployment_id'] ?? null,
            'context_title' => $context['context_title'] ?? null,
            'host' => $request->getHost(),
            'mode' => $mode,
            'block_reason' => $reason,
        ]);
    }

    /**
     * @return array{host: string, method: string, received_keys: array<int, string>}
     */
    private function diagnosticsFor(Request $request): array
    {
        $keys = $this->flattenKeys($request->all());

        return [
            'host' => $request->getHost(),
            'method' => $request->method(),
            'received_keys' => collect($keys)
                ->reject(fn (string $key): bool => $this->isSensitiveKey($key))
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string|int, mixed>  $values
     * @return array<int, string>
     */
    private function flattenKeys(array $values, string $prefix = ''): array
    {
        $keys = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;
            $path = $prefix === '' ? $key : $prefix.'.'.$key;
            $keys[] = $path;

            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenKeys($value, $path));
            }
        }

        return $keys;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = mb_strtolower($key);

        return str_contains($key, 'id_token')
            || str_contains($key, 'authorization')
            || str_contains($key, 'api_key')
            || str_contains($key, 'token')
            || str_contains($key, 'signature')
            || str_contains($key, 'login_hint')
            || str_contains($key, 'lti_message_hint');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function rememberContext(array $context): void
    {
        if (($context['lti_authenticated'] ?? false) === true
            && is_numeric($context['expires_at'] ?? null)
            && (int) $context['expires_at'] > now()->timestamp) {
            session(['ai_grader_lti_context' => $context]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyLtiContext(): array
    {
        return [
            'lti_authenticated' => false,
            'lti_installation_id' => null,
            'organization_id' => null,
            'canvas_environment_id' => null,
            'issuer' => null,
            'client_id' => null,
            'deployment_id' => null,
            'course_id' => null,
            'account_id' => null,
            'canvas_user_id' => null,
            'name' => null,
            'login' => null,
            'email' => null,
            'roles' => [],
            'launch_id' => null,
            'context_title' => null,
            'expires_at' => null,
        ];
    }

    /**
     * @param  array<string|int, mixed>  $value
     */
    private function prettyJson(array $value): string
    {
        if ($value === []) {
            return '{}';
        }

        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{}';
    }

    /**
     * @return array<string, mixed>
     */
    private function blueprintRubricData(AiGraderBlueprintConfig $blueprintConfig): array
    {
        try {
            $rubrics = $this->syncService->listCourseRubrics($blueprintConfig)->all();

            return [
                'availableRubrics' => $rubrics,
                'availableRubricsById' => collect($rubrics)->mapWithKeys(fn (array $rubric): array => [
                    (string) $rubric['id'] => $rubric,
                ])->all(),
                'rubricsLoadError' => null,
            ];
        } catch (Throwable $exception) {
            Log::warning('GraderAI LTI rubric list could not be loaded.', [
                'blueprint_config_id' => $blueprintConfig->id,
                'blueprint_course_id' => $blueprintConfig->blueprint_course_id,
                'message' => $exception->getMessage(),
            ]);

            return [
                'availableRubrics' => [],
                'availableRubricsById' => [],
                'rubricsLoadError' => $this->friendlyCanvasAccessMessage($exception, __('ai-grader.lti.blueprint.question_rubric_load_failed')),
            ];
        }
    }

    private function correctionModeLabel(?string $mode): string
    {
        $mode = in_array($mode, AiGraderQuestionConfig::correctionModes(), true)
            ? $mode
            : AiGraderQuestionConfig::CORRECTION_MODE_SIMPLE;

        return (string) __('ai-grader.rubric.modes.'.$mode);
    }

    private function rubricPercentageForExport(AiGraderCorrectionItem $item): ?string
    {
        if (is_numeric($item->ai_rubric_percentage)) {
            return $this->formatFloat((float) $item->ai_rubric_percentage).'%';
        }

        if (! is_numeric($item->final_score) || ! is_numeric($item->canvas_points_possible) || (float) $item->canvas_points_possible <= 0) {
            return null;
        }

        return $this->formatFloat(((float) $item->final_score / (float) $item->canvas_points_possible) * 100).'%';
    }

    private function rubricFeedbackForExport(AiGraderCorrectionItem $item): string
    {
        $feedback = $item->teacher_rubric_feedback ?? $item->published_rubric_feedback ?? $item->ai_rubric_feedback;

        if (! is_array($feedback) || $feedback === []) {
            return '';
        }

        $lines = [];

        foreach ($feedback as $criterion) {
            if (! is_array($criterion)) {
                continue;
            }

            $label = trim((string) ($criterion['criterion_name'] ?? $criterion['criterion_id'] ?? 'Criterio'));
            $percentage = is_numeric($criterion['percentage'] ?? null) ? $this->formatFloat((float) $criterion['percentage']).'%' : null;
            $maxPercentage = is_numeric($criterion['max_percentage'] ?? null) ? $this->formatFloat((float) $criterion['max_percentage']).'%' : null;
            $criterionFeedback = trim((string) ($criterion['feedback'] ?? ''));
            $parts = array_filter([
                $label,
                $percentage,
                $maxPercentage !== null ? 'peso '.$maxPercentage : null,
                $criterionFeedback !== '' ? $criterionFeedback : null,
            ]);

            if ($parts !== []) {
                $lines[] = implode(' - ', $parts);
            }
        }

        return implode("\n", $lines);
    }

    private function jsonEncodeComparable(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : 'null';
    }

    private function formatFloat(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function numericStringOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        if ($value === null || ! ctype_digit($value)) {
            return null;
        }

        return $value;
    }

    private function integerOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit(trim($value))) {
            return (int) trim($value);
        }

        return null;
    }
}
