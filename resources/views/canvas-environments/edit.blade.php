@extends('layouts.ai-grader')

@section('title')
    Editar ambiente Canvas
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            Início
        @endslot
        @slot('title')
            Editar ambiente Canvas
        @endslot
    @endcomponent
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">
                    <div>
                        <h4 class="mb-1">Editar ambiente Canvas</h4>
                        <p class="text-muted mb-0">
                            Atualize os dados do ambiente e, se necessário, informe um novo token.
                        </p>
                    </div>

                    <div>
                        <a href="{{ route('canvas-environments.index') }}"
                            class="btn btn-light">
                            Voltar
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form action="{{ route('canvas-environments.update', $canvasEnvironment) }}"
                    method="POST">
                    @csrf
                    @method('PUT')

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="organization_id"
                                class="form-label">
                                Organização <span class="text-danger">*</span>
                            </label>

                            <select name="organization_id"
                                id="organization_id"
                                class="form-select @error('organization_id') is-invalid @enderror"
                                required>
                                <option value="">Selecione</option>

                                @foreach ($organizations as $organization)
                                    <option value="{{ $organization->id }}"
                                        @selected(old('organization_id', $canvasEnvironment->organization_id) == $organization->id)>
                                        {{ $organization->name }}
                                    </option>
                                @endforeach
                            </select>

                            @error('organization_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="name"
                                class="form-label">
                                Nome <span class="text-danger">*</span>
                            </label>

                            <input type="text"
                                name="name"
                                id="name"
                                class="form-control @error('name') is-invalid @enderror"
                                value="{{ old('name', $canvasEnvironment->name) }}"
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
                                value="{{ old('slug', $canvasEnvironment->slug) }}">

                            @error('slug')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="environment_type"
                                class="form-label">
                                Tipo do ambiente <span class="text-danger">*</span>
                            </label>

                            <select name="environment_type"
                                id="environment_type"
                                class="form-select @error('environment_type') is-invalid @enderror"
                                required>
                                <option value="production"
                                    @selected(old('environment_type', $canvasEnvironment->environment_type) === 'production')>Production</option>
                                <option value="test"
                                    @selected(old('environment_type', $canvasEnvironment->environment_type) === 'test')>Test</option>
                                <option value="staging"
                                    @selected(old('environment_type', $canvasEnvironment->environment_type) === 'staging')>Staging</option>
                                <option value="vestibular"
                                    @selected(old('environment_type', $canvasEnvironment->environment_type) === 'vestibular')>Vestibular</option>
                                <option value="other"
                                    @selected(old('environment_type', $canvasEnvironment->environment_type) === 'other')>Other</option>
                            </select>

                            @error('environment_type')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="base_url"
                                class="form-label">
                                Base URL <span class="text-danger">*</span>
                            </label>

                            <input type="url"
                                name="base_url"
                                id="base_url"
                                class="form-control @error('base_url') is-invalid @enderror"
                                value="{{ old('base_url', $canvasEnvironment->base_url) }}"
                                required>

                            @error('base_url')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label d-block">
                                Token configurado
                            </label>

                            <span class="badge {{ $tokenConfigured ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">
                                {{ $tokenConfigured ? 'Sim' : 'Não' }}
                            </span>

                            @if ($tokenInvalid)
                                <div class="form-text text-warning">
                                    O token salvo está inválido ou incompatível. Preencha um novo token para substituir o valor atual.
                                </div>
                            @endif
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="api_token"
                                class="form-label">
                                Novo API Token
                            </label>

                            <input type="password"
                                name="api_token"
                                id="api_token"
                                class="form-control @error('api_token') is-invalid @enderror">

                            <div class="form-text">
                                Preencha somente se desejar substituir o token atual.
                            </div>

                            @error('api_token')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-12 mt-3 mb-2">
                            <h5>{{ __('ai-grader.admin.lti_environment.title') }}</h5>
                            <p class="text-muted mb-2">{{ __('ai-grader.admin.lti_environment.help') }}</p>
                        </div>

                        <div class="col-12 mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="lti_enabled" id="lti_enabled" value="1"
                                    class="form-check-input @error('lti_enabled') is-invalid @enderror"
                                    @checked(old('lti_enabled', $canvasEnvironment->lti_enabled))>
                                <label for="lti_enabled" class="form-check-label">{{ __('ai-grader.admin.lti_environment.enabled') }}</label>
                                @error('lti_enabled')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        @foreach ([
                            'lti_issuer' => ['label' => __('ai-grader.admin.lti_environment.issuer'), 'type' => 'url'],
                            'lti_client_id' => ['label' => __('ai-grader.admin.lti_environment.client_id'), 'type' => 'text'],
                            'lti_deployment_id' => ['label' => __('ai-grader.admin.lti_environment.deployment_id'), 'type' => 'text'],
                            'lti_authorization_endpoint' => ['label' => __('ai-grader.admin.lti_environment.authorization_endpoint'), 'type' => 'url'],
                            'lti_jwks_uri' => ['label' => __('ai-grader.admin.lti_environment.jwks_uri'), 'type' => 'url'],
                        ] as $field => $config)
                            <div class="col-md-6 mb-3">
                                <label for="{{ $field }}" class="form-label">{{ $config['label'] }}</label>
                                <input type="{{ $config['type'] }}" name="{{ $field }}" id="{{ $field }}"
                                    class="form-control @error($field) is-invalid @enderror"
                                    value="{{ old($field, $canvasEnvironment->{$field}) }}">
                                @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @endforeach
                    </div>

                    <div class="form-check mb-2">
                        <input type="checkbox"
                            name="is_active"
                            id="is_active"
                            value="1"
                            class="form-check-input"
                            @checked(old('is_active', $canvasEnvironment->is_active))>

                        <label for="is_active"
                            class="form-check-label">
                            Ambiente ativo
                        </label>
                    </div>

                    <div class="form-check mb-4">
                        <input type="checkbox"
                            name="is_default"
                            id="is_default"
                            value="1"
                            class="form-check-input"
                            @checked(old('is_default', $canvasEnvironment->is_default))>

                        <label for="is_default"
                            class="form-check-label">
                            Definir como ambiente padrão da organização
                        </label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit"
                            class="btn btn-primary">
                            Salvar alterações
                        </button>

                        <a href="{{ route('canvas-environments.index') }}"
                            class="btn btn-light">
                            Cancelar
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
