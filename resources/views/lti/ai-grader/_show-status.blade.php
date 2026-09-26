@php
    $viewHelper = \App\Support\AiGraderLtiView::class;
    $questionConfig = $correctionItem->questionConfig;
    $questionTitle =
        $correctionItem->canvas_question_name ?:
        data_get($questionConfig, 'canvas_question_name') ?:
        data_get($questionConfig, 'question_name') ?:
        $correctionItem->canvas_question_id ?:
        '-';
    $pointsPossible = is_numeric($correctionItem->canvas_points_possible) ? (float) $correctionItem->canvas_points_possible : null;
    $usesCanvasRubric = $correctionItem->usesCanvasRubric();
    $statusClasses = [
        \App\Models\AiGraderCorrectionItem::STATUS_PENDING => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        \App\Models\AiGraderCorrectionItem::STATUS_PROCESSING => 'bg-info-subtle text-info-emphasis border border-info-subtle',
        \App\Models\AiGraderCorrectionItem::STATUS_AI_CORRECTED => 'bg-info-subtle text-info-emphasis border border-info-subtle',
        \App\Models\AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL => 'bg-primary-subtle text-primary-emphasis border border-primary-subtle',
        \App\Models\AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS => 'bg-success-subtle text-success-emphasis border border-success-subtle',
        \App\Models\AiGraderCorrectionItem::STATUS_FAILED => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
        \App\Models\AiGraderCorrectionItem::STATUS_PUBLICATION_FAILED => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
        \App\Models\AiGraderCorrectionItem::STATUS_SKIPPED_BLANK_ANSWER => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
        \App\Models\AiGraderCorrectionItem::STATUS_APPROVED_BY_TEACHER => 'bg-primary-subtle text-primary-emphasis border border-primary-subtle',
        \App\Models\AiGraderCorrectionItem::STATUS_ADJUSTED_BY_TEACHER => 'bg-primary-subtle text-primary-emphasis border border-primary-subtle',
    ];
    $statusLabel = __('ai-grader.correction_statuses.' . $correctionItem->status);
    $statusClass = $statusClasses[$correctionItem->status] ?? 'bg-light text-dark border';
    $reviewStatusLabel = $correctionItem->review_status ? __('ai-grader.review_statuses.' . $correctionItem->review_status) : '-';
    $retryLabel = $correctionItem->hasPublicationFailure()
        ? __('ai-grader.lti.retry_publication_button')
        : __('ai-grader.lti.retry_correction_button');
    $formatNumber = static function (mixed $value): string {
        if (! is_numeric($value)) {
            return '-';
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    };
    $formatDateTime = static fn (mixed $value): string => $viewHelper::formatDateTime($value);
    $headerMeta = array_filter([
        $formatDateTime($correctionItem->ai_corrected_at ?? $correctionItem->created_at) !== '-'
            ? __('ai-grader.lti.show.processed_on') . ' ' . $formatDateTime($correctionItem->ai_corrected_at ?? $correctionItem->created_at)
            : null,
        $correctionItem->published_at ? __('ai-grader.lti.show.published_on') . ' ' . $formatDateTime($correctionItem->published_at) : null,
    ]);
    $selectedScore = $correctionItem->final_score ?? $correctionItem->ai_score;
@endphp

<div id="ai-grader-correction-status"
    hx-get="{{ $statusUrl }}"
    hx-trigger="{{ $shouldPollStatus ? 'load, every 5s' : 'none' }}"
    hx-swap="outerHTML"
    hx-indicator="#ai-grader-correction-status-indicator"
    data-page-url="{{ $showUrl }}">
    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <h1 class="h3 mb-1">{{ __('ai-grader.lti.show.heading', ['id' => $correctionItem->id]) }}</h1>
                    <div class="fw-semibold">{{ $correctionItem->canvas_user_name ?: $correctionItem->canvas_user_id }}</div>
                    @if ($headerMeta !== [])
                        <div class="graderai-header-meta mt-1">{{ implode(' | ', $headerMeta) }}</div>
                    @endif
                    @if ($shouldPollStatus)
                        <div class="d-flex flex-wrap align-items-center gap-2 text-muted small mt-2">
                            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                {{ __('ai-grader.lti.processing_in_progress') }}
                            </span>
                            <span id="ai-grader-correction-status-indicator" class="htmx-indicator">
                                <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                {{ __('ai-grader.lti.refreshing_status') }}
                            </span>
                        </div>
                    @else
                        <span id="ai-grader-correction-status-indicator" class="htmx-indicator text-muted small">
                            <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                            {{ __('ai-grader.lti.refreshing_status') }}
                        </span>
                    @endif
                </div>

                <a class="btn btn-light" href="{{ $backUrl }}">{{ __('ai-grader.common.back') }}</a>
            </div>

            <div class="graderai-detail-grid mt-4">
                <div class="graderai-detail-card">
                    <div class="graderai-card-label">{{ __('ai-grader.lti.show.assessment_summary') }}</div>
                    <div class="graderai-card-line">
                        <span>{{ __('ai-grader.lti.show.quiz') }}</span>
                        <span class="graderai-card-line-value">{{ $correctionItem->quizConfig?->canvas_quiz_title ?: $correctionItem->canvas_quiz_id }}</span>
                    </div>
                    <div class="graderai-card-line">
                        <span>{{ __('ai-grader.lti.show.question_title') }}</span>
                        <span class="graderai-card-line-value">{{ $questionTitle }}</span>
                    </div>
                    <div class="graderai-card-line">
                        <span>{{ __('ai-grader.lti.show.attempt') }}</span>
                        <span class="graderai-card-line-value">{{ $correctionItem->attempt ?: '-' }}</span>
                    </div>
                </div>

                <div class="graderai-detail-card">
                    <div class="graderai-card-label">{{ __('ai-grader.lti.show.correction_summary') }}</div>
                    <div class="graderai-card-line">
                        <span>{{ __('ai-grader.lti.show.status') }}</span>
                        <span class="graderai-card-line-value">
                            <span class="badge {{ $statusClass }}">{{ $statusLabel }}</span>
                        </span>
                    </div>
                    <div class="graderai-card-line">
                        <span>{{ __('ai-grader.lti.show.review') }}</span>
                        <span class="graderai-card-line-value">{{ $reviewStatusLabel }}</span>
                    </div>
                    <div class="graderai-card-line">
                        <span>{{ __('ai-grader.lti.show.correction_mode') }}</span>
                        <span class="graderai-card-line-value">{{ __('ai-grader.rubric.modes.' . $correctionItem->normalizedCorrectionMode()) }}</span>
                    </div>
                    @if ($usesCanvasRubric)
                        <div class="graderai-card-line">
                            <span>{{ __('ai-grader.lti.show.rubric') }}</span>
                            <span class="graderai-card-line-value">{{ $correctionItem->rubric_title ?: ((is_array($correctionItem->rubric_snapshot) ? $correctionItem->rubric_snapshot : [])['title'] ?? '-') }}</span>
                        </div>
                    @endif
                </div>

                <div class="graderai-detail-card">
                    <div class="graderai-card-label">{{ __('ai-grader.lti.show.scores_summary') }}</div>
                    @unless ($usesCanvasRubric)
                        <div class="graderai-card-line">
                            <span>{{ __('ai-grader.lti.show.ai_suggested_score') }}</span>
                            <span class="graderai-card-line-value">
                                @if (is_numeric($correctionItem->ai_score) && $pointsPossible !== null)
                                    {{ $formatNumber($correctionItem->ai_score) }} / {{ $formatNumber($pointsPossible) }}
                                @else
                                    {{ is_numeric($correctionItem->ai_score) ? $formatNumber($correctionItem->ai_score) : '-' }}
                                @endif
                            </span>
                        </div>
                    @endunless
                    <div class="graderai-card-line">
                        <span>{{ __('ai-grader.lti.show.final_score') }}</span>
                        <span class="graderai-card-line-value">
                            @if (is_numeric($selectedScore) && $pointsPossible !== null)
                                {{ $formatNumber($selectedScore) }} / {{ $formatNumber($pointsPossible) }}
                            @else
                                {{ is_numeric($selectedScore) ? $formatNumber($selectedScore) : '-' }}
                            @endif
                        </span>
                    </div>
                    <div class="graderai-card-line">
                        <span>{{ __('ai-grader.lti.show.canvas_published_score') }}</span>
                        <span class="graderai-card-line-value">
                            @if (is_numeric($correctionItem->canvas_published_score) && $pointsPossible !== null)
                                {{ $formatNumber($correctionItem->canvas_published_score) }} / {{ $formatNumber($pointsPossible) }}
                            @else
                                {{ is_numeric($correctionItem->canvas_published_score) ? $formatNumber($correctionItem->canvas_published_score) : '-' }}
                            @endif
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($correctionItem->canRetry())
        <div class="d-flex justify-content-end mb-4">
            <form method="POST" action="{{ route('lti.ai-grader.correction-items.retry', ['correctionItem' => $correctionItem]) }}">
                @csrf
                @include('lti.ai-grader._context-fields', ['context' => $context])
                <button type="submit" class="btn btn-outline-warning">{{ $retryLabel }}</button>
            </form>
        </div>
    @endif
</div>
