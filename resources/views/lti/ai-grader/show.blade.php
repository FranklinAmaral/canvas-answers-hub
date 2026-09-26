@extends('layouts.lti')

@section('title', __('ai-grader.lti.titles.detail'))

@section('content')
    <style>
        .graderai-detail-grid,
        .graderai-rubric-summary,
        .graderai-criterion-stats,
        .graderai-review-meta {
            display: grid;
            gap: 12px;
        }

        .graderai-detail-grid {
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        }

        .graderai-rubric-summary,
        .graderai-criterion-stats,
        .graderai-review-meta {
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        }

        .graderai-summary-card,
        .graderai-detail-card,
        .graderai-inline-summary,
        .graderai-criterion-card {
            border: 1px solid #dbe3ef;
            border-radius: 16px;
            background: #fff;
        }

        .graderai-summary-card,
        .graderai-detail-card,
        .graderai-inline-summary {
            padding: 16px 18px;
        }

        .graderai-header-meta {
            color: #6c757d;
            font-size: .95rem;
        }

        .graderai-card-label {
            display: block;
            color: #6c757d;
            font-size: 12px;
            margin-bottom: 6px;
        }

        .graderai-card-line {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .graderai-card-line + .graderai-card-line {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #eef3f9;
        }

        .graderai-card-line-value {
            font-weight: 600;
            text-align: right;
        }

        .graderai-disclosure {
            border: 1px solid #dbe3ef;
            border-radius: 16px;
            background: #f8fbff;
            padding: 14px 16px;
        }

        .graderai-disclosure summary {
            cursor: pointer;
            font-weight: 600;
            list-style: none;
        }

        .graderai-disclosure summary::-webkit-details-marker {
            display: none;
        }

        .graderai-criterion-card {
            padding: 20px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
        }

        .graderai-criterion-stat {
            background: #f8fafc;
            border: 1px solid #e5edf8;
            border-radius: 14px;
            padding: 10px 12px;
        }

        .graderai-rating-button {
            width: min(100%, 230px);
            text-align: left;
            border: 1px solid #dbe3ef;
            border-radius: 14px;
            background: #f8fafc;
            color: #212529;
            padding: 12px 14px;
            transition: border-color .2s ease, background-color .2s ease, box-shadow .2s ease, transform .2s ease;
        }

        .graderai-rating-button:hover:not(:disabled),
        .graderai-rating-button:focus-visible:not(:disabled) {
            border-color: #0d6efd;
            background: #eef4ff;
            box-shadow: 0 10px 24px rgba(13, 110, 253, .08);
            transform: translateY(-1px);
        }

        .graderai-rating-button.is-selected {
            border-color: #0d6efd;
            background: #eef4ff;
            box-shadow: 0 0 0 1px rgba(13, 110, 253, .12);
        }

        .graderai-feedback-panel {
            margin-top: 12px;
            border: 1px dashed #c8d7ea;
            border-radius: 14px;
            background: #f8fafc;
            padding: 14px;
        }

        .graderai-linked-row {
            color: inherit;
            text-decoration: none;
            display: block;
            border-radius: 10px;
            margin: -6px;
            padding: 6px;
        }

        .graderai-linked-row:hover {
            background: #f8fbff;
        }

        .graderai-history-item + .graderai-history-item {
            margin-top: 10px;
        }

        @media (max-width: 767.98px) {
            .graderai-card-line {
                display: block;
            }

            .graderai-card-line-value {
                text-align: left;
                margin-top: 2px;
            }

            .graderai-rating-button {
                width: 100%;
            }
        }
    </style>

    @php
        $viewHelper = \App\Support\AiGraderLtiView::class;
        $questionConfig = $correctionItem->questionConfig;
        $questionTitle =
            $correctionItem->canvas_question_name ?:
            data_get($questionConfig, 'canvas_question_name') ?:
            data_get($questionConfig, 'question_name') ?:
            $correctionItem->canvas_question_id ?:
            '-';
        $questionText =
            $correctionItem->canvas_question_text ?:
            data_get($questionConfig, 'canvas_question_text') ?:
            data_get($questionConfig, 'question_text');
        $questionComment = $correctionItem->ai_grading_instructions_snapshot ?: data_get($questionConfig, 'ai_grading_instructions');
        $pointsPossible = is_numeric($correctionItem->canvas_points_possible) ? (float) $correctionItem->canvas_points_possible : null;
        $usesCanvasRubric = $correctionItem->usesCanvasRubric();
        $rubricSnapshot = is_array($correctionItem->rubric_snapshot) ? $correctionItem->rubric_snapshot : [];
        $aiRubricFeedback = is_array($correctionItem->ai_rubric_feedback) ? $correctionItem->ai_rubric_feedback : [];
        $teacherRubricFeedback = is_array($correctionItem->teacher_rubric_feedback) ? $correctionItem->teacher_rubric_feedback : [];
        $publishedRubricFeedback = is_array($correctionItem->published_rubric_feedback) ? $correctionItem->published_rubric_feedback : [];
        $oldRubricFeedback = old('teacher_rubric_feedback');
        $hasOldRubricFeedback = is_array($oldRubricFeedback);
        $hasTeacherRubricFeedback = $teacherRubricFeedback !== [];
        $normalizedRoles = collect($context['roles'] ?? [])
            ->filter(fn (mixed $role): bool => is_scalar($role) && trim((string) $role) !== '')
            ->map(fn (mixed $role): string => mb_strtolower(trim((string) $role)))
            ->values();
        $isAdminViewer = $normalizedRoles->contains(fn (string $role): bool => str_contains($role, 'admin') || str_contains($role, 'administrator') || str_contains($role, 'accountadmin'));
        $canViewPrompt = $isAdminViewer || (bool) ($correctionItem->blueprintConfig?->teacher_can_view_grading_prompt ?? false);
        $canReview = ($canReviewActions ?? false)
            && in_array($correctionItem->status, \App\Models\AiGraderCorrectionItem::reviewableStatuses(), true);
        $canPublish = $canReview && ($canPublishAction ?? false);
        $publishLabel = $correctionItem->status === \App\Models\AiGraderCorrectionItem::STATUS_PUBLISHED_TO_CANVAS
            ? __('ai-grader.lti.show.republish')
            : __('ai-grader.lti.show.publish');
        $canvasBaseUrl = $correctionItem->canvasEnvironment?->base_url
            ? rtrim((string) $correctionItem->canvasEnvironment->base_url, '/')
            : null;
        $canvasCourseId = $correctionItem->child_course_id;
        $canvasQuizId = $correctionItem->canvas_quiz_id;
        $quizSubmissionId = $correctionItem->canvas_quiz_submission_id ?? ($correctionItem->quiz_submission_id ?? null);
        $canvasSubmissionUrl = null;

        if ($canvasBaseUrl && $canvasCourseId && $canvasQuizId && $quizSubmissionId) {
            $canvasSubmissionUrl = "{$canvasBaseUrl}/courses/{$canvasCourseId}/quizzes/{$canvasQuizId}/history?quiz_submission_id={$quizSubmissionId}";
        }

        $numeric = function (mixed $value): ?float {
            return is_numeric($value) ? (float) $value : null;
        };

        $formatNumber = function (mixed $value): string {
            if (! is_numeric($value)) {
                return '-';
            }

            return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        };

        $formatDateTime = static fn (mixed $value): string => $viewHelper::formatDateTime($value);

        $criterionKey = function (array $criterion) use ($viewHelper): string {
            $key = $criterion['criterion_id']
                ?? $criterion['id']
                ?? $criterion['criterion_name']
                ?? $criterion['name']
                ?? null;

            if (is_scalar($key) && trim((string) $key) !== '') {
                $stringKey = trim((string) $key);

                return is_numeric($stringKey)
                    ? $stringKey
                    : ($viewHelper::normalizeRubricLabel($stringKey) ?? $stringKey);
            }

            return md5((string) json_encode($criterion));
        };

        $mapCriteria = function (array $criteria) use ($criterionKey): array {
            $mapped = [];

            foreach ($criteria as $criterion) {
                if (! is_array($criterion)) {
                    continue;
                }

                $mapped[$criterionKey($criterion)] = $criterion;
            }

            return $mapped;
        };

        $aiFeedbackByCriterion = $mapCriteria($aiRubricFeedback);
        $teacherFeedbackByCriterion = $mapCriteria($teacherRubricFeedback);
        $selectedFeedbackByCriterion = $hasOldRubricFeedback
            ? $mapCriteria($oldRubricFeedback)
            : ($hasTeacherRubricFeedback ? $teacherFeedbackByCriterion : $aiFeedbackByCriterion);

        $rubricCriteriaBlocks = [];
        $snapshotCriteriaLookup = [];
        $rubricTotalPercentage = 0.0;
        $hasRubricTotal = false;
        $snapshotCriteria = is_array($rubricSnapshot['criteria'] ?? null) ? $rubricSnapshot['criteria'] : [];

        foreach ($snapshotCriteria as $criterion) {
            if (! is_array($criterion)) {
                continue;
            }

            $key = $criterionKey($criterion);
            $snapshotCriteriaLookup[$key] = $criterion;
            $ratings = is_array($criterion['ratings'] ?? null) ? $criterion['ratings'] : [];
            $aiCriterion = $aiFeedbackByCriterion[$key] ?? [];
            $selectedCriterion = $selectedFeedbackByCriterion[$key] ?? $aiCriterion;
            $teacherCriterion = $teacherFeedbackByCriterion[$key] ?? null;
            $maxPercentage = $numeric($criterion['max_percentage'] ?? null) ?? $numeric($aiCriterion['max_percentage'] ?? null);
            $aiPercentage = $numeric($aiCriterion['percentage'] ?? null);
            $selectedPercentage = $numeric($selectedCriterion['percentage'] ?? null);
            $aiRating = $viewHelper::findMatchingRubricRating($ratings, $aiCriterion['selected_rating'] ?? null, $aiPercentage);
            $selectedRating = $viewHelper::findMatchingRubricRating($ratings, $selectedCriterion['selected_rating'] ?? null, $selectedPercentage);
            $aiRatingName = trim((string) (($aiCriterion['selected_rating'] ?? null) ?: ($aiRating['description'] ?? $aiRating['id'] ?? '')));
            $selectedRatingName = trim((string) (($selectedCriterion['selected_rating'] ?? null) ?: ($selectedRating['description'] ?? $selectedRating['id'] ?? '')));

            if ($maxPercentage !== null) {
                $rubricTotalPercentage += $maxPercentage;
                $hasRubricTotal = true;
            }

            $rubricCriteriaBlocks[] = [
                'criterion_id' => $criterion['id'] ?? ($aiCriterion['criterion_id'] ?? null),
                'name' => $criterion['name'] ?? $criterion['description'] ?? ($aiCriterion['criterion_name'] ?? ($aiCriterion['criterion_id'] ?? '-')),
                'description' => $criterion['long_description'] ?? $criterion['description'] ?? null,
                'max_percentage' => $maxPercentage,
                'max_points' => $numeric($criterion['max_points'] ?? null) ?? $numeric($aiCriterion['max_points'] ?? null),
                'ratings' => $ratings,
                'ai_percentage' => $aiPercentage,
                'selected_percentage' => $selectedPercentage,
                'ai_rating_name' => $aiRatingName !== '' ? $aiRatingName : ($aiPercentage !== null ? $formatNumber($aiPercentage) . '%' : __('ai-grader.common.not_available')),
                'selected_rating_name' => $selectedRatingName !== '' ? $selectedRatingName : ($selectedPercentage !== null ? $formatNumber($selectedPercentage) . '%' : __('ai-grader.common.not_available')),
                'selection_changed' => $viewHelper::rubricSelectionChanged($selectedRatingName, $aiRatingName, $selectedPercentage, $aiPercentage),
                'criterion_score_points' => $viewHelper::criterionProportionalScore($selectedPercentage, $pointsPossible),
                'ai_feedback' => trim((string) ($aiCriterion['feedback'] ?? '')),
                'current_feedback' => trim((string) ($selectedCriterion['feedback'] ?? ($aiCriterion['feedback'] ?? ''))),
                'show_feedback_editor' => $canReview && ($hasOldRubricFeedback || $teacherCriterion !== null),
            ];
        }

        if (! $hasRubricTotal && $rubricCriteriaBlocks !== []) {
            $rubricTotalPercentage = 100.0;
        }

        $weightedSelection = 0.0;
        $hasWeightedSelection = false;

        foreach ($rubricCriteriaBlocks as $criterion) {
            $criterionPercentage = $numeric($criterion['selected_percentage'] ?? null);
            $criterionMaxPercentage = $numeric($criterion['max_percentage'] ?? null);

            if ($criterionPercentage === null || $criterionMaxPercentage === null) {
                continue;
            }

            $hasWeightedSelection = true;
            $weightedSelection += ($criterionPercentage * $criterionMaxPercentage) / 100;
        }

        $selectedRubricPercentage = $hasWeightedSelection
            ? round($weightedSelection, 2)
            : (is_numeric($correctionItem->final_score ?? $correctionItem->ai_score) && $pointsPossible && $pointsPossible > 0
                ? round(((float) ($correctionItem->final_score ?? $correctionItem->ai_score) / $pointsPossible) * 100, 2)
                : null);
        $aiRubricPercentage = is_numeric($correctionItem->ai_rubric_percentage)
            ? (float) $correctionItem->ai_rubric_percentage
            : (is_numeric($correctionItem->ai_score) && $pointsPossible && $pointsPossible > 0
                ? round(((float) $correctionItem->ai_score / $pointsPossible) * 100, 2)
                : null);
        $selectedConvertedScore = $selectedRubricPercentage !== null && $pointsPossible !== null
            ? round(($selectedRubricPercentage / 100) * $pointsPossible, 2)
            : null;
        $editableScore = old('final_score');

        if ($editableScore === null || $editableScore === '') {
            $editableScore = $usesCanvasRubric && $selectedConvertedScore !== null
                ? $selectedConvertedScore
                : ($correctionItem->final_score ?? $correctionItem->ai_score);
        }

        $editableFeedback = old('final_feedback', $correctionItem->final_feedback ?? $correctionItem->ai_feedback);
        $publishedRubricCards = [];

        foreach ($publishedRubricFeedback as $criterion) {
            if (! is_array($criterion)) {
                continue;
            }

            $key = $criterionKey($criterion);
            $snapshotCriterion = $snapshotCriteriaLookup[$key] ?? null;
            $ratings = is_array($snapshotCriterion['ratings'] ?? null) ? $snapshotCriterion['ratings'] : [];
            $percentage = $numeric($criterion['percentage'] ?? null);
            $selectedRating = $viewHelper::findMatchingRubricRating($ratings, $criterion['selected_rating'] ?? null, $percentage);
            $selectedRatingName = trim((string) (($criterion['selected_rating'] ?? null) ?: ($selectedRating['description'] ?? $selectedRating['id'] ?? '')));
            $publishedRubricCards[] = [
                'criterion' => $criterion['criterion_name'] ?? ($criterion['criterion_id'] ?? '-'),
                'domain' => $selectedRatingName !== '' ? $selectedRatingName : __('ai-grader.common.not_available'),
                'score' => $numeric($criterion['proportional_score'] ?? null) ?? $viewHelper::criterionProportionalScore($percentage, $pointsPossible),
                'feedback' => trim((string) ($criterion['feedback'] ?? '')),
            ];
        }
    @endphp

    @include('lti.ai-grader._show-status')

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
        <div class="card-body">
            <h2 class="h5 mb-3">{{ __('ai-grader.lti.show.question_title') }}</h2>

            <div class="row g-3 mb-3">
                <div class="col-md-8">
                    <div class="text-muted small">{{ __('ai-grader.lti.show.title_or_identifier') }}</div>
                    <div class="fw-semibold">{{ $questionTitle }}</div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">{{ __('ai-grader.lti.show.max_points') }}</div>
                    <div>{{ $pointsPossible !== null ? $formatNumber($pointsPossible) : '-' }}</div>
                </div>
            </div>

            <div class="mb-3">
                <div class="text-muted small mb-2">{{ __('ai-grader.lti.show.prompt') }}</div>
                @if ($questionText)
                    <div class="bg-light border rounded p-3">{!! $questionText !!}</div>
                @else
                    <div class="bg-light border rounded p-3 text-muted">{{ __('ai-grader.lti.show.prompt_unavailable') }}</div>
                @endif
            </div>

            @if ($questionComment && $canViewPrompt)
                <details class="graderai-disclosure">
                    <summary>{{ __('ai-grader.lti.show.view_grading_guidelines') }}</summary>
                    <pre class="bg-white border rounded p-3 mb-0 mt-3 text-wrap" style="white-space: pre-wrap;">{{ $questionComment }}</pre>
                </details>
            @endif
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5 mb-3">{{ __('ai-grader.lti.show.student_answer') }}</h2>
            <pre class="bg-light border rounded p-3 mb-0 text-wrap" style="white-space: pre-wrap;">{{ $correctionItem->answer_text ?: '-' }}</pre>
        </div>
    </div>

    @if ($canReview)
        <form method="POST" action="{{ route('lti.ai-grader.review.save-draft', ['correctionItem' => $correctionItem]) }}">
            @csrf
            @include('lti.ai-grader._context-fields', ['context' => $context])
    @endif

    @if ($usesCanvasRubric)
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h5 mb-3">{{ __('ai-grader.lti.show.rubric_evaluation') }}</h2>
                @include('lti.ai-grader._rubric-applied', [
                    'criteriaBlocks' => $rubricCriteriaBlocks,
                    'rubricTitle' => $correctionItem->rubric_title ?: ($rubricSnapshot['title'] ?? null),
                    'criteriaCount' => count($rubricCriteriaBlocks),
                    'rubricTotalPercentage' => $rubricTotalPercentage,
                    'currentRubricPercentage' => $selectedRubricPercentage,
                    'convertedScore' => $selectedConvertedScore,
                    'pointsPossible' => $pointsPossible,
                    'formatNumber' => $formatNumber,
                    'interactive' => $canReview,
                ])
            </div>
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5 mb-3">{{ __('ai-grader.lti.show.ai_suggestion') }}</h2>
            <div class="row g-3 mb-3">
                @if (! $usesCanvasRubric)
                    <div class="col-md-12">
                        <div class="graderai-inline-summary h-100">
                            <div class="text-muted small">{{ __('ai-grader.lti.show.ai_suggested_score') }}</div>
                            <div class="fw-semibold">
                                @if (is_numeric($correctionItem->ai_score) && $pointsPossible !== null)
                                    {{ $formatNumber($correctionItem->ai_score) }} / {{ $formatNumber($pointsPossible) }}
                                @else
                                    {{ is_numeric($correctionItem->ai_score) ? $formatNumber($correctionItem->ai_score) : '-' }}
                                @endif
                            </div>
                        </div>
                    </div>
                @else
                    <div class="col-md-6">
                        <div class="graderai-inline-summary h-100">
                            <div class="text-muted small">{{ __('ai-grader.lti.show.rubric_result') }}</div>
                            <div class="fw-semibold">
                                @if ($aiRubricPercentage !== null)
                                    {{ $formatNumber($aiRubricPercentage) }}% / {{ $formatNumber($rubricTotalPercentage) }}%
                                @else
                                    -
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="graderai-inline-summary h-100">
                            <div class="text-muted small">{{ __('ai-grader.lti.show.final_score') }}</div>
                            <div class="fw-semibold">
                                @if ($selectedConvertedScore !== null && $pointsPossible !== null)
                                    {{ $formatNumber($selectedConvertedScore) }} / {{ $formatNumber($pointsPossible) }}
                                @else
                                    -
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <pre class="bg-light border rounded p-3 mb-0 text-wrap" style="white-space: pre-wrap;">{{ $correctionItem->ai_feedback ?: '-' }}</pre>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5 mb-3">{{ __('ai-grader.lti.show.teacher_review') }}</h2>

            @if ($canReview)
                @if ($usesCanvasRubric)
                    <div class="graderai-review-meta mb-4">
                        <div class="graderai-inline-summary">
                            <div class="text-muted small">{{ __('ai-grader.lti.show.rubric_result') }}</div>
                            <div class="fw-semibold" data-rubric-result-output>
                                @if ($selectedRubricPercentage !== null)
                                    {{ $formatNumber($selectedRubricPercentage) }}% / {{ $formatNumber($rubricTotalPercentage) }}%
                                @else
                                    -
                                @endif
                            </div>
                        </div>
                        <div class="graderai-inline-summary">
                            <div class="text-muted small">{{ __('ai-grader.lti.show.final_converted_score') }}</div>
                            <div class="fw-semibold" data-rubric-converted-score-output>
                                @if ($selectedConvertedScore !== null && $pointsPossible !== null)
                                    {{ $formatNumber($selectedConvertedScore) }} / {{ $formatNumber($pointsPossible) }}
                                @else
                                    -
                                @endif
                            </div>
                        </div>
                        <div class="graderai-inline-summary">
                            <div class="text-muted small">{{ __('ai-grader.lti.show.last_review') }}</div>
                            <div class="fw-semibold">{{ $formatDateTime($correctionItem->reviewed_at) }}</div>
                        </div>
                        <div class="graderai-inline-summary">
                            <div class="text-muted small">{{ __('ai-grader.lti.show.teacher') }}</div>
                            <div class="fw-semibold">{{ $correctionItem->reviewer_name ?: ($correctionItem->reviewer_login_id ?: '-') }}</div>
                        </div>
                    </div>
                @endif

                <div class="row g-4">
                    <div class="col-md-4">
                        <label for="final_score" class="form-label">{{ __('ai-grader.lti.show.final_score') }}</label>
                        <input type="number"
                            step="0.01"
                            min="0"
                            max="{{ $pointsPossible !== null ? (float) $pointsPossible : 0 }}"
                            class="form-control @error('final_score') is-invalid @enderror"
                            id="final_score"
                            name="final_score"
                            value="{{ $editableScore }}">
                        @error('final_score')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12">
                        <label for="final_feedback" class="form-label">{{ __('ai-grader.lti.show.final_feedback') }}</label>
                        <textarea class="form-control @error('final_feedback') is-invalid @enderror"
                            id="final_feedback"
                            name="final_feedback"
                            rows="7">{{ $editableFeedback }}</textarea>
                        @error('final_feedback')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12">
                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-outline-primary">{{ __('ai-grader.lti.show.save_draft') }}</button>
                            @if ($canPublish)
                                <button type="submit"
                                    class="btn btn-primary"
                                    formaction="{{ route('lti.ai-grader.review.publish', ['correctionItem' => $correctionItem]) }}">
                                    {{ $publishLabel }}
                                </button>
                            @endif
                            @if ($canvasSubmissionUrl)
                                <a class="btn btn-light" href="{{ $canvasSubmissionUrl }}" target="_blank" rel="noopener noreferrer">
                                    {{ __('ai-grader.lti.show.open_canvas') }}
                                </a>
                            @endif
                            <a class="btn btn-light"
                                href="{{ route('lti.ai-grader.index') }}">{{ __('ai-grader.common.back') }}</a>
                        </div>
                    </div>
                </div>
            @else
                <div class="text-muted mb-3">{{ __('ai-grader.lti.show.review_unavailable') }}</div>
                <div class="d-flex flex-wrap gap-2">
                    @if ($canvasSubmissionUrl)
                        <a class="btn btn-light" href="{{ $canvasSubmissionUrl }}" target="_blank" rel="noopener noreferrer">
                            {{ __('ai-grader.lti.show.open_canvas') }}
                        </a>
                    @endif
                </div>
            @endif
        </div>
    </div>

    @if ($canReview)
        </form>
    @endif

    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">{{ __('ai-grader.lti.show.publication_history') }}</h2>
                    <div class="mb-3">
                        <div class="text-muted small">{{ __('ai-grader.lti.show.published_feedback') }}</div>
                        <pre class="bg-light border rounded p-3 mb-0 text-wrap" style="white-space: pre-wrap;">{{ $correctionItem->canvas_published_feedback ?: '-' }}</pre>
                    </div>

                    @if ($usesCanvasRubric && $publishedRubricCards !== [])
                        <div class="mb-3">
                            <div class="text-muted small mb-2">{{ __('ai-grader.lti.show.rubric_criteria_analysis') }}</div>
                            <div class="vstack gap-2">
                                @foreach ($publishedRubricCards as $criterion)
                                    <div class="border rounded p-3 bg-light graderai-history-item">
                                        <div class="fw-semibold">
                                            {{ $criterion['criterion'] }} — {{ $criterion['domain'] }} — {{ __('ai-grader.lti.show.criterion_score_label', ['value' => is_numeric($criterion['score']) ? $formatNumber($criterion['score']) : '-']) }}
                                        </div>
                                        @if ($criterion['feedback'] !== '')
                                            <div class="small text-muted mt-1">{{ $criterion['feedback'] }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($correctionItem->canvas_publication_error)
                        <div class="alert alert-danger mb-3">
                            <strong>{{ __('ai-grader.lti.show.publication_error') }}</strong> {{ $correctionItem->canvas_publication_error }}
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('ai-grader.lti.show.date') }}</th>
                                    <th>{{ __('ai-grader.lti.show.score') }}</th>
                                    <th>{{ __('ai-grader.lti.show.http_status') }}</th>
                                    <th>{{ __('ai-grader.lti.show.result') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($correctionItem->publicationLogs->sortByDesc('created_at') as $log)
                                    <tr>
                                        <td>{{ $formatDateTime($log->created_at) }}</td>
                                        <td>{{ $log->published_score ?? '-' }}</td>
                                        <td>{{ $log->response_status ?? '-' }}</td>
                                        <td>{{ $log->success ? __('ai-grader.lti.show.success') : ($log->error_message ?: __('ai-grader.lti.show.failure_short')) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-muted">{{ __('ai-grader.lti.show.no_publications') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">{{ __('ai-grader.lti.show.human_review_history') }}</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('ai-grader.lti.show.date') }}</th>
                                    <th>{{ __('ai-grader.lti.show.action') }}</th>
                                    <th>{{ __('ai-grader.lti.show.teacher') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($correctionItem->teacherActions->sortByDesc('created_at') as $action)
                                    <tr>
                                        <td>{{ $formatDateTime($action->created_at) }}</td>
                                        <td>{{ $action->action }}</td>
                                        <td>{{ data_get($action->metadata, 'reviewer_name') ?: data_get($action->metadata, 'reviewer_login_id', '-') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-muted">{{ __('ai-grader.lti.show.no_human_reviews') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($correctionItem->failure_reason)
        <div class="alert alert-danger">
            <strong>{{ __('ai-grader.lti.show.failure') }}</strong> {{ $correctionItem->failure_reason }}
        </div>
    @endif

    @if ($canReview && $usesCanvasRubric)
        <script>
            (() => {
                const rubricEditor = document.querySelector('[data-rubric-editor="true"]');

                if (!rubricEditor) {
                    return;
                }

                const finalScoreInput = document.getElementById('final_score');
                const pointsPossible = Number.parseFloat(rubricEditor.dataset.pointsPossible || '');
                const aiSuggestedLabel = rubricEditor.dataset.aiSuggestedLabel || '';
                const teacherAdjustedLabel = rubricEditor.dataset.teacherAdjustedLabel || '';

                const formatNumber = (value) => {
                    if (!Number.isFinite(value)) {
                        return '-';
                    }

                    return value.toFixed(2).replace(/\.00$/, '').replace(/(\.\d*[1-9])0$/, '$1');
                };

                const normalizeLabel = (value) => {
                    const normalized = (value || '')
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '')
                        .toLowerCase()
                        .replace(/\s+/g, ' ')
                        .trim();

                    if (normalized === 'nao observada' || normalized === 'nao observado') {
                        return 'nao observado';
                    }

                    if (normalized === 'precisa de melhorar' || normalized === 'precisa de melhoria') {
                        return 'precisa melhorar';
                    }

                    return normalized;
                };

                const updateOutputs = (selector, value) => {
                    document.querySelectorAll(selector).forEach((node) => {
                        node.textContent = value;
                    });
                };

                const updateCriterionSelection = (card, button) => {
                    const percentageInput = card.querySelector('[data-percentage-input]');
                    const selectedRatingInput = card.querySelector('[data-selected-rating-input]');
                    const percentage = Number.parseFloat(button.dataset.percentage || '');
                    const label = button.dataset.ratingLabel || '-';
                    const scoreOutput = card.querySelector('[data-criterion-score-output]');
                    const domainLabelOutput = card.querySelector('[data-domain-label-output]');
                    const domainValueOutput = card.querySelector('[data-domain-value-output]');
                    const domainPercentageOutput = card.querySelector('[data-domain-percentage-output]');
                    const feedbackEditor = card.querySelector('[data-feedback-editor]');
                    const aiPercentage = Number.parseFloat(card.dataset.aiPercentage || '');
                    const aiLabel = card.dataset.aiLabel || '';
                    const manuallyAdjusted = normalizeLabel(label) !== normalizeLabel(aiLabel)
                        || (Number.isFinite(percentage) && Number.isFinite(aiPercentage) && Math.abs(percentage - aiPercentage) >= 0.01);

                    if (percentageInput) {
                        percentageInput.value = Number.isFinite(percentage) ? percentage : '';
                    }

                    if (selectedRatingInput) {
                        selectedRatingInput.value = label;
                    }

                    if (domainLabelOutput) {
                        domainLabelOutput.textContent = manuallyAdjusted ? teacherAdjustedLabel : aiSuggestedLabel;
                    }

                    if (domainValueOutput) {
                        domainValueOutput.textContent = label;
                    }

                    if (domainPercentageOutput) {
                        domainPercentageOutput.textContent = Number.isFinite(percentage) ? `${formatNumber(percentage)}%` : '-';
                    }

                    if (scoreOutput) {
                        scoreOutput.textContent = Number.isFinite(percentage) && Number.isFinite(pointsPossible)
                            ? formatNumber((percentage / 100) * pointsPossible)
                            : '-';
                    }

                    card.querySelectorAll('[data-rating-button]').forEach((candidate) => {
                        const isSelected = candidate === button;
                        candidate.classList.toggle('is-selected', isSelected);
                        candidate.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
                    });

                    if (feedbackEditor) {
                        feedbackEditor.classList.remove('d-none');
                    }

                    recalculateRubric();
                };

                const recalculateRubric = () => {
                    let weightedTotal = 0;
                    let hasWeights = false;
                    let rubricTotal = 0;

                    rubricEditor.querySelectorAll('[data-criterion-card]').forEach((card) => {
                        const percentageInput = card.querySelector('[data-percentage-input]');
                        const percentage = Number.parseFloat(percentageInput ? percentageInput.value : '');
                        const maxPercentage = Number.parseFloat(card.dataset.maxPercentage || '');

                        if (Number.isFinite(maxPercentage)) {
                            rubricTotal += maxPercentage;
                        }

                        if (Number.isFinite(percentage) && Number.isFinite(maxPercentage)) {
                            hasWeights = true;
                            weightedTotal += (percentage * maxPercentage) / 100;
                        }
                    });

                    updateOutputs(
                        '[data-rubric-result-output]',
                        hasWeights ? `${formatNumber(weightedTotal)}% / ${formatNumber(rubricTotal)}%` : '-'
                    );

                    if (Number.isFinite(pointsPossible) && hasWeights) {
                        const convertedScore = (weightedTotal / 100) * pointsPossible;
                        updateOutputs(
                            '[data-rubric-converted-score-output]',
                            `${formatNumber(convertedScore)} / ${formatNumber(pointsPossible)}`
                        );

                        if (finalScoreInput) {
                            finalScoreInput.value = convertedScore.toFixed(2);
                        }
                    } else {
                        updateOutputs('[data-rubric-converted-score-output]', '-');
                    }
                };

                rubricEditor.addEventListener('click', (event) => {
                    const toggleButton = event.target.closest('[data-toggle-target]');

                    if (toggleButton) {
                        const criterionCard = toggleButton.closest('[data-criterion-card]');
                        const targetName = toggleButton.dataset.toggleTarget;
                        const panel = criterionCard ? criterionCard.querySelector(`[data-panel="${targetName}"]`) : null;

                        if (panel) {
                            panel.classList.toggle('d-none');
                        }

                        return;
                    }

                    const ratingButton = event.target.closest('[data-rating-button]');

                    if (!ratingButton || ratingButton.disabled) {
                        return;
                    }

                    const criterionCard = ratingButton.closest('[data-criterion-card]');

                    if (criterionCard) {
                        updateCriterionSelection(criterionCard, ratingButton);
                    }
                });

                recalculateRubric();
            })();
        </script>
    @endif
@endsection
