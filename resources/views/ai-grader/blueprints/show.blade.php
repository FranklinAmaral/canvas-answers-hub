@extends('layouts.ai-grader')

@section('title')
    GraderAI - Blueprint
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            GraderAI
        @endslot
        @slot('title')
            Blueprint
        @endslot
    @endcomponent

    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">
                    <div>
                        <h4 class="mb-1">{{ $blueprintConfig->blueprint_course_name ?: $blueprintConfig->blueprint_course_id }}</h4>
                        <p class="text-muted mb-0">{{ $blueprintConfig->organization?->name }} / {{ $blueprintConfig->canvasEnvironment?->name }}</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="{{ route('ai-grader.blueprints.index') }}" class="btn btn-light">Voltar</a>
                        <a href="{{ route('ai-grader.blueprints.edit', $blueprintConfig) }}" class="btn btn-primary">Editar</a>
                        <form method="POST" action="{{ route('ai-grader.blueprints.sync-quizzes', $blueprintConfig) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-primary">Sincronizar quizzes do Canvas</button>
                        </form>
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

        @if (session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            </div>
        @endif

        <div class="row">
            @foreach ([
                'Organização' => $blueprintConfig->organization?->name,
                'Environment' => $blueprintConfig->canvasEnvironment?->name,
                'Blueprint course_id' => $blueprintConfig->blueprint_course_id,
                'Status' => $blueprintConfig->enabled ? 'Habilitada' : 'Inativa',
                'Modo de publicação' => $blueprintConfig->publication_mode,
                'Gatilho' => $blueprintConfig->trigger_mode,
                'Próxima execução/data' => $blueprintConfig->scheduled_at?->format('d/m/Y H:i') ?? '-',
                'Pronta para processar' => $status['ready'] ? 'Sim' : 'Não',
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
            @foreach ([
                'Quizzes configurados' => $status['quizzes'],
                'Questões discursivas configuradas' => $status['essay_questions'],
                'Questões sem orientação de IA' => $status['questions_without_instructions'],
                'Itens de correção vinculados' => $status['correction_items'],
            ] as $label => $value)
                <div class="col-md-3 mb-3">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="text-muted small">{{ $label }}</div>
                            <div class="h4 mb-0">{{ $value }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Permissões do professor</h5>
                <div class="row">
                    @foreach ([
                        'Ajustar nota' => $blueprintConfig->teacher_can_adjust_score,
                        'Ajustar feedback' => $blueprintConfig->teacher_can_adjust_feedback,
                        'Publicar' => $blueprintConfig->teacher_can_publish,
                        'Republicar' => $blueprintConfig->teacher_can_republish,
                        'Exportar' => $blueprintConfig->teacher_can_export,
                        __('ai-grader.lti.blueprint.teacher_prompt_visibility_short') => $blueprintConfig->teacher_can_view_grading_prompt,
                    ] as $label => $value)
                        <div class="col-md-2 mb-2">
                            <span class="text-muted small">{{ $label }}</span>
                            <div>{{ $value ? 'Sim' : 'Não' }}</div>
                        </div>
                    @endforeach
                    <div class="col-md-2 mb-2">
                        <span class="text-muted small">Provider</span>
                        <div>{{ $blueprintConfig->aiProvider?->name ?: 'Padrão da organização' }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Quizzes</h5>
                <div class="table-responsive">
                    <table class="table table-nowrap align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Título</th>
                                <th>Quiz ID</th>
                                <th>Assignment ID</th>
                                <th>Tipo</th>
                                <th>Pontos</th>
                                <th>Questões</th>
                                <th>Habilitado</th>
                                <th>Correção</th>
                                <th>Última sincronização</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($blueprintConfig->quizConfigs as $quizConfig)
                                <tr>
                                    <td>{{ $quizConfig->canvas_quiz_title ?: '-' }}</td>
                                    <td>{{ $quizConfig->canvas_quiz_id }}</td>
                                    <td>{{ $quizConfig->canvas_assignment_id ?: '-' }}</td>
                                    <td>{{ $quizConfig->canvas_quiz_type ?: '-' }}</td>
                                    <td>{{ $quizConfig->points_possible ?? '-' }}</td>
                                    <td>{{ $quizConfig->question_count ?? '-' }}</td>
                                    <td>{{ $quizConfig->enabled ? 'Sim' : 'Não' }}</td>
                                    <td>{{ $quizConfig->correction_enabled ? 'Sim' : 'Não' }}</td>
                                    <td>{{ $quizConfig->last_synced_at?->format('d/m/Y H:i') ?? '-' }}</td>
                                    <td>
                                        <a href="{{ route('ai-grader.blueprints.quizzes.show', [$blueprintConfig, $quizConfig]) }}" class="btn btn-sm btn-outline-primary">Abrir</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center text-muted">Nenhum quiz sincronizado.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
