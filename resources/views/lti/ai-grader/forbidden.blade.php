@extends('layouts.lti')

@section('title', __('ai-grader.lti.titles.forbidden'))

@section('content')
    <div class="alert alert-warning mb-0" role="alert">
        <h1 class="h5 mb-2">{{ __('ai-grader.lti.forbidden.heading') }}</h1>
        <p class="mb-0">{{ $message ?? __('ai-grader.lti.forbidden.message') }}</p>
    </div>

    <div class="card mt-3">
        <div class="card-body">
            <h2 class="h6 mb-3">{{ __('ai-grader.lti.forbidden.diagnostics_title') }}</h2>
            <dl class="row mb-0 small">
                <dt class="col-sm-3">{{ __('ai-grader.lti.forbidden.course_id') }}</dt>
                <dd class="col-sm-9">{{ $context['course_id'] ?? '-' }}</dd>

                <dt class="col-sm-3">{{ __('ai-grader.lti.forbidden.canvas_user_id') }}</dt>
                <dd class="col-sm-9">{{ $context['canvas_user_id'] ?? '-' }}</dd>

                <dt class="col-sm-3">{{ __('ai-grader.lti.forbidden.login_id') }}</dt>
                <dd class="col-sm-9">{{ $context['login'] ?? '-' }}</dd>

                <dt class="col-sm-3">{{ __('ai-grader.lti.forbidden.roles') }}</dt>
                <dd class="col-sm-9">
                    @forelse (($context['roles'] ?? []) as $role)
                        <code>{{ $role }}</code>@if (! $loop->last), @endif
                    @empty
                        <span class="text-muted">{{ __('ai-grader.lti.forbidden.no_roles') }}</span>
                    @endforelse
                </dd>

                <dt class="col-sm-3">{{ __('ai-grader.lti.forbidden.block_reason') }}</dt>
                <dd class="col-sm-9"><code>{{ $blockReason ?? 'unknown' }}</code></dd>

                <dt class="col-sm-3">{{ __('ai-grader.lti.forbidden.host') }}</dt>
                <dd class="col-sm-9">{{ $diagnostics['host'] ?? '-' }}</dd>

                <dt class="col-sm-3">{{ __('ai-grader.lti.forbidden.method') }}</dt>
                <dd class="col-sm-9">{{ $diagnostics['method'] ?? '-' }}</dd>

                <dt class="col-sm-3">{{ __('ai-grader.lti.forbidden.received_keys') }}</dt>
                <dd class="col-sm-9">
                    @forelse (($diagnostics['received_keys'] ?? []) as $key)
                        <code>{{ $key }}</code>@if (! $loop->last), @endif
                    @empty
                        <span class="text-muted">{{ __('ai-grader.lti.forbidden.no_keys') }}</span>
                    @endforelse
                </dd>
            </dl>
        </div>
    </div>
@endsection
