@extends('layouts.lti')

@section('title', 'GraderAI - Login LTI')

@section('content')
    <div class="alert alert-warning mb-4" role="alert">
        <h1 class="h5 mb-2">Login LTI recebido</h1>
        <p class="mb-0">{{ $message }}</p>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6 mb-3">Parametros principais</h2>
            @if (! empty($missing ?? []))
                <div class="alert alert-danger small" role="alert">
                    Parametros obrigatorios ausentes:
                    @foreach ($missing as $key)
                        <code>{{ $key }}</code>@if (! $loop->last), @endif
                    @endforeach
                </div>
            @endif
            <dl class="row mb-0 small">
                <dt class="col-sm-3">metodo</dt>
                <dd class="col-sm-9">{{ $diagnostics['method'] ?? '-' }}</dd>

                <dt class="col-sm-3">host</dt>
                <dd class="col-sm-9">{{ $diagnostics['host'] ?? '-' }}</dd>

                <dt class="col-sm-3">iss</dt>
                <dd class="col-sm-9">{{ $diagnostics['iss'] ?? '-' }}</dd>

                <dt class="col-sm-3">login_hint recebido</dt>
                <dd class="col-sm-9">{{ ($diagnostics['has_login_hint'] ?? false) ? 'sim' : 'nao' }}</dd>

                <dt class="col-sm-3">target_link_uri</dt>
                <dd class="col-sm-9">{{ $diagnostics['target_link_uri'] ?? '-' }}</dd>

                <dt class="col-sm-3">lti_message_hint recebido</dt>
                <dd class="col-sm-9">{{ ($diagnostics['has_lti_message_hint'] ?? false) ? 'sim' : 'nao' }}</dd>

                <dt class="col-sm-3">client_id</dt>
                <dd class="col-sm-9">{{ $diagnostics['client_id'] ?? '-' }}</dd>

                <dt class="col-sm-3">lti_deployment_id</dt>
                <dd class="col-sm-9">{{ $diagnostics['lti_deployment_id'] ?? '-' }}</dd>

                <dt class="col-sm-3">motivo</dt>
                <dd class="col-sm-9"><code>{{ $diagnostics['reason'] ?? '-' }}</code></dd>
            </dl>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="h6 mb-3">Query params sanitizados</h2>
                    <pre class="bg-light border rounded p-3 mb-0" style="white-space: pre-wrap;"><code>{{ json_encode($diagnostics['query_params'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></pre>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="h6 mb-3">Post params sanitizados</h2>
                    <pre class="bg-light border rounded p-3 mb-0" style="white-space: pre-wrap;"><code>{{ json_encode($diagnostics['post_params'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></pre>
                </div>
            </div>
        </div>
    </div>
@endsection
