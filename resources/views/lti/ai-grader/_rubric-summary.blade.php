@php
    $rubric = is_array($rubric ?? null) ? $rubric : [];
    $criteria = is_array($rubric['criteria'] ?? null) ? $rubric['criteria'] : [];
    $compact = (bool) ($compact ?? false);
@endphp

@if ($criteria === [])
    <div class="text-muted small">{{ __('ai-grader.rubric.summary.empty') }}</div>
@else
    <div class="border rounded bg-light p-3">
        @if (! empty($rubric['title']))
            <div class="fw-semibold mb-1">{{ $rubric['title'] }}</div>
        @endif

        <div class="small text-muted mb-3">
            {{ __('ai-grader.rubric.summary.criteria_count', ['count' => count($criteria)]) }}
            @if (is_numeric($rubric['points_possible'] ?? null))
                · {{ __('ai-grader.rubric.summary.points_possible', ['value' => rtrim(rtrim(number_format((float) $rubric['points_possible'], 2, '.', ''), '0'), '.')]) }}
            @endif
        </div>

        <div class="vstack gap-2">
            @foreach ($criteria as $criterion)
                @php
                    $criterion = is_array($criterion) ? $criterion : [];
                    $ratings = is_array($criterion['ratings'] ?? null) ? $criterion['ratings'] : [];
                @endphp
                <div class="border rounded bg-white p-2">
                    <div class="fw-semibold">
                        {{ $criterion['name'] ?? $criterion['description'] ?? ($criterion['id'] ?? '-') }}
                    </div>
                    <div class="small text-muted">
                        @if (is_numeric($criterion['max_percentage'] ?? null))
                            {{ __('ai-grader.rubric.summary.max_weight', ['value' => rtrim(rtrim(number_format((float) $criterion['max_percentage'], 2, '.', ''), '0'), '.')]) }}
                        @endif
                        @if (is_numeric($criterion['max_points'] ?? null))
                            @if (is_numeric($criterion['max_percentage'] ?? null))
                                ·
                            @endif
                            {{ __('ai-grader.rubric.summary.max_points', ['value' => rtrim(rtrim(number_format((float) $criterion['max_points'], 2, '.', ''), '0'), '.')]) }}
                        @endif
                    </div>

                    @if (! empty($criterion['long_description']))
                        <div class="small mt-1">{{ $criterion['long_description'] }}</div>
                    @endif

                    @if (! $compact && $ratings !== [])
                        <div class="small mt-2">
                            <div class="text-muted fw-semibold mb-1">{{ __('ai-grader.rubric.summary.ratings') }}</div>
                            <div class="vstack gap-1">
                                @foreach ($ratings as $rating)
                                    @php $rating = is_array($rating) ? $rating : []; @endphp
                                    <div>
                                        <span class="fw-semibold">{{ $rating['description'] ?? ($rating['id'] ?? '-') }}</span>
                                        @if (is_numeric($rating['percentage'] ?? null))
                                            <span class="text-muted">· {{ rtrim(rtrim(number_format((float) $rating['percentage'], 2, '.', ''), '0'), '.') }}%</span>
                                        @endif
                                        @if (is_numeric($rating['points'] ?? null))
                                            <span class="text-muted">· {{ rtrim(rtrim(number_format((float) $rating['points'], 2, '.', ''), '0'), '.') }} pts</span>
                                        @endif
                                        @if (! empty($rating['long_description']))
                                            <div class="text-muted">{{ $rating['long_description'] }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif
