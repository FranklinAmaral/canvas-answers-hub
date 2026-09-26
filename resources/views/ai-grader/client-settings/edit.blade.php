@extends('layouts.ai-grader')

@section('title')
    GraderAI - Editar cliente
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            GraderAI
        @endslot
        @slot('title')
            Editar cliente
        @endslot
    @endcomponent

    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">
                    <div>
                        <h4 class="mb-1">{{ $organization->name }}</h4>
                        <p class="text-muted mb-0">Habilite e configure a cota global do GraderAI para esta organização.</p>
                    </div>
                    <a href="{{ route('ai-grader.client-settings.show', $organization) }}" class="btn btn-light">Voltar</a>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('ai-grader.client-settings.update', $organization) }}">
                    @csrf
                    @method('PUT')

                    <div class="form-check form-switch mb-4">
                        <input type="checkbox" name="enabled" id="enabled" value="1" class="form-check-input" @checked(old('enabled', $setting?->enabled ?? false))>
                        <label for="enabled" class="form-check-label">GraderAI habilitado para esta organização</label>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                            <select name="status" id="status" class="form-select @error('status') is-invalid @enderror" required>
                                @foreach ($statuses as $value => $label)
                                    <option value="{{ $value }}" @selected(old('status', $setting?->status ?? 'inactive') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('status')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="plan_name" class="form-label">Plano</label>
                            <input type="text" name="plan_name" id="plan_name" class="form-control @error('plan_name') is-invalid @enderror" value="{{ old('plan_name', $setting?->plan_name) }}">
                            @error('plan_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                    </div>

                    <div class="row">
                        @foreach ([
                            'trial_quota_total' => ['label' => 'Cota trial', 'default' => 50],
                            'purchased_quota_total' => ['label' => 'Cota contratada', 'default' => 0],
                            'quota_used' => ['label' => 'Cota usada', 'default' => 0],
                            'quota_reserved' => ['label' => 'Cota reservada', 'default' => 0],
                        ] as $field => $meta)
                            <div class="col-md-3 mb-3">
                                <label for="{{ $field }}" class="form-label">{{ $meta['label'] }}</label>
                                <input type="number" min="0" step="1" name="{{ $field }}" id="{{ $field }}" class="form-control @error($field) is-invalid @enderror" value="{{ old($field, $setting?->{$field} ?? $meta['default']) }}">
                                @error($field)
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        @endforeach
                    </div>

                    <div class="row">
                        @foreach ([
                            'starts_at' => 'Início',
                            'ends_at' => 'Fim',
                            'quota_reset_at' => 'Reset de cota',
                        ] as $field => $label)
                            <div class="col-md-4 mb-3">
                                <label for="{{ $field }}" class="form-label">{{ $label }}</label>
                                <input type="datetime-local" name="{{ $field }}" id="{{ $field }}" class="form-control @error($field) is-invalid @enderror" value="{{ old($field, $setting?->{$field}?->format('Y-m-d\TH:i')) }}">
                                @error($field)
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        @endforeach
                    </div>

                    <div class="mb-4">
                        <label for="notes" class="form-label">Observações internas</label>
                        <textarea name="notes" id="notes" rows="4" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $setting?->notes) }}</textarea>
                        @error('notes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Salvar configuração</button>
                        <a href="{{ route('ai-grader.client-settings.index') }}" class="btn btn-light">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
