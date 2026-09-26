@php
    $formatDateTime = static fn (mixed $value): string => \App\Support\AiGraderLtiView::formatDateTime($value);
    $isCheckingOnly = (bool) ($processingDispatch['checking_only'] ?? false);
    $shouldPoll = (bool) ($processingDispatch['should_poll'] ?? false);
    $statusCards = is_array($processingDispatch['cards'] ?? null) ? $processingDispatch['cards'] : [];
    $currentStudentFilter = $filters['student_name'] ?? null;
    $currentStatusFilter = $filters['status'] ?? null;
    $studentFilterParameters = array_filter([
        'student_name' => $currentStudentFilter,
    ], static fn (mixed $value): bool => $value !== null && $value !== '');
    $persistParameters = array_merge($filterParameters, $pageParameters ?? []);
    $exportUrl = route('lti.ai-grader.course-export', array_merge($ltiParameters, $filterParameters));
    $statusCardClasses = [
        'success' => 'alert-success border-success-subtle bg-success-subtle text-success-emphasis',
        'warning' => 'alert-warning border-warning-subtle bg-warning-subtle text-warning-emphasis',
        'danger' => 'alert-danger border-danger-subtle bg-danger-subtle text-danger-emphasis',
        'primary' => 'alert-primary border-primary-subtle bg-primary-subtle text-primary-emphasis',
        'info' => 'alert-info border-info-subtle bg-info-subtle text-info-emphasis',
    ];
    $statusBadges = [
        \App\Models\AiGraderCorrectionItem::STATUS_PENDING => [
            'class' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
            'label' => __('ai-grader.correction_statuses.pending'),
            'icon' => 'spinner-border spinner-border-sm',
        ],
        \App\Models\AiGraderCorrectionItem::STATUS_PROCESSING => [
            'class' => 'bg-info-subtle text-info-emphasis border border-info-subtle',
            'label' => __('ai-grader.correction_statuses.processing'),
            'icon' => 'spinner-border spinner-border-sm',
        ],
        \App\Models\AiGraderCorrectionItem::STATUS_AI_CORRECTED => [
            'class' => 'bg-info-subtle text-info-emphasis border border-info-subtle',
            'label' => __('ai-grader.correction_statuses.ai_corrected'),
            'icon' => null,
        ],
        \App\Models\AiGraderCorrectionItem::STATUS_PENDING_TEACHER_APPROVAL => [
            'class' => 'bg-primary-subtle text-primary-emphasis border border-primary-subtle',
            'label' => __('ai-grader.correction_statuses.pending_teacher_approval'),
            'icon' => null,
        ],
        \App\Models\AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS => [
            'class' => 'bg-success-subtle text-success-emphasis border border-success-subtle',
            'label' => __('ai-grader.correction_statuses.published_to_canvas'),
            'icon' => null,
        ],
        \App\Models\AiGraderCorrectionItem::STATUS_FAILED => [
            'class' => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
            'label' => __('ai-grader.correction_statuses.failed'),
            'icon' => null,
        ],
        \App\Models\AiGraderCorrectionItem::STATUS_PUBLICATION_FAILED => [
            'class' => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
            'label' => __('ai-grader.correction_statuses.publication_failed'),
            'icon' => null,
        ],
        \App\Models\AiGraderCorrectionItem::STATUS_SKIPPED_BLANK_ANSWER => [
            'class' => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
            'label' => __('ai-grader.correction_statuses.skipped_blank_answer'),
            'icon' => null,
        ],
    ];
    $metrics = [
        [
            'label' => __('ai-grader.lti.course.metrics.total'),
            'value' => $summary['total'],
            'status' => null,
            'active' => $currentStatusFilter === null,
        ],
        [
            'label' => __('ai-grader.lti.course.metrics.pending'),
            'value' => $summary['pending'],
            'status' => 'pending',
            'active' => $currentStatusFilter === 'pending',
        ],
        [
            'label' => __('ai-grader.lti.course.metrics.ai_corrected'),
            'value' => $summary['ai_corrected'],
            'status' => 'ai_corrected',
            'active' => $currentStatusFilter === 'ai_corrected',
        ],
        [
            'label' => __('ai-grader.lti.course.metrics.pending_teacher_approval'),
            'value' => $summary['pending_teacher_approval'],
            'status' => 'pending_teacher_approval',
            'active' => $currentStatusFilter === 'pending_teacher_approval',
        ],
        [
            'label' => __('ai-grader.lti.course.metrics.published_to_canvas'),
            'value' => $summary['published_to_canvas'],
            'status' => 'published_to_canvas',
            'active' => $currentStatusFilter === 'published_to_canvas',
        ],
        [
            'label' => __('ai-grader.lti.course.metrics.failed'),
            'value' => $summary['failed'],
            'status' => 'failed',
            'active' => $currentStatusFilter === 'failed',
        ],
        [
            'label' => __('ai-grader.lti.course.metrics.skipped_blank_answer'),
            'value' => $summary['skipped_blank_answer'],
            'status' => 'skipped_blank_answer',
            'active' => $currentStatusFilter === 'skipped_blank_answer',
        ],
    ];
