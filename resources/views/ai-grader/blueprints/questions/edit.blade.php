@extends('layouts.ai-grader')

@section('title')
    GraderAI - Questão
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            GraderAI
        @endslot
        @slot('title')
            Questão discursiva
        @endslot
    @endcomponent

    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">
                    <div>
                        <h4 class="mb-1">{{ $questionConfig->canvas_question_name ?: $questionConfig->canvas_question_id }}</h4>
                        <p class="text-muted mb-0">{{ $quizConfig->canvas_quiz_title }}</p>
                    </div>
                    <a href="{{ route('ai-grader.blueprints.quizzes.show', [$blueprintConfig, $quizConfig]) }}" class="btn btn-light">Voltar</a>
                </div>
            </div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger">
                Revise os campos destacados antes de salvar.
            </div>
        @endif

        <div class="row">
            <div class="col-lg-5 mb-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title">Snapshot do Canvas</h5>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <tbody>
                                    <tr>
                                        <th>Question ID</th>
                                        <td>{{ $questionConfig->canvas_question_id }}</td>
                                    </tr>
                                    <tr>
                                        <th>Nome</th>
                                        <td>{{ $questionConfig->canvas_question_name ?: '-' }}</td>
                                    </tr>
                                    <tr>
                                        <th>Tipo</th>
                                        <td>{{ $questionConfig->canvas_question_type }}</td>
                                    </tr>
                                    <tr>
                                        <th>Pontuação máxima</th>
                                        <td>{{ $questionConfig->canvas_points_possible ?? '-' }}</td>
                                    </tr>
                                    <tr>
                                        <th>Versão das orientações</th>
                                        <td>{{ $questionConfig->instructions_version }}</td>
                                    </tr>
                                    <tr>
                                        <th>Atualizado em</th>
                                        <td>{{ $questionConfig->instructions_updated_at?->format('d/m/Y H:i') ?? '-' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <hr>

                        <h6>Enunciado</h6>
                        <div class="border rounded p-3 mb-3 bg-light">
                            {!! $questionConfig->canvas_question_text ?: '<span class="text-muted">Sem enunciado sincronizado.</span>' !!}
                        </div>

                        <h6>Comentários gerais do Canvas</h6>
                        <p class="text-muted small mb-2">
                            Este campo vem do Canvas e pode ficar visível ao aluno. Não será usado como orientação oficial da IA.
                        </p>
                        <div class="border rounded p-3 bg-light">
                            {!! $questionConfig->canvas_neutral_comments_snapshot ?: '<span class="text-muted">Nenhum comentário geral sincronizado.</span>' !!}
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7 mb-4">
                <div class="card h-100">
                    <div class="card-body">
                        <form method="POST" action="{{ route('ai-grader.blueprints.questions.update', [$blueprintConfig, $quizConfig, $questionConfig]) }}">
                            @csrf
                            @method('PUT')

                            <div class="form-check form-switch mb-4">
                                <input type="checkbox" name="enabled" id="enabled" value="1" class="form-check-input @error('enabled') is-invalid @enderror" @checked(old('enabled', $questionConfig->enabled)) @disabled($questionConfig->canvas_question_type !== \App\Models\AiGraderQuestionConfig::TYPE_ESSAY)>
                                <label for="enabled" class="form-check-label">Habilitar questão para correção por IA</label>
                                @if ($questionConfig->canvas_question_type !== \App\Models\AiGraderQuestionConfig::TYPE_ESSAY)
                                    <div class="form-text">Questões objetivas não podem ser habilitadas para correção por IA.</div>
                                @endif
                                @error('enabled')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-4">
                                <label for="ai_grading_instructions" class="form-label">Orientações de correção para IA</label>
                                <textarea name="ai_grading_instructions" id="ai_grading_instructions" rows="14" class="form-control @error('ai_grading_instructions') is-invalid @enderror">{{ old('ai_grading_instructions', $questionConfig->ai_grading_instructions) }}</textarea>
                                <div class="form-text">
                                    @lang('ai-grader.admin.questions.private_instructions_help')
                                </div>
                                @error('ai_grading_instructions')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Salvar questão</button>
                                <a href="{{ route('ai-grader.blueprints.quizzes.show', [$blueprintConfig, $quizConfig]) }}" class="btn btn-light">Cancelar</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
