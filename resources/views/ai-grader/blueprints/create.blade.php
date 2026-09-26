@extends('layouts.ai-grader')

@section('title')
    GraderAI - Nova blueprint
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            GraderAI
        @endslot
        @slot('title')
            Nova blueprint
        @endslot
    @endcomponent

    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">
                    <div>
                        <h4 class="mb-1">Nova configuração de blueprint</h4>
                        <p class="text-muted mb-0">A organização precisa estar com GraderAI habilitado em trial ou active.</p>
                    </div>
                    <a href="{{ route('ai-grader.blueprints.index') }}" class="btn btn-light">Voltar</a>
                </div>
            </div>
        </div>

        @include('ai-grader.blueprints._form')
    </div>
@endsection
