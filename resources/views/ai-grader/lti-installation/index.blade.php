@extends('layouts.ai-grader')

@section('title')
    GraderAI - Instalacao LTI
@endsection

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            GraderAI
        @endslot
        @slot('title')
            Instalacao LTI
        @endslot
    @endcomponent

    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">
                    <div>
                        <h4 class="mb-1">Instalacao LTI do GraderAI</h4>
                        <p class="text-muted mb-0">Use estas informacoes para instalar a ferramenta no Canvas.</p>
                    </div>
                    <a href="{{ $urls['config'] }}" target="_blank" rel="noopener" class="btn btn-outline-primary">
                        Abrir JSON
                    </a>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-5">
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">URLs oficiais</h5>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <tbody>
                                    <tr>
                                        <th class="text-nowrap">OIDC/Login URL</th>
                                        <td><code>{{ $urls['login'] }}</code></td>
                                    </tr>
                                    <tr>
                                        <th class="text-nowrap">Launch URL</th>
                                        <td><code>{{ $urls['launch'] }}</code></td>
                                    </tr>
                                    <tr>
                                        <th class="text-nowrap">JWKS URL</th>
                                        <td><code>{{ $urls['jwks'] }}</code></td>
                                    </tr>
                                    <tr>
                                        <th class="text-nowrap">Config URL</th>
                                        <td><code>{{ $urls['config'] }}</code></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">Instalacao no Canvas</h5>
                        <ol class="mb-0">
                            <li>Acesse <strong>Admin &gt; Developer Keys</strong>.</li>
                            <li>Clique em <strong>Developer Key &gt; + LTI Key</strong>.</li>
                            <li>Em <strong>Method</strong>, escolha <strong>Paste JSON</strong>.</li>
                            <li>Cole o JSON de configuracao exibido nesta pagina.</li>
                            <li>Salve e ative a Developer Key.</li>
                            <li>Copie o <strong>Client ID</strong>.</li>
                            <li>Acesse <strong>Admin &gt; Settings &gt; Apps</strong>.</li>
                            <li>Clique em <strong>+ App</strong>.</li>
                            <li>Em <strong>Configuration Type</strong>, escolha <strong>By Client ID</strong>.</li>
                            <li>Informe o Client ID e instale a ferramenta.</li>
                        </ol>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">Visibilidade</h5>
                        <ul class="mb-0">
                            <li>Instale a ferramenta na conta, subconta ou curso conforme o escopo desejado.</li>
                            <li>A ferramenta deve aparecer em <strong>Course Navigation</strong>.</li>
                            <li>Ela nao deve ficar disponivel para alunos.</li>
                            <li>Valide se o usuario de teste possui papel de professor, instrutor ou admin no curso.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-xl-7">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h5 class="card-title mb-0">JSON de configuracao da LTI</h5>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-copy-target="lti-config-json">
                                Copiar
                            </button>
                        </div>
                        <pre id="lti-config-json" class="bg-light border rounded p-3 mb-0" style="white-space: pre-wrap;"><code>{{ $configJson }}</code></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('[data-copy-target]').forEach((button) => {
            button.addEventListener('click', () => {
                const target = document.getElementById(button.dataset.copyTarget);
                if (! target || ! navigator.clipboard) {
                    return;
                }

                navigator.clipboard.writeText(target.innerText);
                button.innerText = 'Copiado';
                setTimeout(() => {
                    button.innerText = 'Copiar';
                }, 1500);
            });
        });
    </script>
@endsection
