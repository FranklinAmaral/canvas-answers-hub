@extends('layouts.lti')

@section('title', __('ai-grader.lti.titles.info'))

@section('content')
    <div class="alert alert-info mb-4" role="alert">
        <h1 class="h5 mb-2">{{ __('ai-grader.lti.info.heading') }}</h1>
        <p class="mb-0">{{ __('ai-grader.lti.info.message') }}</p>
    </div>

    <div class="card">
        <div class="card-body">
            <h2 class="h6 mb-3">{{ __('ai-grader.lti.info.card_title') }}</h2>
            <p class="mb-3">{{ __('ai-grader.lti.info.card_text') }}</p>
            <a href="{{ $urls['config'] }}" target="_blank" rel="noopener" class="btn btn-outline-secondary me-2">
                {{ __('ai-grader.lti.info.open_json') }}
            </a>
            <a href="/lti/install" class="btn btn-primary">
                {{ __('ai-grader.lti.info.view_install') }}
            </a>
        </div>
    </div>
@endsection
