@extends('layouts.lti')

@section('title', __('ai-grader.lti.titles.install'))

@section('content')
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('ai-grader.lti.install.heading') }}</h1>
            <div class="text-muted">{{ __('ai-grader.lti.install.subtitle') }}</div>
        </div>
        <a href="{{ $urls['config'] }}" target="_blank" rel="noopener" class="btn btn-outline-primary">
            {{ __('ai-grader.lti.install.open_json') }}
        </a>
    </div>

    <div class="row">
        <div class="col-xl-5">
            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="h5 mb-3">{{ __('ai-grader.lti.install.official_urls') }}</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <tbody>
                                <tr>
                                    <th class="text-nowrap">Login/OIDC</th>
                                    <td><code>{{ $urls['login'] }}</code></td>
                                </tr>
                                <tr>
                                    <th class="text-nowrap">Launch</th>
                                    <td><code>{{ $urls['launch'] }}</code></td>
                                </tr>
                                <tr>
                                    <th class="text-nowrap">JWKS</th>
                                    <td><code>{{ $urls['jwks'] }}</code></td>
                                </tr>
                                <tr>
                                    <th class="text-nowrap">Config JSON</th>
                                    <td><code>{{ $urls['config'] }}</code></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="h5 mb-3">{{ __('ai-grader.lti.install.canvas_steps') }}</h2>
                    <ol class="mb-0">
                        <li>Admin &gt; Developer Keys</li>
                        <li>+ Developer Key &gt; + LTI Key</li>
                        <li>Method: Paste JSON</li>
                        <li>Paste JSON</li>
                        <li>Save</li>
                        <li>Enable Developer Key</li>
                        <li>Copy Client ID</li>
                        <li>Admin &gt; Settings &gt; Apps</li>
                        <li>+ App &gt; By Client ID</li>
                        <li>Provide the Client ID and install</li>
                    </ol>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="h5 mb-3">{{ __('ai-grader.lti.install.usage_title') }}</h2>
                    <p class="mb-2">{{ __('ai-grader.lti.install.usage_text_1') }}</p>
                    <p class="mb-0">{{ __('ai-grader.lti.install.usage_text_2') }}</p>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h2 class="h5 mb-0">{{ __('ai-grader.lti.install.config_json') }}</h2>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-copy-target="lti-config-json">
                            {{ __('ai-grader.lti.install.copy') }}
                        </button>
                    </div>
                    <pre id="lti-config-json" class="bg-light border rounded p-3 mb-0" style="white-space: pre-wrap;"><code>{{ $configJson }}</code></pre>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('[data-copy-target]').forEach((button) => {
            button.addEventListener('click', () => {
                const target = document.getElementById(button.dataset.copyTarget);
                if (!target || !navigator.clipboard) {
                    return;
                }

                navigator.clipboard.writeText(target.innerText);
                button.innerText = @json(__('ai-grader.lti.install.copied'));
                setTimeout(() => {
                    button.innerText = @json(__('ai-grader.lti.install.copy'));
                }, 1500);
            });
        });
    </script>
@endsection
