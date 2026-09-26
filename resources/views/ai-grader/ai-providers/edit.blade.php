@extends('layouts.ai-grader')

@section('title')
    Editar provider de IA
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            GraderAI
        @endslot
        @slot('title')
            Editar provider de IA
        @endslot
    @endcomponent

    <div class="container-fluid">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('ai-grader.ai-providers.update', $provider) }}">
                    @csrf
                    @method('PUT')
                    @include('ai-grader.ai-providers._form')
                </form>
            </div>
        </div>
    </div>
@endsection
