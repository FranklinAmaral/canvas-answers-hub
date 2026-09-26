@extends('layouts.ai-grader')

@section('title')
    Provider de IA
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            GraderAI
        @endslot
        @slot('title')
            Provider de IA
        @endslot
    @endcomponent

    <div class="container-fluid">
        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            </div>
        @endif

        <div class="row">
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-4">
                            <div>
                                <h4 class="mb-1">{{ $provider->name }}</h4>
                                <p class="text-muted mb-0">{{ $provider->organization?->name }}</p>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="{{ route('ai-grader.ai-providers.edit', $provider) }}" class="btn btn-primary">Editar</a>
                                <form method="POST" action="{{ route('ai-grader.ai-providers.test', $provider) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-primary">Testar conexao</button>
                                </form>
                            </div>
                        </div>

                        <dl class="row mb-0">
                            <dt class="col-sm-4">Tipo</dt>
                            <dd class="col-sm-8">{{ $provider->provider_type }}</dd>

                            <dt class="col-sm-4">URL base</dt>
                            <dd class="col-sm-8">{{ $provider->base_url ?: '-' }}</dd>

                            <dt class="col-sm-4">Endpoint path</dt>
                            <dd class="col-sm-8">{{ $provider->endpointPath() }}</dd>

                            <dt class="col-sm-4">Endpoint final</dt>
                            <dd class="col-sm-8">{{ $provider->endpointUrl() }}</dd>

                            <dt class="col-sm-4">Modelo</dt>
                            <dd class="col-sm-8">{{ $provider->model ?: '-' }}</dd>

                            <dt class="col-sm-4">Bearer Token configurado</dt>
                            <dd class="col-sm-8">{{ $provider->hasApiKey() ? 'sim' : 'nao' }}</dd>

                            <dt class="col-sm-4">Timeout</dt>
                            <dd class="col-sm-8">{{ $provider->request_timeout_seconds ?: '-' }}</dd>

                            <dt class="col-sm-4">Max tokens</dt>
                            <dd class="col-sm-8">{{ $provider->max_tokens ?: '-' }}</dd>

                            <dt class="col-sm-4">Temperature</dt>
                            <dd class="col-sm-8">{{ $provider->temperature ?? '-' }}</dd>

                            <dt class="col-sm-4">Ativo</dt>
                            <dd class="col-sm-8">{{ $provider->enabled ? 'sim' : 'nao' }}</dd>

                            <dt class="col-sm-4">Default da organizacao</dt>
                            <dd class="col-sm-8">{{ $provider->is_default ? 'sim' : 'nao' }}</dd>

                            <dt class="col-sm-4">Blueprints associadas</dt>
                            <dd class="col-sm-8">{{ $provider->blueprint_configs_count }}</dd>

                            <dt class="col-sm-4">Regra de seleção</dt>
                            <dd class="col-sm-8">@lang('ai-grader.admin.ai_providers.resolution_help')</dd>
                        </dl>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Acoes</h5>
                        <div class="d-grid gap-2">
                            <a href="{{ route('ai-grader.ai-providers.index') }}" class="btn btn-outline-secondary">Voltar</a>
                            <form method="POST" action="{{ route('ai-grader.ai-providers.destroy', $provider) }}" onsubmit="return confirm('Remover este provider?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger w-100">Remover</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