@endphp

<div id="ai-grader-course-status"
    hx-get="{{ $pollUrl ?? $statusUrl }}"
    hx-trigger="{{ $shouldPoll ? 'load, every 5s' : 'none' }}"
    hx-swap="outerHTML"
    hx-indicator="#ai-grader-status-indicator"
    data-page-url="{{ $pageUrl }}">
    @if (session('success'))
        <div class="alert alert-success border-success-subtle bg-success-subtle text-success-emphasis">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger border-danger-subtle bg-danger-subtle text-danger-emphasis">
            {{ session('error') }}
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h2 class="h5 mb-1">{{ __('ai-grader.lti.course.refresh_title') }}</h2>
                <div class="text-muted small">{{ __('ai-grader.lti.course.refresh_subtitle') }}</div>
                @if ($items->total() > 0)
                    <div class="text-muted small mt-1">
                        {{ __('ai-grader.lti.course.last_submission') }}
                        {{ $formatDateTime($items->first()?->created_at) }}
                    </div>
                @endif
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2">
                <span id="ai-grader-status-indicator" class="htmx-indicator text-muted small">
                    <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                    {{ __('ai-grader.lti.refreshing_status') }}
                </span>

                <span id="ai-grader-refresh-indicator" class="htmx-indicator text-muted small">
                    <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                    {{ __('ai-grader.lti.course.refresh_loading') }}
                </span>

                <button type="button"
                    class="btn btn-primary"
                    hx-post="{{ $forceRefreshUrl }}"
                    hx-target="#ai-grader-course-status"
                    hx-swap="outerHTML"
                    hx-indicator="#ai-grader-refresh-indicator"
                    hx-disabled-elt="this">
                    {{ __('ai-grader.lti.course.refresh_button') }}
                </button>
            </div>
        </div>
    </div>

    @if ($isCheckingOnly)
        <div class="d-flex align-items-center gap-2 text-muted small mb-4">
            <span class="badge border text-muted fw-semibold">{{ $processingDispatch['job_label'] ?? __('ai-grader.lti.job_checking_label') }}</span>
            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            <span>{{ $processingDispatch['checking_message'] ?? __('ai-grader.lti.checking_new_submissions') }}</span>
        </div>
    @endif

    @if ($statusCards !== [])
        <div class="d-grid gap-3 mb-4">
            @foreach ($statusCards as $card)
                @php
                    $cardTone = $card['tone'] ?? 'info';
                    $cardClass = $statusCardClasses[$cardTone] ?? $statusCardClasses['info'];
                    $statusFilterUrl = isset($card['status_filter'])
                        ? route('lti.ai-grader.course-status', array_merge($ltiParameters, $filterParameters, ['status' => $card['status_filter']]))
                        : null;
                @endphp
                <div class="alert {{ $cardClass }} mb-0">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div>
                            <div class="fw-semibold">{{ $card['title'] ?? __('ai-grader.lti.course.processing_title') }}</div>
                            <div>{{ $card['message'] ?? '' }}</div>
                            @if (($card['count'] ?? 0) > 0 && !empty($card['auto_refresh']))
                                <div class="small mt-1">
                                    {{ __('ai-grader.lti.course.pending_answers', ['count' => $card['count']]) }}
                                </div>
                            @endif
                            @if (!empty($card['auto_refresh']))
                                <div class="small text-muted mt-1">{{ __('ai-grader.lti.course.processing_auto_refresh') }}</div>
                            @endif
                            @if (!empty($processingDispatch['mapped_message']) && $loop->first)
                                <div class="small mt-1">{{ $processingDispatch['mapped_message'] }}</div>
                            @endif
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            @if (!empty($card['status_filter']) && $statusFilterUrl)
                                <a class="btn btn-sm btn-outline-primary"
                                    href="{{ $statusFilterUrl }}"
                                    hx-get="{{ $statusFilterUrl }}"
                                    hx-target="#ai-grader-course-status"
                                    hx-swap="outerHTML">
                                    {{ __('ai-grader.common.details') }}
                                </a>
                            @endif

                            @if (!empty($card['retry_action']) && ($retryFailedCount ?? 0) > 0)
                                <form method="POST"
                                    action="{{ route('lti.ai-grader.retry-failed') }}"
                                    hx-post="{{ route('lti.ai-grader.retry-failed') }}"
                                    hx-target="#ai-grader-course-status"
                                    hx-swap="outerHTML">
                                    @csrf
                                    @include('lti.ai-grader._context-fields', ['context' => $context])
                                    @foreach ($persistParameters as $key => $value)
                                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                    @endforeach
                                    <button type="submit" class="btn btn-sm btn-outline-warning">
                                        {{ __('ai-grader.lti.retry_failed_button', ['count' => $retryFailedCount]) }}
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="row">
        @foreach ($metrics as $metric)
            @php
                $cardParameters = array_merge(
                    $ltiParameters,
                    $studentFilterParameters,
                    $metric['status'] !== null ? ['status' => $metric['status']] : [],
                );
                $cardUrl = route('lti.ai-grader.course-status', $cardParameters);
            @endphp
            <div class="col-md-3 mb-3">
                <a href="{{ $cardUrl }}"
                    class="text-decoration-none text-reset d-block"
                    hx-get="{{ $cardUrl }}"
                    hx-target="#ai-grader-course-status"
                    hx-swap="outerHTML">
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
            <form method="GET"
                action="{{ $statusUrl }}"
                hx-get="{{ $statusUrl }}"
                hx-target="#ai-grader-course-status"
                hx-swap="outerHTML"
                class="row g-3 align-items-end">
                @foreach ($ltiParameters as $key => $value)
                    @if (is_array($value))
                        @foreach ($value as $item)
                            <input type="hidden" name="{{ $key }}[]" value="{{ $item }}">
                        @endforeach
                    @else
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach

                <div class="col-md-5">
                    <label for="student_name" class="form-label">{{ __('ai-grader.lti.course.filters.student') }}</label>
                    <input type="text"
                        id="student_name"
                        name="student_name"
                        class="form-control"
                        value="{{ $currentStudentFilter }}"
                        placeholder="{{ __('ai-grader.lti.course.filters.student_placeholder') }}">
                </div>

                <div class="col-md-4">
                    <label for="status" class="form-label">{{ __('ai-grader.lti.course.filters.status') }}</label>
                    <select id="status" name="status" class="form-select">
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($currentStatusFilter === ($value !== '' ? $value : null))>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">{{ __('ai-grader.common.filter') }}</button>
                    <a class="btn btn-light flex-fill"
                        href="{{ route('lti.ai-grader.course-status', $ltiParameters) }}"
                        hx-get="{{ route('lti.ai-grader.course-status', $ltiParameters) }}"
                        hx-target="#ai-grader-course-status"
                        hx-swap="outerHTML">{{ __('ai-grader.common.clear') }}</a>
                </div>
            </form>

            @if ($activeFilterLabel)
                <div class="small text-muted mt-3">{{ $activeFilterLabel }}</div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                <h2 class="h5 mb-0">{{ __('ai-grader.lti.course.table.title') }}</h2>
                <div class="d-flex flex-wrap gap-2">
                    @if (($retryFailedCount ?? 0) > 0)
                        <form method="POST"
                            action="{{ route('lti.ai-grader.retry-failed') }}"
                            hx-post="{{ route('lti.ai-grader.retry-failed') }}"
                            hx-target="#ai-grader-course-status"
                            hx-swap="outerHTML">
                            @csrf
                            @include('lti.ai-grader._context-fields', ['context' => $context])
                            @foreach ($persistParameters as $key => $value)
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endforeach
                            <button type="submit" class="btn btn-outline-warning">
                                {{ __('ai-grader.lti.retry_failed_button', ['count' => $retryFailedCount]) }}
                            </button>
                        </form>
                    @endif

                    <a class="btn btn-outline-secondary" href="{{ $exportUrl }}">
                        {{ __('ai-grader.common.export_csv') }}
                    </a>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('ai-grader.lti.course.table.student') }}</th>
                            <th>{{ __('ai-grader.lti.course.table.quiz') }}</th>
                            <th>{{ __('ai-grader.lti.course.table.question') }}</th>
                            <th>{{ __('ai-grader.lti.course.table.attempt') }}</th>
                            <th>{{ __('ai-grader.lti.course.table.status') }}</th>
                            <th>{{ __('ai-grader.lti.course.table.ai_score') }}</th>
                            <th>{{ __('ai-grader.lti.course.table.canvas_score') }}</th>
                            <th>{{ __('ai-grader.lti.course.table.points') }}</th>
                            <th>{{ __('ai-grader.lti.course.table.published_at') }}</th>
                            <th>{{ __('ai-grader.lti.course.table.created_at') }}</th>
                            <th>{{ __('ai-grader.lti.course.table.action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            @php
                                $badge = $statusBadges[$item->status] ?? [
                                    'class' => 'bg-light text-dark border',
                                    'label' => __('ai-grader.correction_statuses.' . $item->status),
                                    'icon' => null,
                                ];
                            @endphp
                            <tr @class([
                                'table-warning' => $item->status === \App\Models\AiGraderCorrectionItem::STATUS_PENDING,
                                'table-danger' => in_array(
                                    $item->status,
                                    [
                                        \App\Models\AiGraderCorrectionItem::STATUS_FAILED,
                                        \App\Models\AiGraderCorrectionItem::STATUS_PUBLICATION_FAILED,
                                    ],
                                    true,
                                ),
                            ])>
                                <td>
                                    <div>{{ $item->canvas_user_name ?: '-' }}</div>
                                    <div class="text-muted small">{{ $item->canvas_user_id }}</div>
                                </td>
                                <td>{{ $item->quizConfig?->canvas_quiz_title ?: $item->canvas_quiz_id }}</td>
                                <td>{{ $item->canvas_question_name ?: $item->canvas_question_id }}</td>
                                <td>{{ $item->attempt ?: '-' }}</td>
                                <td>
                                    <span class="badge {{ $badge['class'] }}">
                                        @if ($badge['icon'])
                                            <span class="{{ $badge['icon'] }} me-1" role="status" aria-hidden="true"></span>
                                        @endif
                                        {{ $badge['label'] }}
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
                                                $ltiParameters,
                                            )) }}">
                                            {{ __('ai-grader.common.details') }}
                                        </a>

                                        @if ($item->canRetry())
                                            <form method="POST"
                                                action="{{ route('lti.ai-grader.correction-items.retry', ['correctionItem' => $item]) }}"
                                                hx-post="{{ route('lti.ai-grader.correction-items.retry', ['correctionItem' => $item]) }}"
                                                hx-target="#ai-grader-course-status"
                                                hx-swap="outerHTML">
                                                @csrf
                                                @include('lti.ai-grader._context-fields', ['context' => $context])
                                                @foreach ($persistParameters as $key => $value)
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
                                <td colspan="11" class="text-center text-muted py-4">
                                    {{ __('ai-grader.lti.course.table.empty') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($items->hasPages())
                <div class="mt-4"
                    hx-boost="true"
                    hx-target="#ai-grader-course-status"
                    hx-select="#ai-grader-course-status"
                    hx-swap="outerHTML">
                    {{ $items->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
