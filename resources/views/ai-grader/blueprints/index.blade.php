@extends('layouts.ai-grader')

@section('title')
    GraderAI - Blueprints
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            GraderAI
        @endslot
        @slot('title')
            Blueprints
        @endslot
    @endcomponent

    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">
                    <div>
                        <h4 class="mb-1">Blueprints do GraderAI</h4>
                        <p class="text-muted mb-0">Configure Classic Quizzes e questões discursivas por blueprint.</p>
                    </div>
                    <a href="{{ route('ai-grader.blueprints.create') }}" class="btn btn-primary">Nova blueprint</a>
                </div>
            </div>
        </div>

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

        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="{{ route('ai-grader.blueprints.index') }}" class="row align-items-end">
                    <div class="col-md-4 mb-3">
                        <label for="organization_id" class="form-label">Organização</label>
                        <select name="organization_id" id="organization_id" class="form-select">
                            <option value="">Todas</option>
                            @foreach ($organizations as $organization)
                                <option value="{{ $organization->id }}" @selected((string) request('organization_id') === (string) $organization->id)>
                                    {{ $organization->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="canvas_environment_id" class="form-label">Environment</label>
                        <select name="canvas_environment_id" id="canvas_environment_id" class="form-select">
                            <option value="">Todos</option>
                            @foreach ($environments as $environment)
                                <option value="{{ $environment->id }}" @selected((string) request('canvas_environment_id') === (string) $environment->id)>
                                    {{ $environment->name }} - {{ $environment->organization?->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="enabled" class="form-label">Status</label>
                        <select name="enabled" id="enabled" class="form-select">
                            <option value="">Todos</option>
                            <option value="1" @selected(request('enabled') === '1')>Habilitado</option>
                            <option value="0" @selected(request('enabled') === '0')>Inativo</option>
                        </select>
                    </div>
                    <div class="col-md-2 mb-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Filtrar</button>
                        <a href="{{ route('ai-grader.blueprints.index') }}" class="btn btn-light">Limpar</a>
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
                                <th>Organização</th>
                                <th>Environment</th>
                                <th>Blueprint course_id</th>
                                <th>Nome</th>
                                <th>Status</th>
                                <th>Publicação</th>
                                <th>Gatilho</th>
                                <th>Quizzes</th>
                                <th>Discursivas habilitadas</th>
                                <th>Última sincronização</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($configs as $config)
                                @php
                                    $status = $statuses[$config->id] ?? [];
                                    $lastSyncedAt = $config->quizConfigs
                                        ->pluck('last_synced_at')
                                        ->filter()
                                        ->sortDesc()
                                        ->first();
                                @endphp
                                <tr>
                                    <td>{{ $config->organization?->name }}</td>
                                    <td>{{ $config->canvasEnvironment?->name }}</td>
                                    <td>{{ $config->blueprint_course_id }}</td>
                                    <td>{{ $config->blueprint_course_name ?: '-' }}</td>
                                    <td>
                                        @if ($config->enabled)
                                            <span class="badge bg-success-subtle text-success">Habilitado</span>
                                        @else
                                            <span class="badge bg-secondary-subtle text-secondary">Inativo</span>
                                        @endif
                                    </td>
                                    <td>{{ $config->publication_mode }}</td>
                                    <td>{{ $config->trigger_mode }}</td>
                                    <td>{{ $status['quizzes'] ?? 0 }}</td>
                                    <td>{{ $status['enabled_essay_questions'] ?? 0 }}</td>
                                    <td>{{ $lastSyncedAt ? $lastSyncedAt->format('d/m/Y H:i') : '-' }}</td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <a href="{{ route('ai-grader.blueprints.show', $config) }}" class="btn btn-sm btn-outline-primary">Visualizar</a>
                                            <a href="{{ route('ai-grader.blueprints.edit', $config) }}" class="btn btn-sm btn-primary">Editar</a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="text-center text-muted">Nenhuma blueprint configurada.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($configs->hasPages())
                    <div class="mt-4">
                        {{ $configs->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
