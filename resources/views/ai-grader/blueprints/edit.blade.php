@extends('layouts.ai-grader')

@section('title')
    GraderAI - Editar blueprint
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            GraderAI
        @endslot
        @slot('title')
            Editar blueprint
        @endslot
    @endcomponent

    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">
                    <div>
                        <h4 class="mb-1">{{ $blueprintConfig->blueprint_course_name ?: $blueprintConfig->blueprint_course_id }}</h4>
                        <p class="text-muted mb-0">Atualize habilitação, gatilho, publicação e permissões do professor.</p>
                    </div>
                    <a href="{{ route('ai-grader.blueprints.show', $blueprintConfig) }}" class="btn btn-light">Voltar</a>
                </div>
            </div>
        </div>

        @include('ai-grader.blueprints._form')
    </div>
@endsection
