@extends('layouts.ai-grader')

@section('title')
    GraderAI - Clientes
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            Início
        @endslot
        @slot('title')
            GraderAI - Clientes
        @endslot
    @endcomponent

    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">
                    <div>
                        <h4 class="mb-1">Habilitação do GraderAI</h4>
                        <p class="text-muted mb-0">Configure a disponibilidade global do módulo por organização.</p>
                    </div>
                </div>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            </div>
        @endif

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-nowrap align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Organização</th>
                                <th>Habilitado</th>
                                <th>Status</th>
                                <th>Plano</th>
                                <th>Cota trial</th>
                                <th>Cota contratada</th>
                                <th>Usada</th>
                                <th>Reservada</th>
                                <th>Saldo</th>
                                <th>Uso</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($organizations as $organization)
                                @php
                                    $setting = $organization->aiGraderClientSetting;
                                    $summary = $summaries[$organization->id] ?? ['available' => 0, 'used_percent' => 0];
                                @endphp
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $organization->name }}</div>
                                        <div class="text-muted small">{{ $organization->slug }}</div>
                                    </td>
                                    <td>
                                        @if ($setting?->enabled)
                                            <span class="badge bg-success-subtle text-success">Sim</span>
                                        @else
                                            <span class="badge bg-secondary-subtle text-secondary">Não</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($setting)
                                            <span class="badge bg-{{ $summary['state_tone'] }}-subtle text-{{ $summary['state_tone'] }}">
                                                {{ $summary['display_status'] }}
                                            </span>
                                        @else
                                            <span class="badge bg-light text-dark">Não configurado</span>
                                        @endif
                                    </td>
                                    <td>{{ $setting?->plan_name ?: '-' }}</td>
                                    <td>{{ $setting?->trial_quota_total ?? '-' }}</td>
                                    <td>{{ $setting?->purchased_quota_total ?? '-' }}</td>
                                    <td>{{ $setting?->quota_used ?? '-' }}</td>
                                    <td>{{ $setting?->quota_reserved ?? '-' }}</td>
                                    <td>{{ $setting ? $summary['available'] : '-' }}</td>
                                    <td>{{ $setting ? number_format((float) $summary['used_percent'], 1, ',', '.') . '%' : '-' }}</td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <a href="{{ route('ai-grader.client-settings.show', $organization) }}" class="btn btn-sm btn-outline-primary">
                                                Visualizar
                                            </a>
                                            <a href="{{ route('ai-grader.client-settings.edit', $organization) }}" class="btn btn-sm btn-primary">
                                                {{ $setting ? 'Editar' : 'Configurar' }}
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="text-center text-muted">Nenhuma organização cadastrada.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($organizations->hasPages())
                    <div class="mt-4">
                        {{ $organizations->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
