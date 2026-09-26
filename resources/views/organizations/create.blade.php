@extends('layouts.ai-grader')

@section('title')
    Nova organização
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            Início
        @endslot
        @slot('title')
            Nova organização
        @endslot
    @endcomponent

    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">
                    <div>
                        <h4 class="mb-1">Nova organização</h4>
                        <p class="text-muted mb-0">
                            Cadastre uma nova organização para agrupar ambientes Canvas.
                        </p>
                    </div>

                    <div>
                        <a href="{{ route('organizations.index') }}"
                            class="btn btn-light">
                            Voltar
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form action="{{ route('organizations.store') }}"
                    method="POST">
                    @csrf

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="name"
                                class="form-label">
                                Nome <span class="text-danger">*</span>
                            </label>

                            <input type="text"
                                name="name"
                                id="name"
                                class="form-control @error('name') is-invalid @enderror"
                                value="{{ old('name') }}"
                                required>

                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="slug"
                                class="form-label">Slug</label>

                            <input type="text"
                                name="slug"
                                id="slug"
                                class="form-control @error('slug') is-invalid @enderror"
                                value="{{ old('slug') }}">

                            <div class="form-text">
                                Opcional. Se não informar, será gerado automaticamente com base no nome.
                            </div>

                            @error('slug')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="timezone"
                                class="form-label">
                                Timezone <span class="text-danger">*</span>
                            </label>

                            <select name="timezone"
                                id="timezone"
                                class="form-select @error('timezone') is-invalid @enderror"
                                required>
                                <option value="America/Sao_Paulo"
                                    @selected(old('timezone', 'America/Sao_Paulo') === 'America/Sao_Paulo')>
                                    America/Sao_Paulo
                                </option>
                                <option value="America/Araguaina"
                                    @selected(old('timezone') === 'America/Araguaina')>
                                    America/Araguaina
                                </option>
                                <option value="America/New_York"
                                    @selected(old('timezone') === 'America/New_York')>
                                    America/New_York
                                </option>
                                <option value="UTC"
                                    @selected(old('timezone') === 'UTC')>
                                    UTC
                                </option>
                            </select>

                            @error('timezone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="form-check mb-4">
                        <input type="checkbox"
                            name="is_active"
                            id="is_active"
                            value="1"
                            class="form-check-input"
                            @checked(old('is_active', true))>

                        <label for="is_active"
                            class="form-check-label">
                            Organização ativa
                        </label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit"
                            class="btn btn-primary">
                            Salvar organização
                        </button>

                        <a href="{{ route('organizations.index') }}"
                            class="btn btn-light">
                            Cancelar
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
