@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-md-6">
        <label for="organization_id" class="form-label">Organizacao</label>
        <select name="organization_id" id="organization_id" class="form-select" required>
            <option value="">Selecione</option>
            @foreach ($organizations as $organization)
                <option value="{{ $organization->id }}" @selected((string) old('organization_id', $provider->organization_id) === (string) $organization->id)>
                    {{ $organization->name }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="col-md-6">
        <label for="name" class="form-label">Nome</label>
        <input type="text" name="name" id="name" value="{{ old('name', $provider->name) }}" class="form-control" required>
    </div>

    <div class="col-md-4">
        <label for="provider_type" class="form-label">Tipo</label>
        <select name="provider_type" id="provider_type" class="form-select" required>
            @foreach ($providerTypes as $value => $label)
                <option value="{{ $value }}" @selected(old('provider_type', $provider->provider_type ?: \App\Models\AiGraderAiProvider::TYPE_LM_STUDIO) === $value)>
                    {{ $label }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="col-md-8">
        <label for="base_url" class="form-label">URL base</label>
        <input type="url" name="base_url" id="base_url" value="{{ old('base_url', $provider->base_url) }}" class="form-control" placeholder="http://localhost:1234">
    </div>

    <div class="col-md-6">
        <label for="endpoint_path" class="form-label">Endpoint path</label>
        <input type="text" name="endpoint_path" id="endpoint_path" value="{{ old('endpoint_path', $provider->exists ? $provider->endpointPath() : '/v1/chat/completions') }}" class="form-control">
    </div>

    <div class="col-md-6">
        <label for="model" class="form-label">Modelo</label>
        <input type="text" name="model" id="model" value="{{ old('model', $provider->model) }}" class="form-control">
    </div>

    <div class="col-md-6">
        <label for="api_key" class="form-label">Bearer Token</label>
        <input type="password" name="api_key" id="api_key" value="" class="form-control" autocomplete="new-password">
        @if ($provider->exists)
            <div class="form-text">Bearer Token configurado: {{ $provider->hasApiKey() ? 'sim' : 'nao' }}. Deixe em branco para manter o token atual.</div>
        @endif
    </div>

    <div class="col-md-2">
        <label for="request_timeout_seconds" class="form-label">Timeout</label>
        <input type="number" min="5" max="300" name="request_timeout_seconds" id="request_timeout_seconds" value="{{ old('request_timeout_seconds', $provider->request_timeout_seconds ?: 60) }}" class="form-control">
    </div>

    <div class="col-md-2">
        <label for="max_tokens" class="form-label">Max tokens</label>
        <input type="number" min="100" name="max_tokens" id="max_tokens" value="{{ old('max_tokens', $provider->max_tokens ?: 800) }}" class="form-control">
    </div>

    <div class="col-md-2">
        <label for="temperature" class="form-label">Temperature</label>
        <input type="number" min="0" max="2" step="0.01" name="temperature" id="temperature" value="{{ old('temperature', $provider->temperature ?? '0.20') }}" class="form-control">
    </div>

    <div class="col-md-6">
        <div class="form-check form-switch">
            <input type="hidden" name="enabled" value="0">
            <input type="checkbox" name="enabled" value="1" id="enabled" class="form-check-input" @checked(old('enabled', $provider->exists ? $provider->enabled : true))>
            <label for="enabled" class="form-check-label">Provider ativo</label>
        </div>
    </div>

    <div class="col-md-6">
        <div class="form-check form-switch">
            <input type="hidden" name="is_default" value="0">
            <input type="checkbox" name="is_default" value="1" id="is_default" class="form-check-input" @checked(old('is_default', $provider->is_default))>
            <label for="is_default" class="form-check-label">Provider padrao da organizacao</label>
        </div>
    </div>

</div>

<div class="d-flex gap-2 mt-4">
    <button type="submit" class="btn btn-primary">Salvar</button>
    <a href="{{ route('ai-grader.ai-providers.index') }}" class="btn btn-outline-secondary">Cancelar</a>
</div>
