<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="@lang('ai-grader.admin.layout.description')">
    <title>@yield('title') - @lang('ai-grader.admin.layout.brand')</title>

    <link rel="icon" href="{{ asset('build/images/favicon.ico') }}">
    @vite('resources/js/graderai.js')
    @stack('styles')
</head>

@section('body')
    <body data-sidebar="dark" data-layout-mode="light">
@show
    <div id="layout-wrapper">
        @include('ai-grader.partials.topbar')
        @include('ai-grader.partials.sidebar')

        <div class="main-content">
            <div class="page-content">
                <div class="container-fluid">
                    @yield('content')
                </div>
            </div>

            @include('ai-grader.partials.footer')
        </div>
    </div>

    @yield('script')
    @yield('script-bottom')
    @stack('scripts')
</body>

</html>
