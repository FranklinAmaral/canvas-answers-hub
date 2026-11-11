# Validação técnica — 2026-11-11

## Resultado atual

Windows, PHP 8.5.12 e Node 22.22.2. Testes com SQLite `:memory:`, extensões SQLite
habilitadas somente no processo, OPENSSL_CONF da instalação PHP, configuração
local sintética ignorada e nenhum acesso a bancos ou credenciais de produção.

| Check executado após a padronização | Resultado |
| --- | --- |
| Pint global --test | Exit 0; zero divergências |
| Testes LTI após substituir os JWTs literais | 89 passaram; 436 assertions; zero falhas |
| Suíte completa após substituir os JWTs literais | 260 passaram; 1035 assertions; zero falhas |
| Busca de JWTs compactos literais | Zero nos 264 arquivos atuais analisados; duas fixtures no commit histórico, preservado |
| Licenças Nette e metadados oficiais dos tags instalados | Hashes iguais aos arquivos instalados; BSD-3-Clause disponível nas duas versões |
| php tools/check-nette-licenses.php | Exit 0 no checkout e em cópia isolada íntegra; exit 1 com aviso ausente ou licença alterada na cópia |
| Comparação PHP antes/depois do Pint | 58 arquivos alterados; zero diferenças nas ASTs normalizadas |
| Composer audit --locked | Exit 0; zero avisos aplicáveis e abandonados |
| npm audit | Exit 0; zero vulnerabilidades |
| npm run build | Passou; aviso existente de eval no HTMX permanece |
| view:cache / config:cache / route:list / route:cache | Passaram; caches limpos após validação |
| Composer validate --strict / check-platform-reqs | Passaram no PHP instalado |
| bash -n deploy.sh / git diff --check | Passaram |

Na padronização, o Pint formatou 45 arquivos em app, quatro em lang e nove em tests. Para comparar
sem ruído de estilo, os nomes PHP foram resolvidos e ignoraram-se imports,
comentários e atributos de localização na AST. Literais e expressões foram
mantidos na comparação, incluindo os prompts do produto. Essa verificação
complementa os testes; não substitui homologação nem análise de Segurança. A
substituição posterior dos dois JWTs literais por geração em execução foi
validada pelos testes LTI e suíte completa acima. Os asserts de rejeição e
não exposição do token foram preservados; ver [JWT-REVIEW.md](JWT-REVIEW.md).

Após incluir a adoção BSD-3-Clause e os checks em CI/deploy, Pint global,
composer validate, sintaxe Bash e suíte completa foram executados novamente e
passaram (260 testes, 1035 assertions). O check isolado de distribuição não roda
Laravel, não acessa banco e não altera os pacotes reais. Os hashes de composer.json,
composer.lock, package.json e package-lock.json permaneceram inalterados. Build,
views/rotas e auditorias anteriormente registrados não foram repetidos por não
haver alteração nas dependências, assets ou comportamento da aplicação.

Não foram removidos testes, asserts, arquivos ou checks para obter aprovação.
Manifests/lockfiles não mudaram na padronização. Evidências de execução e JUnit
ficam localmente em .validation, ignorado pelo Git; HTML de testes pode conter
sessões/tokens sintéticos e não é publicado nos documentos.

## Resultados históricos preservados

| Referência técnica | Resultado registrado |
| --- | --- |
| Commit 40a7e31 com lockfile original, em cópia isolada | 256 testes: 238 passaram, 18 falharam; 951 assertions |
| Mesmo código com lockfile corrigido, antes de corrigir as expectativas/mock | Os mesmos 18 testes falharam; 238 passaram; 951 assertions |
| Quatro arquivos afetados antes das correções | 104 testes: 86 passaram, 18 falharam; 404 assertions |
| Arquivos afetados e quatro novos casos de idioma, após correções | 108 passaram; 488 assertions |
| Verificação focada em LTI/autorização/processamento/publicação | 127 passaram; 582 assertions |
| Pint antes da padronização global | 61 arquivos inicialmente; 58 após formatação dos três arquivos das correções funcionais; agora zero |

Os nomes, causas, resultados esperados/obtidos e correções dos 18 testes estão em
[FAILURE-INVESTIGATION.md](FAILURE-INVESTIGATION.md), evitando duplicação da lista.
Os JSONs das auditorias antes/depois e o histórico de versões permanecem em
[DEPENDENCIES.md](DEPENDENCIES.md).

## Limites

PHP 8.4, workflow remoto, serviços web/worker/cron e ambiente alvo não foram
executados/homologados localmente. Regras de branch e avaliação de Compliance não
são comprovadas por esses checks. Não há analisador estático configurado.
Os resultados valem para a árvore local identificada pelo inventário, não para
o HEAD isolado ou uma implantação externa. Ver [REVALIDATION-SUMMARY.md](REVALIDATION-SUMMARY.md).
