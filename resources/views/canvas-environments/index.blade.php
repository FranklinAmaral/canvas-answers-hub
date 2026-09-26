@extends('layouts.ai-grader')

@section('title')
    @lang('translation.environments')
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            Início
        @endslot
        @slot('title')
            @lang('translation.environments')
        @endslot
    @endcomponent
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">
                    <div class="row mb-4">
                        <p class="text-muted mb-0">@lang('translation.environments.description')</p>
                    </div>
                    {{-- @if (session('success'))
                        <div class="alert alert-success alert-dismissible fade show"
                            role="alert">
                            {{ session('success') }}
                            <button type="button"
                                class="btn-close"
                                data-bs-dismiss="alert"
                                aria-label="Fechar"></button>
                        </div>
                    @endif

                    @if (session('error'))
                        <div class="alert alert-danger alert-dismissible fade show"
                            role="alert">
                            {{ session('error') }}
                            <button type="button"
                                class="btn-close"
                                data-bs-dismiss="alert"
                                aria-label="Fechar"></button>
                        </div>
                    @endif --}}
                    <div>
                        <a href="{{ route('canvas-environments.create') }}"
                            class="btn btn-primary">
                            Novo ambiente
                        </a>
                    </div>
                </div>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show"
                role="alert">
                {{ session('success') }}

                <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Fechar"></button>
            </div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger alert-dismissible fade show"
                role="alert">
                {{ session('error') }}
                <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Fechar"></button>
            </div>
        @endif
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-nowrap align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Organização</th>
                                <th>Nome</th>
                                <th>Tipo</th>
                                <th>Base URL</th>
                                <th>Status</th>
                                <th>Padrão</th>
                                <th>Criado em</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($environments as $environment)
                                <tr>
                                    <td>{{ $environment->id }}</td>
                                    <td>{{ $environment->organization?->name }}</td>
                                    <td>{{ $environment->name }}</td>
                                    <td>
                                        <span class="badge bg-light text-dark">
                                            {{ $environment->environment_type }}
                                        </span>
                                    </td>
                                    <td>{{ $environment->base_url }}</td>
                                    <td>
                                        @if ($environment->is_active)
                                            <span class="badge bg-success-subtle text-success">
                                                Ativo
                                            </span>
                                        @else
                                            <span class="badge bg-danger-subtle text-danger">
                                                Inativo
                                            </span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($environment->is_default)
                                            <span class="badge bg-primary-subtle text-primary">
                                                Sim
                                            </span>
                                        @else
                                            <span class="text-muted">Não</span>
                                        @endif
                                    </td>
                                    <td>{{ $environment->created_at?->format('d/m/Y H:i') }}</td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <a href="{{ route('canvas-environments.edit', $environment) }}"
                                                class="btn btn-sm btn-outline-primary">
                                                Editar
                                            </a>

                                            <form method="POST"
                                                action="{{ route('canvas-environments.test-connection', $environment) }}">
                                                @csrf
                                                <button type="submit"
                                                    class="btn btn-sm btn-outline-primary">
                                                    Testar conexão
                                                </button>
                                            </form>

                                            {{-- <form action="{{ route('canvas-environments.destroy', $environment) }}"
                                                method="POST"
                                                class="d-inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                    onclick="return confirm('Tem certeza que deseja excluir este ambiente?')">
                                                    Excluir
                                                </button>
                                            </form> --}}
                                        </div>

                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8"
                                        class="text-center text-muted">
                                        Nenhum ambiente cadastrado.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($environments->hasPages())
                    <div class="mt-4">
                        {{ $environments->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
