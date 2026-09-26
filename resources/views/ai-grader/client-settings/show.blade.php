@extends('layouts.ai-grader')

@section('title')
    GraderAI - {{ $organization->name }}
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            GraderAI
        @endslot
        @slot('title')
            {{ $organization->name }}
        @endslot
    @endcomponent

    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">
                    <div>
                        <h4 class="mb-1">Configuração do GraderAI</h4>
                        <p class="text-muted mb-0">{{ $summary['state_message'] }}</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="{{ route('ai-grader.client-settings.index') }}" class="btn btn-light">Voltar</a>
                        <a href="{{ route('ai-grader.client-settings.edit', $organization) }}" class="btn btn-primary">
                            {{ $setting ? 'Editar' : 'Configurar' }}
                        </a>
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

        @if (! $setting)
            <div class="alert alert-warning">Módulo inativo para esta organização. Nenhuma configuração foi criada ainda.</div>
        @elseif (($summary['available'] ?? 0) <= 0 && ($summary['total_quota'] ?? 0) > 0)
            <div class="alert alert-danger">Cota esgotada. Novas respostas discursivas ficarão pendentes por limite.</div>
        @endif

        <div class="row">
            @foreach ([
                'Status' => $summary['display_status'] ?? 'Não configurado',
                'Plano' => $setting?->plan_name ?: '-',
                'Cota trial' => $setting?->trial_quota_total ?? 0,
                'Cota contratada' => $setting?->purchased_quota_total ?? 0,
                'Cota usada' => $setting?->quota_used ?? 0,
                'Cota reservada' => $setting?->quota_reserved ?? 0,
                'Saldo disponível' => $summary['available'] ?? 0,
                'Percentual de uso' => number_format((float) ($summary['used_percent'] ?? 0), 1, ',', '.') . '%',
            ] as $label => $value)
                <div class="col-md-3 mb-3">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="text-muted small">{{ $label }}</div>
                            <div class="h5 mb-0">{{ $value }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="row">
            <div class="col-lg-12 mb-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title">Vigência</h5>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <tbody>
                                    <tr>
                                        <th>Início</th>
                                        <td>{{ $setting?->starts_at?->format('d/m/Y H:i') ?? '-' }}</td>
                                    </tr>
                                    <tr>
                                        <th>Fim</th>
                                        <td>{{ $setting?->ends_at?->format('d/m/Y H:i') ?? '-' }}</td>
                                    </tr>
                                    <tr>
                                        <th>Reset de cota</th>
                                        <td>{{ $setting?->quota_reset_at?->format('d/m/Y H:i') ?? '-' }}</td>
                                    </tr>
                                    <tr>
                                        <th>Observações internas</th>
                                        <td>{{ $setting?->notes ?: '-' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Contadores</h5>
                <div class="row">
                    @foreach ([
                        'Blueprints configuradas' => $counters['blueprints'],
                        'Quizzes configurados' => $counters['quizzes'],
                        'Questões configuradas' => $counters['questions'],
                        'Itens de correção' => $counters['correction_items'],
                        'Pendentes por cota' => $counters['pending_quota'],
                        'Publicados no Canvas' => $counters['published_to_canvas'],
                    ] as $label => $value)
                        <div class="col-md-2 mb-3">
                            <div class="text-muted small">{{ $label }}</div>
                            <div class="h4 mb-0">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endsection
