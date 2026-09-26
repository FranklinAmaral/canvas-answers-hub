<header id="page-topbar">
    <div class="navbar-header">
        <div class="d-flex">
            <div class="navbar-brand-box">
                <a href="{{ route('ai-grader.blueprints.index') }}" class="logo logo-dark">
                    <span class="logo-sm">
                        <img src="{{ asset('build/images/logo-dark.png') }}" alt="@lang('ai-grader.admin.layout.brand')" height="22">
                    </span>
                    <span class="logo-lg">
                        <img src="{{ asset('build/images/logo-dark.png') }}" alt="@lang('ai-grader.admin.layout.brand')" height="35">
                    </span>
                </a>

                <a href="{{ route('ai-grader.blueprints.index') }}" class="logo logo-light">
                    <span class="logo-sm">
                        <img src="{{ asset('build/images/logo-light.png') }}" alt="@lang('ai-grader.admin.layout.brand')" height="22">
                    </span>
                    <span class="logo-lg">
                        <img src="{{ asset('build/images/logo-light.png') }}" alt="@lang('ai-grader.admin.layout.brand')" height="35">
                    </span>
                </a>
            </div>

            <button type="button" class="btn btn-sm px-3 font-size-16 header-item waves-effect" id="vertical-menu-btn"
                aria-label="@lang('ai-grader.admin.navigation.toggle_menu')">
                <i class="bx bx-menu"></i>
            </button>
        </div>

        <div class="d-flex">
            <div class="dropdown d-inline-block">
                <button type="button" class="btn header-item waves-effect" data-bs-toggle="dropdown"
                    aria-haspopup="true" aria-expanded="false"
                    aria-label="@lang('ai-grader.admin.navigation.language')">
                    @if (app()->getLocale() === 'pt_BR')
                        <img src="{{ asset('build/images/flags/brazil.svg') }}" alt="@lang('ai-grader.admin.navigation.portuguese')" height="16">
                    @else
                        <img src="{{ asset('build/images/flags/us.jpg') }}" alt="@lang('ai-grader.admin.navigation.english')" height="16">
                    @endif
                </button>
                <div class="dropdown-menu dropdown-menu-end">
                    <a href="{{ route('locale.update', 'en') }}" class="dropdown-item notify-item language" data-lang="eng">
                        <img src="{{ asset('build/images/flags/us.jpg') }}" alt="" class="me-1" height="12">
                        <span class="align-middle">@lang('ai-grader.admin.navigation.english')</span>
                    </a>
                    <a href="{{ route('locale.update', 'pt_BR') }}" class="dropdown-item notify-item language" data-lang="pt_BR">
                        <img src="{{ asset('build/images/flags/brazil.svg') }}" alt="" class="me-1" height="12">
                        <span class="align-middle">@lang('ai-grader.admin.navigation.portuguese')</span>
                    </a>
                </div>
            </div>

            <div class="dropdown d-inline-block">
                <button type="button" class="btn header-item waves-effect" data-bs-toggle="dropdown"
                    aria-haspopup="true" aria-expanded="false">
                    <i class="bx bx-user-circle font-size-20 align-middle"></i>
                    <span class="d-none d-xl-inline-block ms-1">{{ ucfirst(auth()->user()->name) }}</span>
                    <i class="bx bx-chevron-down d-none d-xl-inline-block"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end">
                    <a class="dropdown-item" href="{{ route('profile.edit') }}">
                        <i class="bx bx-user font-size-16 align-middle me-1"></i>
                        @lang('ai-grader.admin.navigation.profile')
                    </a>
                    <div class="dropdown-divider"></div>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="dropdown-item text-danger">
                            <i class="bx bx-power-off font-size-16 align-middle me-1 text-danger"></i>
                            @lang('ai-grader.admin.navigation.logout')
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>
