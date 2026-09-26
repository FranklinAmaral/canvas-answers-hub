@extends('layouts.ai-grader')

@section('title')
    GraderAI - Correções
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            GraderAI
        @endslot
        @slot('title')
            Correções
        @endslot
    @endcomponent

    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-title-box">
                    <h4 class="mb-1">Itens de correção do GraderAI</h4>
                    <p class="text-muted mb-0">Auditoria das respostas discursivas coletadas para correção.</p>
                </div>
            </div>
        </div>

        <div class="row">
            @foreach ([
                'Total de itens' => $summary['total'],
                'Pending' => $summary['pending'],
                'Pending quota' => $summary['pending_quota'],
                'Skipped blank answer' => $summary['skipped_blank_answer'],
                'AI corrected' => $summary['ai_corrected'],
                'Pending teacher approval' => $summary['pending_teacher_approval'],
                'Published to Canvas' => $summary['published_to_canvas'],
                'Failed' => $summary['failed'],
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
                <form method="GET" action="{{ route('ai-grader.correction-items.index') }}" class="row align-items-end">
                    <div class="col-md-3 mb-3">
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
                    <div class="col-md-3 mb-3">
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
                    <div class="col-md-3 mb-3">
                        <label for="ai_grader_blueprint_config_id" class="form-label">Blueprint</label>
                        <select name="ai_grader_blueprint_config_id" id="ai_grader_blueprint_config_id" class="form-select">
                            <option value="">Todas</option>
                            @foreach ($blueprints as $blueprint)
                                <option value="{{ $blueprint->id }}" @selected((string) request('ai_grader_blueprint_config_id') === (string) $blueprint->id)>
                                    #{{ $blueprint->id }} - {{ $blueprint->blueprint_course_name ?: $blueprint->blueprint_course_id }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="ai_grader_quiz_config_id" class="form-label">Quiz config</label>
                        <select name="ai_grader_quiz_config_id" id="ai_grader_quiz_config_id" class="form-select">
                            <option value="">Todos</option>
                            @foreach ($quizzes as $quiz)
                                <option value="{{ $quiz->id }}" @selected((string) request('ai_grader_quiz_config_id') === (string) $quiz->id)>
                                    #{{ $quiz->id }} - {{ $quiz->canvas_quiz_title ?: $quiz->canvas_quiz_id }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select name="status" id="status" class="form-select">
                            <option value="">Todos</option>
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="child_course_id" class="form-label">Child course_id</label>
                        <input type="text" name="child_course_id" id="child_course_id" class="form-control" value="{{ request('child_course_id') }}">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="canvas_user_id" class="form-label">Aluno</label>
                        <input type="text" name="canvas_user_id" id="canvas_user_id" class="form-control" value="{{ request('canvas_user_id') }}">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="canvas_quiz_submission_id" class="form-label">Quiz submission</label>
                        <input type="text" name="canvas_quiz_submission_id" id="canvas_quiz_submission_id" class="form-control" value="{{ request('canvas_quiz_submission_id') }}">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="canvas_question_id" class="form-label">Question ID</label>
                        <input type="text" name="canvas_question_id" id="canvas_question_id" class="form-control" value="{{ request('canvas_question_id') }}">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="q" class="form-label">Busca</label>
                        <input type="text" name="q" id="q" class="form-control" value="{{ request('q') }}">
                    </div>
                    <div class="col-md-2 mb-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Filtrar</button>
                        <a href="{{ route('ai-grader.correction-items.index') }}" class="btn btn-light">Limpar</a>
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
                                <th>ID</th>
                                <th>Status</th>
                                <th>Organização</th>
                                <th>Environment</th>
                                <th>Disciplina filha</th>
                                <th>Aluno</th>
                                <th>Quiz</th>
                                <th>Questão</th>
                                <th>Tentativa</th>
                                <th>Pontos</th>
                                <th>Resposta</th>
                                <th>Publicação</th>
                                <th>Criado em</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($items as $item)
                                <tr>
                                    <td>{{ $item->id }}</td>
                                    <td><span class="badge bg-light text-dark">{{ $item->status }}</span></td>
                                    <td>{{ $item->organization?->name ?: '-' }}</td>
                                    <td>{{ $item->canvasEnvironment?->name ?: '-' }}</td>
                                    <td>{{ $item->child_course_name ?: $item->child_course_id }}</td>
                                    <td>
                                        <div>{{ $item->canvas_user_name ?: '-' }}</div>
                                        <div class="text-muted small">{{ $item->canvas_user_id }}</div>
                                    </td>
                                    <td>{{ $item->quizConfig?->canvas_quiz_title ?: $item->canvas_quiz_id }}</td>
                                    <td>
                                        <div>{{ $item->canvas_question_name ?: '-' }}</div>
                                        <div class="text-muted small">{{ $item->canvas_question_id }}</div>
                                    </td>
                                    <td>{{ $item->attempt }}</td>
                                    <td>{{ $item->canvas_points_possible ?? '-' }}</td>
                                    <td>{{ mb_strlen((string) $item->answer_text) }}</td>
                                    <td>{{ $item->publication_mode ?: '-' }}</td>
                                    <td>{{ $item->created_at?->format('d/m/Y H:i') ?? '-' }}</td>
                                    <td>
                                        <a href="{{ route('ai-grader.correction-items.show', $item) }}" class="btn btn-sm btn-outline-primary">Ver detalhes</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="14" class="text-center text-muted">Nenhum item de correção encontrado.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($items->hasPages())
                    <div class="mt-4">
                        {{ $items->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
