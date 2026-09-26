@extends('layouts.lti')

@section('title', 'GraderAI - Diagnostico do curso')

@section('content')
    <div class="alert alert-warning mb-4" role="alert">
        <h1 class="h5 mb-2">{{ $message }}</h1>
        @if ($details)
            <p class="mb-0">{{ $details }}</p>
        @endif
    </div>

    <div class="card">
        <div class="card-body">
            <h2 class="h6 mb-3">Diagnostico do launch</h2>
            <dl class="row mb-0 small">
                <dt class="col-sm-3">course_id recebido</dt>
                <dd class="col-sm-9">{{ $context['course_id'] ?? '-' }}</dd>

                <dt class="col-sm-3">Canvas Environment</dt>
                <dd class="col-sm-9">
                    @if ($canvasEnvironment)
                        {{ $canvasEnvironment->name }} ({{ $canvasEnvironment->base_url }})
                    @else
                        <span class="text-muted">Nao identificado.</span>
                    @endif
                </dd>

                <dt class="col-sm-3">login_id recebido</dt>
                <dd class="col-sm-9">{{ $context['login'] ?? '-' }}</dd>

                <dt class="col-sm-3">roles recebidas</dt>
                <dd class="col-sm-9">
                    @forelse (($context['roles'] ?? []) as $role)
                        <code>{{ $role }}</code>@if (! $loop->last), @endif
                    @empty
                        <span class="text-muted">Nenhuma role recebida.</span>
                    @endforelse
                </dd>

                <dt class="col-sm-3">host</dt>
                <dd class="col-sm-9">{{ $diagnostics['host'] ?? '-' }}</dd>

                <dt class="col-sm-3">metodo</dt>
                <dd class="col-sm-9">{{ $diagnostics['method'] ?? '-' }}</dd>

                <dt class="col-sm-3">endpoint chamado</dt>
                <dd class="col-sm-9">{{ $courseDiagnostics['endpoint'] ?? '-' }}</dd>

                <dt class="col-sm-3">status HTTP</dt>
                <dd class="col-sm-9">{{ $courseDiagnostics['status_http'] ?? '-' }}</dd>

                <dt class="col-sm-3">campo blueprint</dt>
                <dd class="col-sm-9">{{ ($courseDiagnostics['has_blueprint'] ?? false) ? 'presente' : 'ausente' }}</dd>

                <dt class="col-sm-3">campo is_blueprint</dt>
                <dd class="col-sm-9">{{ ($courseDiagnostics['has_is_blueprint'] ?? false) ? 'presente' : 'ausente' }}</dd>
            </dl>
        </div>
    </div>
@endsection
