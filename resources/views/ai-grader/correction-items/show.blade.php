@extends('layouts.ai-grader')

@section('title')
    GraderAI - Correção #{{ $correctionItem->id }}
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            GraderAI
        @endslot
        @slot('title')
            Correção #{{ $correctionItem->id }}
        @endslot
    @endcomponent

    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12 d-flex justify-content-between align-items-start">
                <div>
                    <h4 class="mb-1">Item de correção #{{ $correctionItem->id }}</h4>
                    <div class="text-muted">Visualização operacional e read-only da resposta coletada.</div>
                </div>
                <a href="{{ route('ai-grader.correction-items.index') }}" class="btn btn-light">Voltar</a>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0">A. Dados gerais</h5>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">ID</dt>
                            <dd class="col-sm-8">{{ $correctionItem->id }}</dd>
                            <dt class="col-sm-4">Status</dt>
                            <dd class="col-sm-8"><span class="badge bg-light text-dark">{{ $correctionItem->status }}</span></dd>
                            <dt class="col-sm-4">Organização</dt>
                            <dd class="col-sm-8">{{ $correctionItem->organization?->name ?: '-' }}</dd>
                            <dt class="col-sm-4">Environment</dt>
                            <dd class="col-sm-8">{{ $correctionItem->canvasEnvironment?->name ?: '-' }}</dd>
                            <dt class="col-sm-4">Batch</dt>
                            <dd class="col-sm-8">{{ $correctionItem->ai_grader_batch_id ?: '-' }}</dd>
                            <dt class="col-sm-4">Criado em</dt>
                            <dd class="col-sm-8">{{ $correctionItem->created_at?->format('Y-m-d H:i:s') ?: '-' }}</dd>
                            <dt class="col-sm-4">Atualizado em</dt>
                            <dd class="col-sm-8">{{ $correctionItem->updated_at?->format('Y-m-d H:i:s') ?: '-' }}</dd>
                        </dl>
                    </div>
                </div>
            </div>

            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0">B. Dados Canvas</h5>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Blueprint course_id</dt>
                            <dd class="col-sm-7">{{ $correctionItem->blueprint_course_id ?: '-' }}</dd>
                            <dt class="col-sm-5">Child course</dt>
                            <dd class="col-sm-7">{{ $correctionItem->child_course_name ?: $correctionItem->child_course_id ?: '-' }}</dd>
                            <dt class="col-sm-5">Quiz ID</dt>
                            <dd class="col-sm-7">{{ $correctionItem->canvas_quiz_id ?: '-' }}</dd>
                            <dt class="col-sm-5">Assignment ID</dt>
                            <dd class="col-sm-7">{{ $correctionItem->canvas_assignment_id ?: '-' }}</dd>
                            <dt class="col-sm-5">Quiz submission ID</dt>
                            <dd class="col-sm-7">{{ $correctionItem->canvas_quiz_submission_id ?: '-' }}</dd>
                            <dt class="col-sm-5">Assignment submission ID</dt>
                            <dd class="col-sm-7">{{ $correctionItem->canvas_assignment_submission_id ?: '-' }}</dd>
                            <dt class="col-sm-5">Tentativa</dt>
                            <dd class="col-sm-7">{{ $correctionItem->attempt ?: '-' }}</dd>
                            <dt class="col-sm-5">Aluno</dt>
                            <dd class="col-sm-7">
                                <div>{{ $correctionItem->canvas_user_name ?: '-' }}</div>
                                <div class="text-muted small">{{ $correctionItem->canvas_user_id }} {{ $correctionItem->canvas_user_login_id ? '- '.$correctionItem->canvas_user_login_id : '' }}</div>
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">C. Questão</h5>
            </div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-md-3">Question config ID</dt>
                    <dd class="col-md-9">{{ $correctionItem->ai_grader_question_config_id ?: '-' }}</dd>
                    <dt class="col-md-3">Canvas question ID</dt>
                    <dd class="col-md-9">{{ $correctionItem->canvas_question_id ?: '-' }}</dd>
                    <dt class="col-md-3">Nome</dt>
                    <dd class="col-md-9">{{ $correctionItem->canvas_question_name ?: '-' }}</dd>
                    <dt class="col-md-3">Pontos possíveis</dt>
                    <dd class="col-md-9">{{ $correctionItem->canvas_points_possible ?? '-' }}</dd>
                    <dt class="col-md-3">Modo de publicação</dt>
                    <dd class="col-md-9">{{ $correctionItem->publication_mode ?: '-' }}</dd>
                </dl>
                <div class="text-muted small mb-2">Enunciado</div>
                <pre class="bg-light border rounded p-3 mb-0 text-wrap" style="white-space: pre-wrap;">{{ $correctionItem->canvas_question_text ?: '-' }}</pre>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">D. Resposta do aluno</h5>
            </div>
            <div class="card-body">
                <div class="text-muted small mb-2">Texto tratado para leitura</div>
                <pre class="bg-light border rounded p-3 text-wrap" style="white-space: pre-wrap;">{{ $correctionItem->answer_text ?: '-' }}</pre>

                <details>
                    <summary class="text-muted">answer_html bruto</summary>
                    <pre class="bg-light border rounded p-3 mt-3 mb-0 text-wrap" style="white-space: pre-wrap;">{{ $correctionItem->answer_html ?: '-' }}</pre>
                </details>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">E. Instruções de correção</h5>
            </div>
            <div class="card-body">
                <div class="mb-3"><strong>Versão:</strong> {{ $correctionItem->instructions_version ?: '-' }}</div>
                <pre class="bg-light border rounded p-3 mb-0 text-wrap" style="white-space: pre-wrap;">{{ $correctionItem->ai_grading_instructions_snapshot ?: '-' }}</pre>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">F. Dados de IA</h5>
            </div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-md-3">Nota sugerida</dt>
                    <dd class="col-md-9">{{ $correctionItem->ai_score ?? '-' }}</dd>
                    <dt class="col-md-3">Confiança</dt>
                    <dd class="col-md-9">{{ $correctionItem->ai_confidence ?? '-' }}</dd>
                    <dt class="col-md-3">Feedback</dt>
                    <dd class="col-md-9"><pre class="bg-light border rounded p-3 mb-0 text-wrap" style="white-space: pre-wrap;">{{ $correctionItem->ai_feedback ?: '-' }}</pre></dd>
                    <dt class="col-md-3">Flags</dt>
                    <dd class="col-md-9"><pre class="bg-light border rounded p-3 mb-0 text-wrap" style="white-space: pre-wrap;">{{ $aiReviewFlagsJson }}</pre></dd>
                    <dt class="col-md-3">Raw response</dt>
                    <dd class="col-md-9"><pre class="bg-light border rounded p-3 mb-0 text-wrap" style="white-space: pre-wrap;">{{ $aiRawResponseJson }}</pre></dd>
                </dl>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">G. Nota final e publicação</h5>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-md-3">Nota final</dt>
                    <dd class="col-md-9">{{ $correctionItem->final_score ?? '-' }}</dd>
                    <dt class="col-md-3">Feedback final</dt>
                    <dd class="col-md-9"><pre class="bg-light border rounded p-3 mb-0 text-wrap" style="white-space: pre-wrap;">{{ $correctionItem->final_feedback ?: '-' }}</pre></dd>
                    <dt class="col-md-3">Nota publicada</dt>
                    <dd class="col-md-9">{{ $correctionItem->canvas_published_score ?? '-' }}</dd>
                    <dt class="col-md-3">Feedback publicado</dt>
                    <dd class="col-md-9"><pre class="bg-light border rounded p-3 mb-0 text-wrap" style="white-space: pre-wrap;">{{ $correctionItem->canvas_published_feedback ?: '-' }}</pre></dd>
                    <dt class="col-md-3">Publicado em</dt>
                    <dd class="col-md-9">{{ $correctionItem->published_at?->format('Y-m-d H:i:s') ?: '-' }}</dd>
                </dl>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">H. Metadata e falhas</h5>
            </div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-md-3">Falhou em</dt>
                    <dd class="col-md-9">{{ $correctionItem->failed_at?->format('Y-m-d H:i:s') ?: '-' }}</dd>
                    <dt class="col-md-3">Motivo</dt>
                    <dd class="col-md-9">{{ $correctionItem->failure_reason ?: '-' }}</dd>
                </dl>
                <pre class="bg-light border rounded p-3 mb-0 text-wrap" style="white-space: pre-wrap;">{{ $metadataJson }}</pre>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">I. Logs relacionados</h5>
            </div>
            <div class="card-body">
                <h6>Publicações Canvas</h6>
                <div class="table-responsive mb-4">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Método</th>
                                <th>Endpoint</th>
                                <th>Status HTTP</th>
                                <th>Sucesso</th>
                                <th>Nota</th>
                                <th>Erro</th>
                                <th>Criado em</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($correctionItem->publicationLogs as $log)
                                <tr>
                                    <td>{{ $log->id }}</td>
                                    <td>{{ $log->http_method }}</td>
                                    <td>{{ $log->endpoint }}</td>
                                    <td>{{ $log->response_status ?: '-' }}</td>
                                    <td>{{ $log->success ? 'sim' : 'não' }}</td>
                                    <td>{{ $log->published_score ?? '-' }}</td>
                                    <td>{{ $log->error_message ?: '-' }}</td>
                                    <td>{{ $log->created_at?->format('Y-m-d H:i:s') ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-muted text-center">Nenhum log de publicação.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <h6>Uso e cotas</h6>
                <div class="table-responsive mb-4">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tipo</th>
                                <th>Quantidade</th>
                                <th>Status</th>
                                <th>Tokens</th>
                                <th>Custo estimado</th>
                                <th>Criado em</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($correctionItem->usageLogs as $log)
                                <tr>
                                    <td>{{ $log->id }}</td>
                                    <td>{{ $log->usage_type }}</td>
                                    <td>{{ $log->quantity }}</td>
                                    <td>{{ $log->status ?: '-' }}</td>
                                    <td>{{ $log->total_tokens ?? '-' }}</td>
                                    <td>{{ $log->estimated_cost ?? '-' }} {{ $log->currency }}</td>
                                    <td>{{ $log->created_at?->format('Y-m-d H:i:s') ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-muted text-center">Nenhum log de uso.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <h6>Ações de professor</h6>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Ator</th>
                                <th>Ação</th>
                                <th>Nota anterior</th>
                                <th>Nova nota</th>
                                <th>Criado em</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($correctionItem->teacherActions as $action)
                                <tr>
                                    <td>{{ $action->id }}</td>
                                    <td>{{ $action->actor?->name ?: $action->actor_user_id ?: '-' }}</td>
                                    <td>{{ $action->action }}</td>
                                    <td>{{ $action->previous_score ?? '-' }}</td>
                                    <td>{{ $action->new_score ?? '-' }}</td>
                                    <td>{{ $action->created_at?->format('Y-m-d H:i:s') ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-muted text-center">Nenhuma ação registrada.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
