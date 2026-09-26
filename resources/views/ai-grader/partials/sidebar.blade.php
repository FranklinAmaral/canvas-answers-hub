<div class="vertical-menu">
    <div data-simplebar class="h-100">
        <div id="sidebar-menu">
            <ul class="list-unstyled" id="side-menu">
                <li class="menu-title">@lang('ai-grader.admin.navigation.grader_ai')</li>

                <li>
                    <a href="{{ route('ai-grader.client-settings.index') }}"
                        class="waves-effect {{ request()->routeIs('ai-grader.client-settings.*') ? 'active' : '' }}">
                        <i class="bx bx-slider-alt"></i>
                        <span>@lang('ai-grader.admin.navigation.client_settings')</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('ai-grader.blueprints.index') }}"
                        class="waves-effect {{ request()->routeIs('ai-grader.blueprints.*') ? 'active' : '' }}">
                        <i class="bx bx-layer"></i>
                        <span>@lang('ai-grader.admin.navigation.blueprints')</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('ai-grader.ai-providers.index') }}"
                        class="waves-effect {{ request()->routeIs('ai-grader.ai-providers.*') ? 'active' : '' }}">
                        <i class="bx bx-chip"></i>
                        <span>@lang('ai-grader.admin.navigation.ai_providers')</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('ai-grader.correction-items.index') }}"
                        class="waves-effect {{ request()->routeIs('ai-grader.correction-items.*') ? 'active' : '' }}">
                        <i class="bx bx-check-square"></i>
                        <span>@lang('ai-grader.admin.navigation.corrections')</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('ai-grader.lti-installation.index') }}"
                        class="waves-effect {{ request()->routeIs('ai-grader.lti-installation.*') ? 'active' : '' }}">
                        <i class="bx bx-link-external"></i>
                        <span>@lang('ai-grader.admin.navigation.lti_installation')</span>
                    </a>
                </li>

                <li class="menu-title">@lang('ai-grader.admin.navigation.administration')</li>

                <li>
                    <a href="{{ route('organizations.index') }}"
                        class="waves-effect {{ request()->routeIs('organizations.*') ? 'active' : '' }}">
                        <i class="bx bx-buildings"></i>
                        <span>@lang('ai-grader.admin.navigation.organizations')</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('canvas-environments.index') }}"
                        class="waves-effect {{ request()->routeIs('canvas-environments.*') ? 'active' : '' }}">
                        <i class="bx bx-cloud"></i>
                        <span>@lang('ai-grader.admin.navigation.canvas_environments')</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>
