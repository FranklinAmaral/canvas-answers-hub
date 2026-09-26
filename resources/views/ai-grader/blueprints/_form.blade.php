@php
    $isEdit = $blueprintConfig->exists;
@endphp

<div class="card">
    <div class="card-body">
        @if ($errors->any())
            <div class="alert alert-danger">
                Revise os campos destacados antes de salvar.
            </div>
        @endif

        <form method="POST" action="{{ $isEdit ? route('ai-grader.blueprints.update', $blueprintConfig) : route('ai-grader.blueprints.store') }}">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="organization_id" class="form-label">Organização <span class="text-danger">*</span></label>
                    <select name="organization_id" id="organization_id" class="form-select @error('organization_id') is-invalid @enderror" required>
                        <option value="">Selecione</option>
                        @foreach ($organizations as $organization)
                            <option value="{{ $organization->id }}" @selected((string) old('organization_id', $blueprintConfig->organization_id) === (string) $organization->id)>
                                {{ $organization->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('organization_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-md-4 mb-3">
                    <label for="canvas_environment_id" class="form-label">Canvas Environment <span class="text-danger">*</span></label>
                    <select name="canvas_environment_id" id="canvas_environment_id" class="form-select @error('canvas_environment_id') is-invalid @enderror" required>
                        <option value="">Selecione</option>
                        @foreach ($environments as $environment)
                            <option value="{{ $environment->id }}" @selected((string) old('canvas_environment_id', $blueprintConfig->canvas_environment_id) === (string) $environment->id)>
                                {{ $environment->name }} - {{ $environment->organization?->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('canvas_environment_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-md-4 mb-3">
                    <label for="ai_provider_id" class="form-label">Provider da blueprint</label>
                    <select name="ai_provider_id" id="ai_provider_id" class="form-select @error('ai_provider_id') is-invalid @enderror">
                        <option value="">Usar provider padrão da organização</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider->id }}" @selected((string) old('ai_provider_id', $blueprintConfig->ai_provider_id) === (string) $provider->id)>
                                {{ $provider->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('ai_provider_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="blueprint_course_id" class="form-label">Blueprint course_id <span class="text-danger">*</span></label>
                    <input type="text" name="blueprint_course_id" id="blueprint_course_id" class="form-control @error('blueprint_course_id') is-invalid @enderror" value="{{ old('blueprint_course_id', $blueprintConfig->blueprint_course_id) }}" required>
                    @error('blueprint_course_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-8 mb-3">
                    <label for="blueprint_course_name" class="form-label">Nome da blueprint</label>
                    <input type="text" name="blueprint_course_name" id="blueprint_course_name" class="form-control @error('blueprint_course_name') is-invalid @enderror" value="{{ old('blueprint_course_name', $blueprintConfig->blueprint_course_name) }}">
                    @error('blueprint_course_name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="publication_mode" class="form-label">Modo de publicação <span class="text-danger">*</span></label>
                    <select name="publication_mode" id="publication_mode" class="form-select @error('publication_mode') is-invalid @enderror" required>
                        @foreach ($publicationModes as $value => $label)
                            <option value="{{ $value }}" @selected(old('publication_mode', $blueprintConfig->publication_mode ?? 'teacher_approval') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('publication_mode')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-4 mb-3">
                    <label for="trigger_mode" class="form-label">Gatilho <span class="text-danger">*</span></label>
                    <select name="trigger_mode" id="trigger_mode" class="form-select @error('trigger_mode') is-invalid @enderror" required>
                        @foreach ($triggerModes as $value => $label)
                            <option value="{{ $value }}" @selected(old('trigger_mode', $blueprintConfig->trigger_mode ?? 'manual') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('trigger_mode')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-4 mb-3">
                    <label for="scheduled_at" class="form-label">Data programada</label>
                    <input type="datetime-local" name="scheduled_at" id="scheduled_at" class="form-control @error('scheduled_at') is-invalid @enderror" value="{{ old('scheduled_at', $blueprintConfig->scheduled_at?->format('Y-m-d\TH:i')) }}">
                    @error('scheduled_at')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="default_feedback_language" class="form-label">Idioma padrão do feedback</label>
                    <input type="text" name="default_feedback_language" id="default_feedback_language" class="form-control @error('default_feedback_language') is-invalid @enderror" value="{{ old('default_feedback_language', $blueprintConfig->default_feedback_language ?? 'pt_BR') }}">
                    @error('default_feedback_language')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="form-check form-switch mb-3">
                <input type="checkbox" name="enabled" id="enabled" value="1" class="form-check-input" @checked(old('enabled', $blueprintConfig->enabled ?? false))>
                <label for="enabled" class="form-check-label">Blueprint habilitada</label>
            </div>

            <div class="row mb-4">
                @foreach ([
                    'teacher_can_adjust_score' => 'Professor pode ajustar nota',
                    'teacher_can_adjust_feedback' => 'Professor pode ajustar feedback',
                    'teacher_can_publish' => 'Professor pode publicar',
                    'teacher_can_republish' => 'Professor pode republicar',
                    'teacher_can_export' => 'Professor pode exportar',
                    'teacher_can_view_grading_prompt' => __('ai-grader.lti.blueprint.teacher_prompt_visibility_label'),
                ] as $field => $label)
                    <div class="col-md-4 mb-2">
                        <div class="form-check">
                            <input type="checkbox"
                                name="{{ $field }}"
                                id="{{ $field }}"
                                value="1"
                                class="form-check-input"
                                @checked(old($field, $blueprintConfig->{$field} ?? ! in_array($field, ['teacher_can_export', 'teacher_can_view_grading_prompt'], true)))>
                            <label for="{{ $field }}" class="form-check-label">{{ $label }}</label>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Salvar configuração</button>
                <a href="{{ route('ai-grader.blueprints.index') }}" class="btn btn-light">Cancelar</a>
            </div>
        </form>
    </div>
</div>
