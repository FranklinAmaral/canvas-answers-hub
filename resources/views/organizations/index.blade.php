@extends('layouts.ai-grader')

@section('title')
    @lang('translation.organizations')
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            Início
        @endslot
        @slot('title')
            @lang('translation.organizations')
        @endslot
    @endcomponent

    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">

                    <div class="row mb-4">
                        <p class="text-muted mb-0">@lang('translation.organizations.description')</p>
                    </div>

                    <div>
                        <a href="{{ route('organizations.create') }}"
                            class="btn btn-primary">
                            Nova organização
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

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-nowrap align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nome</th>
                                <th>Slug</th>
                                <th>Timezone</th>
                                <th>Status</th>
                                <th>Criada em</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($organizations as $organization)
                                <tr>
                                    <td>{{ $organization->id }}</td>
                                    <td>{{ $organization->name }}</td>
                                    <td>{{ $organization->slug }}</td>
                                    <td>{{ $organization->timezone }}</td>
                                    <td>
                                        @if ($organization->is_active)
                                            <span class="badge bg-success-subtle text-success">
                                                Ativa
                                            </span>
                                        @else
                                            <span class="badge bg-danger-subtle text-danger">
                                                Inativa
                                            </span>
                                        @endif
                                    </td>
                                    <td>{{ $organization->created_at?->format('d/m/Y H:i') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5"
                                        class="text-center text-muted">
                                        Nenhuma organização cadastrada.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($organizations->hasPages())
                    <div class="mt-4">
                        {{ $organizations->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
