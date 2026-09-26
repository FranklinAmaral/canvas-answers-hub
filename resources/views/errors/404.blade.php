@extends('layouts.guest')

@section('title', __('ai-grader.admin.errors.not_found_title') . ' - ' . __('ai-grader.admin.layout.brand'))

@section('content')
    <div class="text-center">
        <div class="display-4 fw-semibold text-body-secondary">404</div>
        <h1 class="h4 mt-4">
            @lang('ai-grader.admin.errors.not_found_title')
        </h1>
        <p class="text-muted mt-2">
            @lang('ai-grader.admin.errors.not_found_message')
        </p>
        <a href="{{ route('root') }}" class="btn btn-primary mt-3">
            @lang('ai-grader.admin.errors.back')
        </a>
    </div>
@endsection
