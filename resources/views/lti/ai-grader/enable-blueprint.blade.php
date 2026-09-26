@extends('layouts.lti')

@section('title', __('ai-grader.lti.titles.enable_blueprint'))

@section('content')
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('ai-grader.lti.enable_blueprint.heading') }}</h1>
            <div class="text-muted">{{ $canvasCourseName ?: __('ai-grader.lti.enable_blueprint.course_fallback', ['id' => $context['course_id']]) }}</div>
        </div>
        <span class="badge bg-light text-dark">{{ __('ai-grader.lti.blueprint.status_badge', ['id' => $context['course_id']]) }}</span>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <div class="fw-semibold mb-1">{{ __('ai-grader.lti.enable_blueprint.save_failed') }}</div>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <div class="alert alert-info">
                        <div class="fw-semibold mb-1">{{ __('ai-grader.lti.enable_blueprint.intro_title') }}</div>
                        <div class="small mb-0">{{ __('ai-grader.lti.enable_blueprint.intro_text') }}</div>
                    </div>

                    <form method="POST" action="{{ route('lti.ai-grader.blueprint.enable') }}">
                        @csrf
                        @include('lti.ai-grader._context-fields', ['context' => $context])
                        <input type="hidden" name="canvas_course_name" value="{{ $canvasCourseName }}">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="organization_id">{{ __('ai-grader.lti.enable_blueprint.organization') }}</label>
                                <div id="organization_id" class="form-control bg-light">{{ $resolvedEnvironment?->organization?->name ?: $context['organization_id'] }}</div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="canvas_environment_id">{{ __('ai-grader.lti.enable_blueprint.environment') }}</label>
                                <div id="canvas_environment_id" class="form-control bg-light">{{ $resolvedEnvironment?->name ?: $context['canvas_environment_id'] }}</div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="ai_provider_id">{{ __('ai-grader.lti.enable_blueprint.provider') }}</label>
                            <select class="form-select" id="ai_provider_id" name="ai_provider_id">
                                <option value="">{{ __('ai-grader.lti.enable_blueprint.provider_placeholder') }}</option>
                                @foreach ($providers as $provider)
                                    <option value="{{ $provider->id }}" @selected((string) old('ai_provider_id') === (string) $provider->id)>
                                        {{ $provider->name }} - {{ $provider->provider_type }} - {{ $provider->organization?->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="publication_mode">{{ __('ai-grader.lti.enable_blueprint.publication') }}</label>
                                <select class="form-select" id="publication_mode" name="publication_mode" required>
                                    @foreach ($publicationModes as $value => $label)
                                        <option value="{{ $value }}" @selected(old('publication_mode', \App\Models\AiGraderBlueprintConfig::PUBLICATION_TEACHER_APPROVAL) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="trigger_mode">{{ __('ai-grader.lti.enable_blueprint.trigger') }}</label>
                                <select class="form-select" id="trigger_mode" name="trigger_mode" required>
                                    @foreach ($triggerModes as $value => $label)
                                        <option value="{{ $value }}" @selected(old('trigger_mode', \App\Models\AiGraderBlueprintConfig::TRIGGER_AFTER_SUBMISSION) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="scheduled_at">{{ __('ai-grader.lti.enable_blueprint.scheduled_at') }}</label>
                                <input class="form-control" type="text" id="scheduled_at" name="scheduled_at" value="{{ old('scheduled_at') }}" placeholder="2026-06-09 14:00:00">
                            </div>
                        </div>

                        <button class="btn btn-primary" type="submit">{{ __('ai-grader.lti.enable_blueprint.submit') }}</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mt-3 mt-lg-0">
            <div class="card">
                <div class="card-body">
                    <h2 class="h5 mb-3">{{ __('ai-grader.lti.enable_blueprint.context_title') }}</h2>
                    <dl class="row mb-0">
                        <dt class="col-5">{{ __('ai-grader.lti.enable_blueprint.course') }}</dt>
                        <dd class="col-7">{{ $canvasCourseName ?: __('ai-grader.lti.enable_blueprint.course_fallback', ['id' => $context['course_id']]) }}</dd>
                        <dt class="col-5">{{ __('ai-grader.lti.enable_blueprint.course_id') }}</dt>
                        <dd class="col-7">{{ $context['course_id'] ?: '-' }}</dd>
                        <dt class="col-5">{{ __('ai-grader.lti.enable_blueprint.user') }}</dt>
                        <dd class="col-7">{{ $context['name'] ?: $context['login'] ?: '-' }}</dd>
                        <dt class="col-5">{{ __('ai-grader.lti.enable_blueprint.login') }}</dt>
                        <dd class="col-7">{{ $context['login'] ?: '-' }}</dd>
                        <dt class="col-5">{{ __('ai-grader.lti.enable_blueprint.roles') }}</dt>
                        <dd class="col-7">{{ implode(', ', $context['roles']) ?: '-' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection
