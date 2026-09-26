<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('ai-grader.admin.layout.brand'))</title>
    <link rel="icon" href="{{ asset('build/images/favicon.ico') }}">
    @vite('resources/js/graderai.js')
</head>
<body class="auth-page">
    <main class="container min-vh-100 d-flex align-items-center justify-content-center py-5">
        <div class="auth-shell">
            <a href="{{ route('root') }}" class="auth-brand d-flex justify-content-center mb-4">
                <img src="{{ asset('build/images/logo-dark.png') }}" alt="@lang('ai-grader.admin.layout.brand')">
            </a>

            <div class="card auth-card">
                <div class="card-body p-4 p-md-5">
                    @yield('content')
                </div>
            </div>
        </div>
    </main>
</body>
</html>
