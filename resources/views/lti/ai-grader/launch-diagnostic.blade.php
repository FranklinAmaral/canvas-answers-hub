@extends('layouts.lti')

@section('title', 'GraderAI - Launch LTI')

@section('content')
    <div class="alert alert-warning mb-4" role="alert">
        <h1 class="h5 mb-2">Launch LTI incompleto</h1>
        <p class="mb-0">{{ $message }}</p>
    </div>

    <div class="card">
        <div class="card-body">
            <h2 class="h6 mb-3">Diagnostico tecnico</h2>
            <dl class="row mb-0 small">
                <dt class="col-sm-3">host</dt>
                <dd class="col-sm-9">{{ $diagnostics['host'] ?? '-' }}</dd>

                <dt class="col-sm-3">metodo</dt>
                <dd class="col-sm-9">{{ $diagnostics['method'] ?? '-' }}</dd>

                <dt class="col-sm-3">motivo</dt>
                <dd class="col-sm-9"><code>{{ $diagnostics['reason'] ?? '-' }}</code></dd>

                <dt class="col-sm-3">chaves recebidas</dt>
                <dd class="col-sm-9">
                    @forelse (($diagnostics['received_keys'] ?? []) as $key)
                        <code>{{ $key }}</code>@if (! $loop->last), @endif
                    @empty
                        <span class="text-muted">Nenhuma chave recebida.</span>
                    @endforelse
                </dd>
            </dl>
        </div>
    </div>
@endsection
