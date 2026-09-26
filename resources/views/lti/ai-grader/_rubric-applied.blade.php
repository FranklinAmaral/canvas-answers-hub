@php
    $criteriaBlocks = is_array($criteriaBlocks ?? null) ? $criteriaBlocks : [];
    $rubricTitle = $rubricTitle ?? null;
    $criteriaCount = (int) ($criteriaCount ?? count($criteriaBlocks));
    $rubricTotalPercentage = is_numeric($rubricTotalPercentage ?? null) ? (float) $rubricTotalPercentage : null;
    $currentRubricPercentage = is_numeric($currentRubricPercentage ?? null) ? (float) $currentRubricPercentage : null;
    $convertedScore = is_numeric($convertedScore ?? null) ? (float) $convertedScore : null;
    $pointsPossible = is_numeric($pointsPossible ?? null) ? (float) $pointsPossible : null;
    $interactive = (bool) ($interactive ?? false);
@endphp

@if ($criteriaBlocks === [])
    <div class="text-muted">{{ __('ai-grader.rubric.summary.empty') }}</div>
@else
    <div class="graderai-rubric"
        data-rubric-editor="{{ $interactive ? 'true' : 'false' }}"
        data-points-possible="{{ $pointsPossible !== null ? $pointsPossible : '' }}"
        data-ai-suggested-label="{{ __('ai-grader.lti.show.ai_suggested_domain') }}"
        data-teacher-adjusted-label="{{ __('ai-grader.lti.show.teacher_adjusted_domain') }}">
        <div class="graderai-rubric-summary mb-4">
            <div class="graderai-summary-card">
                <div class="text-muted small">{{ __('ai-grader.lti.show.rubric_name') }}</div>
                <div class="fw-semibold">{{ $rubricTitle ?: '-' }}</div>
            </div>
            <div class="graderai-summary-card">
                <div class="text-muted small">{{ __('ai-grader.lti.show.rubric_criteria_count') }}</div>
                <div class="fw-semibold">{{ $criteriaCount }}</div>
            </div>
            <div class="graderai-summary-card">
                <div class="text-muted small">{{ __('ai-grader.lti.show.rubric_total') }}</div>
                <div class="fw-semibold">
                    @if ($rubricTotalPercentage !== null)
                        {{ $formatNumber($rubricTotalPercentage) }}%
                    @else
                        -
                    @endif
                </div>
            </div>
            <div class="graderai-summary-card">
                <div class="text-muted small">{{ __('ai-grader.lti.show.rubric_result') }}</div>
                <div class="fw-semibold" data-rubric-result-output>
                    @if ($currentRubricPercentage !== null && $rubricTotalPercentage !== null)
                        {{ $formatNumber($currentRubricPercentage) }}% / {{ $formatNumber($rubricTotalPercentage) }}%
                    @else
                        -
                    @endif
                </div>
            </div>
            <div class="graderai-summary-card">
                <div class="text-muted small">{{ __('ai-grader.lti.show.final_converted_score') }}</div>
                <div class="fw-semibold" data-rubric-converted-score-output>
                    @if ($convertedScore !== null && $pointsPossible !== null)
                        {{ $formatNumber($convertedScore) }} / {{ $formatNumber($pointsPossible) }}
                    @else
                        -
                    @endif
                </div>
            </div>
        </div>

        <div class="vstack gap-3">
            @foreach ($criteriaBlocks as $index => $criterion)
                @php
                    $criterion = is_array($criterion) ? $criterion : [];
                    $ratings = is_array($criterion['ratings'] ?? null) ? $criterion['ratings'] : [];
                    $selectedPercentage = is_numeric($criterion['selected_percentage'] ?? null) ? (float) $criterion['selected_percentage'] : null;
                    $aiPercentage = is_numeric($criterion['ai_percentage'] ?? null) ? (float) $criterion['ai_percentage'] : null;
                    $selectedDomainLabel = $criterion['selected_rating_name'] ?? __('ai-grader.common.not_available');
                    $criterionScore = is_numeric($criterion['criterion_score_points'] ?? null) ? (float) $criterion['criterion_score_points'] : null;
                    $maxPercentage = is_numeric($criterion['max_percentage'] ?? null) ? (float) $criterion['max_percentage'] : null;
                    $aiFeedback = trim((string) ($criterion['ai_feedback'] ?? ''));
                    $currentFeedback = trim((string) ($criterion['current_feedback'] ?? ''));
                    $isAdjusted = (bool) ($criterion['selection_changed'] ?? false);
                    $domainLabel = $isAdjusted
                        ? __('ai-grader.lti.show.teacher_adjusted_domain')
                        : __('ai-grader.lti.show.ai_suggested_domain');
                    $feedbackEditorVisible = (bool) ($criterion['show_feedback_editor'] ?? false);
                @endphp

                <section class="graderai-criterion-card"
                    data-criterion-card
                    data-max-percentage="{{ $maxPercentage !== null ? $maxPercentage : '' }}"
                    data-ai-percentage="{{ $aiPercentage !== null ? $aiPercentage : '' }}"
                    data-ai-label="{{ $criterion['ai_rating_name'] ?? '' }}">
                    @if ($interactive)
                        <input type="hidden"
                            name="teacher_rubric_feedback[{{ $index }}][criterion_id]"
                            value="{{ $criterion['criterion_id'] ?? '' }}">
                        <input type="hidden"
                            name="teacher_rubric_feedback[{{ $index }}][criterion_name]"
                            value="{{ $criterion['name'] ?? '' }}">
                        <input type="hidden"
                            name="teacher_rubric_feedback[{{ $index }}][selected_rating]"
                            value="{{ $criterion['selected_rating_name'] ?? '' }}"
                            data-selected-rating-input>
                        <input type="hidden"
                            name="teacher_rubric_feedback[{{ $index }}][percentage]"
                            value="{{ $selectedPercentage !== null ? $selectedPercentage : '' }}"
                            data-percentage-input>
                        <input type="hidden"
                            name="teacher_rubric_feedback[{{ $index }}][max_percentage]"
                            value="{{ $maxPercentage !== null ? $maxPercentage : '' }}">
                        <input type="hidden"
                            name="teacher_rubric_feedback[{{ $index }}][max_points]"
                            value="{{ is_numeric($criterion['max_points'] ?? null) ? $criterion['max_points'] : '' }}">
                    @endif

                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                        <div>
                            <div class="fw-semibold">{{ $criterion['name'] ?? '-' }}</div>
                            <div class="small text-muted">
                                {{ __('ai-grader.lti.show.rubric_max_weight_full') }}
                                {{ $maxPercentage !== null ? $formatNumber($maxPercentage) . '%' : '-' }}
                            </div>
                        </div>

                        <div class="graderai-criterion-stats">
                            <div class="graderai-criterion-stat">
                                <div class="text-muted small" data-domain-label-output>{{ $domainLabel }}</div>
                                <div class="fw-semibold" data-domain-value-output>{{ $selectedDomainLabel }}</div>
                                <div class="small text-muted" data-domain-percentage-output>
                                    {{ $selectedPercentage !== null ? $formatNumber($selectedPercentage) . '%' : '-' }}
                                </div>
                            </div>
                            <div class="graderai-criterion-stat">
                                <div class="text-muted small">{{ __('ai-grader.lti.show.criterion_score') }}</div>
                                <div class="fw-semibold" data-criterion-score-output>
                                    {{ $criterionScore !== null ? $formatNumber($criterionScore) : '-' }}
                                </div>
                            </div>
                        </div>
                    </div>

                    @if (! empty($criterion['description']))
                        <div class="mb-3">
                            <div class="text-muted small">{{ __('ai-grader.lti.show.rubric_description') }}</div>
                            <div>{{ $criterion['description'] }}</div>
                        </div>
                    @endif

                    @if ($ratings !== [])
                        <div class="mb-3">
                            <div class="text-muted small mb-2">{{ __('ai-grader.lti.show.conceptual_domains') }}</div>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach ($ratings as $rating)
                                    @php
                                        $rating = is_array($rating) ? $rating : [];
                                        $ratingPercentage = is_numeric($rating['percentage'] ?? null) ? (float) $rating['percentage'] : null;
                                        $ratingLabel = $rating['description'] ?? ($rating['id'] ?? '-');
                                        $isSelected = $selectedDomainLabel === $ratingLabel
                                            || ($ratingPercentage !== null && $selectedPercentage !== null && abs($ratingPercentage - $selectedPercentage) < 0.01);
                                    @endphp
                                    <button type="button"
                                        class="graderai-rating-button {{ $isSelected ? 'is-selected' : '' }}"
                                        data-rating-button
                                        data-rating-label="{{ $ratingLabel }}"
                                        data-percentage="{{ $ratingPercentage !== null ? $ratingPercentage : '' }}"
                                        aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                                        @disabled(! $interactive)>
                                        <span class="fw-semibold d-block">{{ $ratingLabel }}</span>
                                        <span class="small text-muted d-block">
                                            {{ $ratingPercentage !== null ? $formatNumber($ratingPercentage) . '%' : '-' }}
                                        </span>

                                        @if (! empty($rating['long_description']))
                                            <span class="small d-block mt-1">{{ $rating['long_description'] }}</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($aiFeedback !== '' || $interactive)
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            @if ($aiFeedback !== '')
                                <button type="button"
                                    class="btn btn-sm btn-light border"
                                    data-toggle-target="criterion-ai-feedback-{{ $index }}">
                                    {{ __('ai-grader.lti.show.view_ai_feedback') }}
                                </button>
                            @endif

                            @if ($interactive)
                                <button type="button"
                                    class="btn btn-sm btn-light border"
                                    data-toggle-target="criterion-feedback-editor-{{ $index }}">
                                    {{ __('ai-grader.lti.show.edit_criterion_feedback') }}
                                </button>
                            @endif
                        </div>
                    @endif

                    @if ($aiFeedback !== '')
                        <div class="graderai-feedback-panel d-none"
                            data-panel="criterion-ai-feedback-{{ $index }}">
                            <div class="text-muted small mb-2">{{ __('ai-grader.lti.show.ai_feedback_per_criterion') }}</div>
                            <div>{{ $aiFeedback }}</div>
                        </div>
                    @endif

                    @if ($interactive)
                        <div class="graderai-feedback-panel {{ $feedbackEditorVisible ? '' : 'd-none' }}"
                            data-panel="criterion-feedback-editor-{{ $index }}"
                            data-feedback-editor>
                            <label class="form-label"
                                for="teacher_rubric_feedback_{{ $index }}">{{ __('ai-grader.lti.show.criterion_feedback') }}</label>
                            <textarea class="form-control @error('teacher_rubric_feedback.' . $index . '.feedback') is-invalid @enderror"
                                id="teacher_rubric_feedback_{{ $index }}"
                                name="teacher_rubric_feedback[{{ $index }}][feedback]"
                                rows="3">{{ $currentFeedback }}</textarea>
                            @error('teacher_rubric_feedback.' . $index . '.feedback')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    @elseif ($currentFeedback !== '' && $currentFeedback !== $aiFeedback)
                        <div class="graderai-feedback-panel">
                            <div class="text-muted small mb-2">{{ __('ai-grader.lti.show.criterion_feedback') }}</div>
                            <div>{{ $currentFeedback }}</div>
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
@endif
