<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <title>@yield('title', 'GraderAI')</title>
    <meta name="viewport"
        content="width=device-width, initial-scale=1">
    <meta name="csrf-token"
        content="{{ csrf_token() }}">
    <link rel="icon" href="{{ asset('build/images/favicon.ico') }}">
    @vite('resources/js/graderai.js')
    <style>
        body {
            background: #f5f7fb;
        }

        .lti-shell {
            max-width: 1280px;
            margin: 0 auto;
            padding: 24px;
        }

        .lti-topbar {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 16px;
        }

        .lti-language-form {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border: 1px solid #dbe3ef;
            border-radius: 999px;
            background: #fff;
        }

        .lti-language-label {
            font-size: 12px;
            color: #6c757d;
        }

        .lti-language-actions {
            display: inline-flex;
            gap: 6px;
        }

        .lti-language-button {
            border: 0;
            background: transparent;
            padding: 2px;
            border-radius: 999px;
            line-height: 1;
        }

        .lti-language-button img {
            display: block;
            height: 18px;
            width: auto;
            border-radius: 999px;
            transition: filter .2s ease, opacity .2s ease, transform .2s ease;
        }

        .lti-language-button.is-inactive img {
            filter: grayscale(1);
            opacity: .65;
        }

        .lti-language-button.is-active img {
            box-shadow: 0 0 0 2px rgba(85, 110, 230, .18);
        }

        .lti-language-button:hover img,
        .lti-language-button:focus-visible img {
            transform: translateY(-1px);
        }

        .metric {
            min-height: 96px;
        }

        pre {
            white-space: pre-wrap;
            word-break: break-word;
        }
    </style>
</head>

<body>
    <main class="lti-shell">
        @php
            $currentLocale = app()->getLocale();
        @endphp
        <div class="lti-topbar">
            <form method="POST"
                action="{{ route('lti.ai-grader.locale') }}"
                class="lti-language-form">
                @csrf
                <input type="hidden"
                    name="redirect_to"
                    value="{{ request()->url() }}"
                    data-lti-locale-redirect>
                <span class="lti-language-label">{{ __('ai-grader.common.language') }}</span>
                <div class="lti-language-actions"
                    role="group"
                    aria-label="{{ __('ai-grader.common.switch_language') }}">
                    <button type="submit"
                        name="locale"
                        value="en"
                        class="lti-language-button {{ $currentLocale === 'en' ? 'is-active' : 'is-inactive' }}"
                        title="{{ __('ai-grader.common.english') }}"
                        aria-label="{{ __('ai-grader.common.english') }}"
                        @disabled($currentLocale === 'en')>
                        <img src="{{ asset('build/images/flags/us.jpg') }}"
                            alt="{{ __('ai-grader.common.english') }}">
                    </button>
                    <button type="submit"
                        name="locale"
                        value="pt_BR"
                        class="lti-language-button {{ $currentLocale === 'pt_BR' ? 'is-active' : 'is-inactive' }}"
                        title="{{ __('ai-grader.common.portuguese') }}"
                        aria-label="{{ __('ai-grader.common.portuguese') }}"
                        @disabled($currentLocale === 'pt_BR')>
                        <img src="{{ asset('build/images/flags/brazil.svg') }}"
                            alt="{{ __('ai-grader.common.portuguese') }}">
                    </button>
                </div>
            </form>
        </div>
        @yield('content')
    </main>
    <script>
        (() => {
            const syncRedirectUrl = () => {
                const redirectInputs = document.querySelectorAll('[data-lti-locale-redirect]');

                if (!redirectInputs.length) {
                    return;
                }

                const courseStatus = document.getElementById('ai-grader-course-status');
                const nextUrl = courseStatus && courseStatus.dataset.pageUrl
                    ? courseStatus.dataset.pageUrl
                    : `${window.location.origin}${window.location.pathname}`;

                redirectInputs.forEach((input) => {
                    input.value = nextUrl;
                });
            };

            syncRedirectUrl();

            document.body.addEventListener('htmx:configRequest', (event) => {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

                if (csrfToken) {
                    event.detail.headers['X-CSRF-TOKEN'] = csrfToken;
                }
            });

            document.body.addEventListener('htmx:afterSwap', (event) => {
                if (event.target && event.target.id === 'ai-grader-course-status') {
                    syncRedirectUrl();
                }
            });
        })();
    </script>
</body>

</html>
