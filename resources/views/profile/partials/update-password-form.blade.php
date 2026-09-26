<div class="card">
    <div class="card-body">
        <h4 class="card-title mb-1">@lang('ai-grader.admin.profile.password_title')</h4>
        <p class="text-muted mb-4">@lang('ai-grader.admin.profile.password_help')</p>

        @if (session('status') === 'password-updated')
            <div class="alert alert-success" role="alert">
                @lang('ai-grader.admin.profile.password_updated')
            </div>
        @endif

        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            @method('PUT')

            <div class="mb-3">
                <label for="update_password_current_password" class="form-label">
                    @lang('ai-grader.admin.profile.current_password')
                </label>
                <input id="update_password_current_password" name="current_password" type="password"
                    class="form-control {{ $errors->updatePassword->has('current_password') ? 'is-invalid' : '' }}"
                    autocomplete="current-password">
                @if ($errors->updatePassword->has('current_password'))
                    <div class="invalid-feedback">{{ $errors->updatePassword->first('current_password') }}</div>
                @endif
            </div>

            <div class="mb-3">
                <label for="update_password_password" class="form-label">
                    @lang('ai-grader.admin.profile.new_password')
                </label>
                <input id="update_password_password" name="password" type="password"
                    class="form-control {{ $errors->updatePassword->has('password') ? 'is-invalid' : '' }}"
                    autocomplete="new-password">
                @if ($errors->updatePassword->has('password'))
                    <div class="invalid-feedback">{{ $errors->updatePassword->first('password') }}</div>
                @endif
            </div>

            <div class="mb-3">
                <label for="update_password_password_confirmation" class="form-label">
                    @lang('ai-grader.admin.profile.confirm_password')
                </label>
                <input id="update_password_password_confirmation" name="password_confirmation" type="password"
                    class="form-control {{ $errors->updatePassword->has('password_confirmation') ? 'is-invalid' : '' }}"
                    autocomplete="new-password">
                @if ($errors->updatePassword->has('password_confirmation'))
                    <div class="invalid-feedback">{{ $errors->updatePassword->first('password_confirmation') }}</div>
                @endif
            </div>

            <button type="submit" class="btn btn-primary">
                @lang('ai-grader.admin.profile.save')
            </button>
        </form>
    </div>
</div>
