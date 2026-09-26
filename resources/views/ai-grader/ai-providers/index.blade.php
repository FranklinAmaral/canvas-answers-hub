@extends('layouts.ai-grader')

@section('title')
    GraderAI - Providers de IA
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            Inicio
        @endslot
        @slot('title')
            GraderAI - Providers de IA
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

        <div class="row mb-4">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">
                    <div>
                        <h4 class="mb-1">Providers de IA</h4>
                        <p class="text-muted mb-0">@lang('ai-grader.admin.ai_providers.resolution_help')</p>
                    </div>
                    <a href="{{ route('ai-grader.ai-providers.create') }}" class="btn btn-primary">Novo provider</a>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="{{ route('ai-grader.ai-providers.index') }}" class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label for="organization_id" class="form-label">Organizacao</label>
                        <select name="organization_id" id="organization_id" class="form-select">
                            <option value="">Todas</option>
                            @foreach ($organizations as $organization)
                                <option value="{{ $organization->id }}" @selected((string) request('organization_id') === (string) $organization->id)>
                                    {{ $organization->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="provider_type" class="form-label">Tipo</label>
                        <select name="provider_type" id="provider_type" class="form-select">
                            <option value="">Todos</option>
                            @foreach ($providerTypes as $value => $label)
                                <option value="{{ $value }}" @selected(request('provider_type') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-outline-primary w-100">Filtrar</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-nowrap align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Organizacao</th>
                                <th>Tipo</th>
                                <th>Base URL</th>
                                <th>Endpoint path</th>
                                <th>Modelo</th>
                                <th>Ativo</th>
                                <th>Padrão</th>
                                <th>Blueprints</th>
                                <th>Bearer Token</th>
                                <th>Acoes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($providers as $provider)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $provider->name }}</div>
                                    </td>
                                    <td>{{ $provider->organization?->name }}</td>
                                    <td>{{ $providerTypes[$provider->provider_type] ?? $provider->provider_type }}</td>
                                    <td>{{ $provider->base_url ?: '-' }}</td>
                                    <td>{{ $provider->endpointPath() }}</td>
                                    <td>{{ $provider->model ?: '-' }}</td>
                                    <td>
                                        <span class="badge bg-{{ $provider->enabled ? 'success' : 'secondary' }}-subtle text-{{ $provider->enabled ? 'success' : 'secondary' }}">
                                            {{ $provider->enabled ? 'Sim' : 'Nao' }}
                                        </span>
                                    </td>
                                    <td>{{ $provider->is_default ? 'Sim' : 'Nao' }}</td>
                                    <td>{{ $provider->blueprint_configs_count }}</td>
                                    <td>{{ $provider->hasApiKey() ? 'Sim' : 'Nao' }}</td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-2">
                                            <a href="{{ route('ai-grader.ai-providers.show', $provider) }}" class="btn btn-sm btn-outline-primary">Ver</a>
                                            <a href="{{ route('ai-grader.ai-providers.edit', $provider) }}" class="btn btn-sm btn-primary">Editar</a>
                                            <form method="POST" action="{{ route('ai-grader.ai-providers.test', $provider) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">Testar</button>
                                            </form>
                                            <form method="POST" action="{{ route('ai-grader.ai-providers.destroy', $provider) }}" onsubmit="return confirm('Remover este provider?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Excluir</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="text-center text-muted">Nenhum provider cadastrado.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($providers->hasPages())
                    <div class="mt-4">
                        {{ $providers->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
