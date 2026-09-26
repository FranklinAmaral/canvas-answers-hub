@extends('layouts.lti')

@section('title', __('ai-grader.lti.titles.blueprint'))

@section('content')
    @php
        $formatDateTime = static fn (mixed $value): string => \App\Support\AiGraderLtiView::formatDateTime($value);
        $instructionsExample = <<<'TEXT'
Você é um avaliador acadêmico responsável por corrigir uma questão discursiva com base no enunciado, na resposta esperada, nos critérios de avaliação, na rubrica de pontuação e na resposta enviada pelo estudante.

Avalie a resposta de forma objetiva, justa, consistente e proporcional à pontuação máxima da questão.

Enunciado:
[INSERIR ENUNCIADO]

Resposta esperada:
[INSERIR ESPELHO DE CORREÇÃO]

Critérios de avaliação:
[INSERIR CRITÉRIOS]

Resposta do estudante:
[INSERIR RESPOSTA]

Formato obrigatório da saída:

Nota: [valor entre 0 e a pontuação máxima]

Justificativa:
[Explique brevemente a nota atribuída.]

Pontos positivos:
[Liste os principais acertos.]

Pontos de melhoria:
[Liste o que faltou ou o que pode melhorar.]
TEXT;
        $statusText = $blueprintConfig->enabled
            ? __('ai-grader.lti.blueprint.status_active')
            : __('ai-grader.lti.blueprint.status_inactive');
        $summaryCards = [
            __('ai-grader.lti.blueprint.cards.status') => $statusText,
            __('ai-grader.lti.blueprint.cards.provider') => $blueprintConfig->aiProvider?->name
                ?: __('ai-grader.lti.blueprint.provider_placeholder'),
            __('ai-grader.lti.blueprint.cards.publication_mode') => $blueprintConfig->publication_mode ? __('ai-grader.publication_modes.' . $blueprintConfig->publication_mode) : '-',
            __('ai-grader.lti.blueprint.cards.trigger_mode') => $blueprintConfig->trigger_mode ? __('ai-grader.trigger_modes.' . $blueprintConfig->trigger_mode) : '-',
            __('ai-grader.lti.blueprint.cards.scheduled_at') => $formatDateTime($blueprintConfig->scheduled_at),
            __('ai-grader.lti.blueprint.cards.configured_quizzes') => $summary['quizzes'],
            __('ai-grader.lti.blueprint.cards.enabled_questions') => $summary['enabled_essay_questions'],
            __('ai-grader.lti.blueprint.cards.quota_used') => $summary['quota_used'] . ' / ' . $summary['quota_total'],
            __('ai-grader.lti.blueprint.cards.quota_reserved') => $summary['quota_reserved'],
        ];
        $metricCards = [
            [
                'label' => __('ai-grader.lti.blueprint.cards.total'),
                'value' => $summary['total'],
                'status' => null,
                'active' => ($gridFilters['status'] ?? null) === null,
            ],
            [
                'label' => __('ai-grader.lti.blueprint.cards.pending'),
                'value' => $summary['pending'],
                'status' => 'pending',
                'active' => ($gridFilters['status'] ?? null) === 'pending',
            ],
            [
                'label' => __('ai-grader.lti.blueprint.cards.ai_corrected'),
                'value' => $summary['ai_corrected'],
                'status' => 'ai_corrected',
                'active' => ($gridFilters['status'] ?? null) === 'ai_corrected',
            ],
            [
                'label' => __('ai-grader.lti.blueprint.cards.pending_teacher_approval'),
                'value' => $summary['pending_teacher_approval'],
                'status' => 'pending_teacher_approval',
                'active' => ($gridFilters['status'] ?? null) === 'pending_teacher_approval',
            ],
            [
                'label' => __('ai-grader.lti.blueprint.cards.published_to_canvas'),
                'value' => $summary['published_to_canvas'],
                'status' => 'published_to_canvas',
                'active' => ($gridFilters['status'] ?? null) === 'published_to_canvas',
            ],
            [
                'label' => __('ai-grader.lti.blueprint.cards.failed'),
                'value' => $summary['failed'],
                'status' => 'failed',
                'active' => ($gridFilters['status'] ?? null) === 'failed',
            ],
            [
                'label' => __('ai-grader.lti.blueprint.cards.skipped_blank_answer'),
                'value' => $summary['skipped_blank_answer'],
                'status' => 'skipped_blank_answer',
                'active' => ($gridFilters['status'] ?? null) === 'skipped_blank_answer',
            ],
        ];
        $statusBadges = [
            \App\Models\AiGraderCorrectionItem::STATUS_PENDING => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
            \App\Models\AiGraderCorrectionItem::STATUS_AI_CORRECTED => 'bg-info-subtle text-info-emphasis border border-info-subtle',
            \App\Models\AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL => 'bg-primary-subtle text-primary-emphasis border border-primary-subtle',
            \App\Models\AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS => 'bg-success-subtle text-success-emphasis border border-success-subtle',
            \App\Models\AiGraderCorrectionItem::STATUS_FAILED => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
            \App\Models\AiGraderCorrectionItem::STATUS_PUBLICATION_FAILED => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
            \App\Models\AiGraderCorrectionItem::STATUS_SKIPPED_BLANK_ANSWER => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
        ];
        $gridPersistParameters = array_merge($gridFilterParameters ?? [], $gridPageParameters ?? []);
    @endphp

    <div id="ai-grader-blueprint-page">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h1 class="h3 mb-1">{{ __('ai-grader.lti.blueprint.heading') }}</h1>
                <div class="text-muted">{{ $canvasCourseName ?: $blueprintConfig->blueprint_course_id }}</div>
            </div>
            <span class="badge bg-light text-dark">{{ __('ai-grader.lti.blueprint.status_badge', ['id' => $context['course_id']]) }}</span>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        @if (! empty($rubricsLoadError))
            <div class="alert alert-warning">{{ $rubricsLoadError }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <div class="fw-semibold mb-1">{{ __('ai-grader.messages.validation_review_fields') }}</div>
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="row">
            @foreach ($summaryCards as $label => $value)
                <div class="col-md-3 mb-3">
                    <div class="card metric h-100">
                        <div class="card-body">
                            <div class="text-muted small">{{ $label }}</div>
                            <div class="h5 mb-0">{{ $value }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

    <div class="row">
        @foreach ($metricCards as $metric)
            @php
                $cardFilters = $gridFilterParameters;
                unset($cardFilters['status']);
                if ($metric['status'] !== null) {
                    $cardFilters['status'] = $metric['status'];
                }
                $cardUrl = route('lti.ai-grader.index', array_merge($gridLtiParameters, $cardFilters));
            @endphp
            <div class="col-md-3 mb-3">
                <a href="{{ $cardUrl }}" class="text-decoration-none text-reset d-block">
                    <div @class([
                        'card metric h-100',
                        'border-primary shadow-sm' => $metric['active'],
                    ])>
                        <div class="card-body">
                            <div @class([
                                'small',
                                'text-muted' => ! $metric['active'],
                                'text-primary fw-semibold' => $metric['active'],
                            ])>{{ $metric['label'] }}</div>
                            <div class="h4 mb-0">{{ $metric['value'] }}</div>
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                <div>
                    <h2 class="h5 mb-1">{{ __('ai-grader.lti.blueprint.settings_title') }}</h2>
                    <div class="text-muted small">
                        {{ $blueprintConfig->enabled ? __('ai-grader.lti.blueprint.toggle_help_active') : __('ai-grader.lti.blueprint.toggle_help_inactive') }}
                    </div>
                </div>

                <form method="POST" action="{{ route('lti.ai-grader.blueprint.toggle-enabled', $blueprintConfig) }}">
                    @csrf
                    @include('lti.ai-grader._context-fields', ['context' => $context])
                    <input type="hidden" name="enabled" value="{{ $blueprintConfig->enabled ? 0 : 1 }}">
                    <button class="btn {{ $blueprintConfig->enabled ? 'btn-outline-danger' : 'btn-outline-success' }}" type="submit">
                        {{ $blueprintConfig->enabled ? __('ai-grader.lti.blueprint.deactivate_button') : __('ai-grader.lti.blueprint.activate_button') }}
                    </button>
                </form>
            </div>

            <form method="POST" action="{{ route('lti.ai-grader.blueprint.settings', $blueprintConfig) }}">
                @csrf
                @include('lti.ai-grader._context-fields', ['context' => $context])
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="ai_provider_id">{{ __('ai-grader.lti.blueprint.provider_label') }}</label>
                        <select class="form-select" id="ai_provider_id" name="ai_provider_id">
                            <option value="">{{ __('ai-grader.lti.blueprint.provider_placeholder') }}</option>
                            @foreach ($providers as $provider)
                                <option value="{{ $provider->id }}" @selected((string) old('ai_provider_id', $blueprintConfig->ai_provider_id) === (string) $provider->id)>
                                    {{ $provider->name }} - {{ $provider->provider_type }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="publication_mode">{{ __('ai-grader.lti.blueprint.publication_label') }}</label>
                        <select class="form-select" id="publication_mode" name="publication_mode" required>
                            @foreach ($publicationModes as $value => $label)
                                <option value="{{ $value }}" @selected(old('publication_mode', $blueprintConfig->publication_mode) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="trigger_mode">{{ __('ai-grader.lti.blueprint.trigger_label') }}</label>
                        <select class="form-select" id="trigger_mode" name="trigger_mode" required>
                            @foreach ($triggerModes as $value => $label)
                                <option value="{{ $value }}" @selected(old('trigger_mode', $blueprintConfig->trigger_mode) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="scheduled_at">{{ __('ai-grader.lti.blueprint.scheduled_label') }}</label>
                        <input class="form-control"
                            type="text"
                            id="scheduled_at"
                            name="scheduled_at"
                            value="{{ old('scheduled_at', optional($blueprintConfig->scheduled_at)->format('Y-m-d H:i:s')) }}"
                            placeholder="2026-06-09 14:00:00">
                    </div>

                    <div class="col-12 mb-1">
                        <div class="form-check">
                            <input class="form-check-input"
                                type="checkbox"
                                id="teacher_can_view_grading_prompt"
                                name="teacher_can_view_grading_prompt"
                                value="1"
                                @checked(old('teacher_can_view_grading_prompt', $blueprintConfig->teacher_can_view_grading_prompt))>
                            <label class="form-check-label" for="teacher_can_view_grading_prompt">
                                {{ __('ai-grader.lti.blueprint.teacher_prompt_visibility_label') }}
                            </label>
                        </div>
                        <div class="form-text">{{ __('ai-grader.lti.blueprint.teacher_prompt_visibility_help') }}</div>
                    </div>
                </div>

                <button class="btn btn-primary" type="submit">{{ __('ai-grader.lti.blueprint.save_button') }}</button>
            </form>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="h5 mb-0">{{ __('ai-grader.lti.blueprint.quizzes_title') }}</h2>
                <form method="POST" action="{{ route('lti.ai-grader.blueprint.sync-quizzes', $blueprintConfig) }}">
                    @csrf
                    @include('lti.ai-grader._context-fields', ['context' => $context])
                    <button class="btn btn-outline-primary btn-sm" type="submit">{{ __('ai-grader.lti.blueprint.sync_quizzes_button') }}</button>
                </form>
            </div>
            <p class="text-muted small">{{ __('ai-grader.lti.blueprint.quizzes_description') }}</p>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('ai-grader.lti.blueprint.quiz_table.quiz') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.quiz_table.quiz_id') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.quiz_table.assignment_id') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.quiz_table.enabled') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.quiz_table.correction') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.quiz_table.essay_questions') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.quiz_table.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($blueprintConfig->quizConfigs as $quizConfig)
                            @php
                                $essayQuestions = $quizConfig->questionConfigs
                                    ->where('canvas_question_type', \App\Models\AiGraderQuestionConfig::TYPE_ESSAY);
                            @endphp
                            <tr id="quiz-{{ $quizConfig->id }}">
                                <td>{{ $quizConfig->canvas_quiz_title ?: '-' }}</td>
                                <td>{{ $quizConfig->canvas_quiz_id }}</td>
                                <td>{{ $quizConfig->canvas_assignment_id ?: '-' }}</td>
                                <td>{{ $quizConfig->enabled ? __('ai-grader.common.yes') : __('ai-grader.common.no') }}</td>
                                <td>{{ $quizConfig->correction_enabled ? __('ai-grader.common.yes') : __('ai-grader.common.no') }}</td>
                                <td>{{ $essayQuestions->where('enabled', true)->count() }} / {{ $essayQuestions->count() }}</td>
                                <td>
                                    <div class="d-flex flex-wrap gap-2">
                                        <form method="POST" action="{{ route('lti.ai-grader.quiz.toggle', $quizConfig) }}">
                                            @csrf
                                            @include('lti.ai-grader._context-fields', ['context' => $context])
                                            <input type="hidden" name="enabled" value="0">
                                            <input type="hidden" name="correction_enabled" value="0">
                                            <label class="form-check form-check-inline mb-0">
                                                <input class="form-check-input" type="checkbox" name="enabled" value="1" @checked($quizConfig->enabled)>
                                                <span class="form-check-label">{{ __('ai-grader.lti.blueprint.quiz_actions.enable') }}</span>
                                            </label>
                                            <label class="form-check form-check-inline mb-0">
                                                <input class="form-check-input" type="checkbox" name="correction_enabled" value="1" @checked($quizConfig->correction_enabled)>
                                                <span class="form-check-label">{{ __('ai-grader.lti.blueprint.quiz_actions.correct') }}</span>
                                            </label>
                                            <button class="btn btn-outline-secondary btn-sm" type="submit">{{ __('ai-grader.lti.blueprint.quiz_actions.save') }}</button>
                                        </form>

                                        <form method="POST" action="{{ route('lti.ai-grader.quiz.sync-essay-questions', $quizConfig) }}">
                                            @csrf
                                            @include('lti.ai-grader._context-fields', ['context' => $context])
                                            <button class="btn btn-outline-primary btn-sm" type="submit">{{ __('ai-grader.lti.blueprint.quiz_actions.sync_questions') }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="7" class="bg-light">
                                    <p class="text-muted small mb-3">{{ __('ai-grader.lti.blueprint.questions_description') }}</p>

                                    @forelse ($essayQuestions as $questionConfig)
                                        @php
                                            $selectedCorrectionMode = old('correction_mode', $questionConfig->correction_mode ?: \App\Models\AiGraderQuestionConfig::CORRECTION_MODE_SIMPLE);
                                            $selectedRubricId = old('canvas_rubric_id', $questionConfig->canvas_rubric_id);
                                            $selectedRubric = $availableRubricsById[$selectedRubricId] ?? (is_array($questionConfig->rubric_snapshot) ? $questionConfig->rubric_snapshot : null);
                                        @endphp
                                        <form id="question-{{ $questionConfig->id }}"
                                            class="border rounded bg-white p-3 mb-2"
                                            method="POST"
                                            action="{{ route('lti.ai-grader.question.update', $questionConfig) }}">
                                            @csrf
                                            @include('lti.ai-grader._context-fields', ['context' => $context])
                                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                                <div>
                                                    <div class="fw-semibold">
                                                        {{ $questionConfig->canvas_question_name ?: __('ai-grader.lti.blueprint.question_fallback', ['id' => $questionConfig->canvas_question_id]) }}
                                                    </div>
                                                    <div class="text-muted small">
                                                        question_id {{ $questionConfig->canvas_question_id }}
                                                        @if ($questionConfig->canvas_points_possible !== null)
                                                            - {{ $questionConfig->canvas_points_possible }} pts
                                                        @endif
                                                    </div>
                                                </div>
                                                <label class="form-check mb-0">
                                                    <input type="hidden" name="enabled" value="0">
                                                    <input class="form-check-input" type="checkbox" name="enabled" value="1" @checked($questionConfig->enabled)>
                                                    <span class="form-check-label">{{ __('ai-grader.lti.blueprint.question_enabled') }}</span>
                                                </label>
                                            </div>
                                            <div class="text-muted small mb-2">{{ \Illuminate\Support\Str::limit(strip_tags((string) $questionConfig->canvas_question_text), 240) }}</div>
                                            <div class="row g-3 mb-2">
                                                <div class="col-lg-6">
                                                    <label class="form-label d-block">{{ __('ai-grader.lti.blueprint.question_mode_label') }}</label>
                                                    <div class="d-flex flex-wrap gap-3">
                                                        <label class="form-check mb-0">
                                                            <input class="form-check-input"
                                                                type="radio"
                                                                name="correction_mode"
                                                                value="{{ \App\Models\AiGraderQuestionConfig::CORRECTION_MODE_SIMPLE }}"
                                                                data-rubric-mode-toggle="question-{{ $questionConfig->id }}"
                                                                @checked($selectedCorrectionMode === \App\Models\AiGraderQuestionConfig::CORRECTION_MODE_SIMPLE)>
                                                            <span class="form-check-label">{{ __('ai-grader.rubric.modes.simple') }}</span>
                                                        </label>
                                                        <label class="form-check mb-0">
                                                            <input class="form-check-input"
                                                                type="radio"
                                                                name="correction_mode"
                                                                value="{{ \App\Models\AiGraderQuestionConfig::CORRECTION_MODE_CANVAS_RUBRIC }}"
                                                                data-rubric-mode-toggle="question-{{ $questionConfig->id }}"
                                                                @checked($selectedCorrectionMode === \App\Models\AiGraderQuestionConfig::CORRECTION_MODE_CANVAS_RUBRIC)>
                                                            <span class="form-check-label">{{ __('ai-grader.rubric.modes.canvas_rubric') }}</span>
                                                        </label>
                                                    </div>
                                                </div>
                                                <div class="col-lg-6">
                                                    <div data-rubric-config="question-{{ $questionConfig->id }}" @class(['d-none' => $selectedCorrectionMode !== \App\Models\AiGraderQuestionConfig::CORRECTION_MODE_CANVAS_RUBRIC])>
                                                        <label class="form-label" for="question-{{ $questionConfig->id }}-rubric">{{ __('ai-grader.lti.blueprint.question_rubric_label') }}</label>
                                                        <select class="form-select form-select-sm @error('canvas_rubric_id') is-invalid @enderror"
                                                            id="question-{{ $questionConfig->id }}-rubric"
                                                            name="canvas_rubric_id">
                                                            <option value="">{{ __('ai-grader.lti.blueprint.question_rubric_placeholder') }}</option>
                                                            @foreach ($availableRubrics as $rubric)
                                                                <option value="{{ $rubric['id'] }}" @selected((string) $selectedRubricId === (string) $rubric['id'])>
                                                                    {{ $rubric['title'] }} @if (is_numeric($rubric['points_possible'] ?? null))({{ rtrim(rtrim(number_format((float) $rubric['points_possible'], 2, '.', ''), '0'), '.') }} pts)@endif
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                        @error('canvas_rubric_id')
                                                            <div class="invalid-feedback d-block">{{ $message }}</div>
                                                        @enderror
                                                        @if ($availableRubrics === [])
                                                            <div class="text-muted small mt-2">{{ __('ai-grader.lti.blueprint.question_rubric_empty') }}</div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                            <label class="form-label" for="question-{{ $questionConfig->id }}-instructions">{{ __('ai-grader.lti.blueprint.question_instructions') }}</label>
                                            <details class="border rounded bg-light p-3 mb-2">
                                                <summary class="fw-semibold">{{ __('ai-grader.lti.blueprint.example_summary') }}</summary>
                                                <p class="text-muted small mt-2 mb-2">{{ __('ai-grader.lti.blueprint.example_help') }}</p>
                                                <textarea class="form-control form-control-sm mb-2" id="question-{{ $questionConfig->id }}-example" rows="14" readonly>{{ $instructionsExample }}</textarea>
                                                <button class="btn btn-outline-secondary btn-sm"
                                                    type="button"
                                                    data-ai-grader-use-example
                                                    data-target="question-{{ $questionConfig->id }}-instructions"
                                                    data-source="question-{{ $questionConfig->id }}-example">
                                                    {{ __('ai-grader.lti.blueprint.use_example') }}
                                                </button>
                                            </details>
                                            <textarea class="form-control mb-2" id="question-{{ $questionConfig->id }}-instructions" name="ai_grading_instructions" rows="4">{{ old('ai_grading_instructions', $questionConfig->ai_grading_instructions) }}</textarea>
                                            <div data-rubric-config="question-{{ $questionConfig->id }}" @class(['mb-2', 'd-none' => $selectedCorrectionMode !== \App\Models\AiGraderQuestionConfig::CORRECTION_MODE_CANVAS_RUBRIC])>
                                                <div class="small text-muted mb-2">{{ __('ai-grader.lti.blueprint.question_rubric_help') }}</div>
                                                @if ($questionConfig->rubric_synced_at)
                                                    <div class="small text-muted mb-2">
                                                        {{ __('ai-grader.lti.blueprint.rubric_synced', ['date' => $formatDateTime($questionConfig->rubric_synced_at)]) }}
                                                    </div>
                                                @endif
                                                @include('lti.ai-grader._rubric-summary', [
                                                    'rubric' => $selectedRubric,
                                                    'compact' => true,
                                                ])
                                            </div>
                                            <div class="d-flex flex-wrap gap-2">
                                                <button class="btn btn-outline-primary btn-sm" type="submit">{{ __('ai-grader.lti.blueprint.question_save') }}</button>
                                                <button class="btn btn-outline-secondary btn-sm"
                                                    type="submit"
                                                    data-rubric-config="question-{{ $questionConfig->id }}"
                                                    @class(['d-none' => $selectedCorrectionMode !== \App\Models\AiGraderQuestionConfig::CORRECTION_MODE_CANVAS_RUBRIC])>
                                                    {{ __('ai-grader.lti.blueprint.sync_rubric_button') }}
                                                </button>
                                            </div>
                                        </form>
                                    @empty
                                        <div class="text-muted small">{{ __('ai-grader.lti.blueprint.question_empty') }}</div>
                                    @endforelse
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted">{{ __('ai-grader.lti.blueprint.quiz_table.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                <div>
                    <h2 class="h5 mb-1">{{ __('ai-grader.lti.blueprint.grid.title') }}</h2>
                    <div class="text-muted small">{{ __('ai-grader.lti.blueprint.grid.subtitle') }}</div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    @if (($gridRetryFailedCount ?? 0) > 0)
                        <form method="POST"
                            action="{{ route('lti.ai-grader.retry-failed') }}"
                            hx-post="{{ route('lti.ai-grader.retry-failed') }}"
                            hx-target="#ai-grader-blueprint-page"
                            hx-select="#ai-grader-blueprint-page"
                            hx-swap="outerHTML">
                            @csrf
                            @include('lti.ai-grader._context-fields', ['context' => $context])
                            @foreach ($gridPersistParameters as $key => $value)
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endforeach
                            <button type="submit" class="btn btn-outline-warning">
                                {{ __('ai-grader.lti.retry_failed_button', ['count' => $gridRetryFailedCount]) }}
                            </button>
                        </form>
                    @endif

                    <a class="btn btn-outline-secondary" href="{{ $gridExportUrl }}">{{ __('ai-grader.common.export_csv') }}</a>
                </div>
            </div>

            <form method="GET" action="{{ $gridBaseUrl }}" class="row g-3 align-items-end mb-3">
                @include('lti.ai-grader._context-fields', ['context' => $context])

                <div class="col-md-3">
                    <label class="form-label" for="student_name">{{ __('ai-grader.lti.blueprint.grid.filters.student') }}</label>
                    <input class="form-control"
                        type="text"
                        id="student_name"
                        name="student_name"
                        value="{{ $gridFilters['student_name'] }}">
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="child_course_id">{{ __('ai-grader.lti.blueprint.grid.filters.child_course') }}</label>
                    <select class="form-select" id="child_course_id" name="child_course_id">
                        <option value="">{{ __('ai-grader.common.all') }}</option>
                        @foreach ($gridChildCourseOptions as $value => $label)
                            <option value="{{ $value }}" @selected((string) $gridFilters['child_course_id'] === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="status">{{ __('ai-grader.lti.blueprint.grid.filters.status') }}</label>
                    <select class="form-select" id="status" name="status">
                        @foreach ($gridStatusOptions as $value => $label)
                            <option value="{{ $value }}" @selected(($gridFilters['status'] ?? null) === ($value !== '' ? $value : null))>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="ai_grader_quiz_config_id">{{ __('ai-grader.lti.blueprint.grid.filters.quiz') }}</label>
                    <select class="form-select" id="ai_grader_quiz_config_id" name="ai_grader_quiz_config_id">
                        <option value="">{{ __('ai-grader.common.all') }}</option>
                        @foreach ($gridQuizOptions as $value => $label)
                            <option value="{{ $value }}" @selected((string) $gridFilters['ai_grader_quiz_config_id'] === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="created_from">{{ __('ai-grader.lti.blueprint.grid.filters.created_from') }}</label>
                    <input class="form-control" type="date" id="created_from" name="created_from" value="{{ $gridFilters['created_from'] }}">
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="created_to">{{ __('ai-grader.lti.blueprint.grid.filters.created_to') }}</label>
                    <input class="form-control" type="date" id="created_to" name="created_to" value="{{ $gridFilters['created_to'] }}">
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary flex-fill" type="submit">{{ __('ai-grader.common.filter') }}</button>
                    <a class="btn btn-light flex-fill" href="{{ $gridBaseUrl }}">{{ __('ai-grader.common.clear') }}</a>
                </div>
            </form>

            @if ($gridActiveFilterLabel)
                <div class="small text-muted mb-3">{{ $gridActiveFilterLabel }}</div>
            @endif

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('ai-grader.lti.blueprint.grid.table.child_course') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.grid.table.student') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.grid.table.quiz') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.grid.table.question') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.grid.table.attempt') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.grid.table.status') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.grid.table.ai_score') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.grid.table.canvas_score') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.grid.table.points') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.grid.table.published_at') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.grid.table.created_at') }}</th>
                            <th>{{ __('ai-grader.lti.blueprint.grid.table.action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($gridItems as $item)
                            <tr>
                                <td>{{ $item->child_course_name ?: $item->child_course_id }}</td>
                                <td>
                                    <div>{{ $item->canvas_user_name ?: '-' }}</div>
                                    <div class="text-muted small">{{ $item->canvas_user_id }}</div>
                                </td>
                                <td>{{ $item->quizConfig?->canvas_quiz_title ?: $item->canvas_quiz_id }}</td>
                                <td>{{ $item->canvas_question_name ?: $item->canvas_question_id }}</td>
                                <td>{{ $item->attempt ?: '-' }}</td>
                                <td>
                                    <span class="badge {{ $statusBadges[$item->status] ?? 'bg-light text-dark border' }}">
                                        {{ __('ai-grader.correction_statuses.' . $item->status) }}
                                    </span>
                                </td>
                                <td>{{ $item->ai_score ?? '-' }}</td>
                                <td>{{ $item->canvas_published_score ?? '-' }}</td>
                                <td>{{ $item->canvas_points_possible ?? '-' }}</td>
                                <td>{{ $formatDateTime($item->published_at) }}</td>
                                <td>{{ $formatDateTime($item->created_at) }}</td>
                                <td>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a class="btn btn-sm btn-outline-primary"
                                            href="{{ route('lti.ai-grader.show', array_merge(
                                                ['correctionItem' => $item],
                                                $gridLtiParameters,
                                            )) }}">
                                            {{ __('ai-grader.common.details') }}
                                        </a>

                                        @if ($item->canRetry())
                                            <form method="POST"
                                                action="{{ route('lti.ai-grader.correction-items.retry', ['correctionItem' => $item]) }}"
                                                hx-post="{{ route('lti.ai-grader.correction-items.retry', ['correctionItem' => $item]) }}"
                                                hx-target="#ai-grader-blueprint-page"
                                                hx-select="#ai-grader-blueprint-page"
                                                hx-swap="outerHTML">
                                                @csrf
                                                @include('lti.ai-grader._context-fields', ['context' => $context])
                                                @foreach ($gridPersistParameters as $key => $value)
                                                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                                @endforeach
                                                <button type="submit" class="btn btn-sm btn-outline-warning">
                                                    {{ __('ai-grader.common.reprocess') }}
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="text-center text-muted py-4">{{ __('ai-grader.lti.blueprint.grid.table.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($gridItems->hasPages())
                <div class="mt-4">
                    {{ $gridItems->links() }}
                </div>
            @endif
        </div>
    </div>
    </div>

    <script>
        document.addEventListener('click', function (event) {
            const button = event.target.closest('[data-ai-grader-use-example]');

            if (!button) {
                return;
            }

            const textarea = document.getElementById(button.dataset.target);
            const source = document.getElementById(button.dataset.source);

            if (!textarea || !source) {
                return;
            }

            textarea.value = source.value;
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            textarea.focus();
        });

        document.addEventListener('change', function (event) {
            const input = event.target.closest('[data-rubric-mode-toggle]');

            if (!input) {
                return;
            }

            document.querySelectorAll('[data-rubric-config="' + input.dataset.rubricModeToggle + '"]').forEach(function (element) {
                element.classList.toggle('d-none', input.value !== '{{ \App\Models\AiGraderQuestionConfig::CORRECTION_MODE_CANVAS_RUBRIC }}' || !input.checked);
            });
        });
    </script>
@endsection
