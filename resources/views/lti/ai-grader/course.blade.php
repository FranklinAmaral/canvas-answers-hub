@extends('layouts.lti')

@section('title', __('ai-grader.lti.titles.course'))

@section('content')
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('ai-grader.lti.course.heading') }}</h1>
            <div class="text-muted">{{ __('ai-grader.lti.course.canvas_course', ['id' => $context['course_id']]) }}</div>
        </div>
        <div class="text-end small text-muted">
            <div>{{ $context['name'] ?: __('ai-grader.lti.course.canvas_user_fallback') }}</div>
            <div>{{ $context['login'] ?: $context['canvas_user_id'] }}</div>
        </div>
    </div>

    @include('lti.ai-grader._course-status')
@endsection
