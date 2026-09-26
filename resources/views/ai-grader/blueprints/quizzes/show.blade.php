@extends('layouts.ai-grader')

@section('title')
    GraderAI - Quiz
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            GraderAI
        @endslot
        @slot('title')
            Quiz
        @endslot
    @endcomponent

    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">
                    <div>
                        <h4 class="mb-1">{{ $quizConfig->canvas_quiz_title ?: $quizConfig->canvas_quiz_id }}</h4>
                        <p class="text-muted mb-0">Classic Quiz da blueprint {{ $blueprintConfig->blueprint_course_id }}</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="{{ route('ai-grader.blueprints.show', $blueprintConfig) }}" class="btn btn-light">Voltar</a>
                        <form method="POST" action="{{ route('ai-grader.blueprints.quizzes.sync-questions', [$blueprintConfig, $quizConfig]) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-primary">Sincronizar questões</button>
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
                'Quiz ID' => $quizConfig->canvas_quiz_id,
                'Assignment ID' => $quizConfig->canvas_assignment_id ?: '-',
                'Pontos' => $quizConfig->points_possible ?? '-',
                'Quantidade de questões' => $quizConfig->question_count ?? '-',
                'Última sincronização' => $quizConfig->last_synced_at?->format('d/m/Y H:i') ?? '-',
                'Pronto para processar' => $status['ready'] ? 'Sim' : 'Não',
            ] as $label => $value)
                <div class="col-md-2 mb-3">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="text-muted small">{{ $label }}</div>
                            <div class="h5 mb-0">{{ $value }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <form method="POST" action="{{ route('ai-grader.blueprints.quizzes.update', [$blueprintConfig, $quizConfig]) }}">
                    @csrf
                    @method('PUT')

                    <div class="row align-items-center">
                        <div class="col-md-3">
                            <div class="form-check form-switch">
                                <input type="checkbox" name="enabled" id="enabled" value="1" class="form-check-input" @checked(old('enabled', $quizConfig->enabled))>
                                <label for="enabled" class="form-check-label">Quiz habilitado</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check form-switch">
                                <input type="checkbox" name="correction_enabled" id="correction_enabled" value="1" class="form-check-input" @checked(old('correction_enabled', $quizConfig->correction_enabled))>
                                <label for="correction_enabled" class="form-check-label">Correção habilitada</label>
                            </div>
                        </div>
                        <div class="col-md-6 text-end">
                            <button type="submit" class="btn btn-primary">Salvar quiz</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="row">
            @foreach ([
                'Discursivas totais' => $status['essay_questions'],
                'Discursivas habilitadas' => $status['enabled_essay_questions'],
                'Habilitadas sem orientação' => $status['enabled_without_instructions'],
                'Objetivas' => $status['objective_questions'],
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

        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Questões</h5>
                <div class="table-responsive">
                    <table class="table table-nowrap align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Question ID</th>
                                <th>Nome</th>
                                <th>Tipo</th>
                                <th>Pontos</th>
                                <th>Habilitada</th>
                                <th>Orientações de IA</th>
                                <th>Última sincronização</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($quizConfig->questionConfigs as $questionConfig)
                                <tr>
                                    <td>{{ $questionConfig->canvas_question_id }}</td>
                                    <td>{{ $questionConfig->canvas_question_name ?: '-' }}</td>
                                    <td>
                                        {{ $questionConfig->canvas_question_type }}
                                        @if ($questionConfig->canvas_question_type !== \App\Models\AiGraderQuestionConfig::TYPE_ESSAY)
                                            <span class="badge bg-light text-dark ms-1">não elegível</span>
                                        @endif
                                    </td>
                                    <td>{{ $questionConfig->canvas_points_possible ?? '-' }}</td>
                                    <td>{{ $questionConfig->enabled ? 'Sim' : 'Não' }}</td>
                                    <td>{{ trim((string) $questionConfig->ai_grading_instructions) !== '' ? 'Sim' : 'Não' }}</td>
                                    <td>{{ $questionConfig->last_synced_at?->format('d/m/Y H:i') ?? '-' }}</td>
                                    <td>
                                        <a href="{{ route('ai-grader.blueprints.questions.edit', [$blueprintConfig, $quizConfig, $questionConfig]) }}" class="btn btn-sm btn-outline-primary">Editar</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center text-muted">Nenhuma questão sincronizada.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
