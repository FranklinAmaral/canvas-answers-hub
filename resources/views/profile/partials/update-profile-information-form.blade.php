<div class="card">
    <div class="card-body">
        <h4 class="card-title mb-1">@lang('ai-grader.admin.profile.information_title')</h4>
        <p class="text-muted mb-4">@lang('ai-grader.admin.profile.information_help')</p>

        @if (session('status') === 'profile-updated')
            <div class="alert alert-success" role="alert">
                @lang('ai-grader.admin.profile.updated')
            </div>
        @endif

        <form method="POST" action="{{ route('profile.update') }}">
            @csrf
            @method('PATCH')

            <div class="mb-3">
                <label for="name" class="form-label">@lang('ai-grader.admin.profile.name')</label>
                <input id="name" name="name" type="text"
                    value="{{ old('name', $user->name) }}"
                    class="form-control @error('name') is-invalid @enderror"
                    required autofocus autocomplete="name">
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">@lang('ai-grader.admin.profile.email')</label>
                <input id="email" name="email" type="email"
                    value="{{ old('email', $user->email) }}"
                    class="form-control @error('email') is-invalid @enderror"
                    required autocomplete="username">
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <button type="submit" class="btn btn-primary">
                @lang('ai-grader.admin.profile.save')
            </button>
        </form>
    </div>
</div>
