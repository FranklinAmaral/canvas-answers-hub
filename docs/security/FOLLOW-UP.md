# Acompanhamento de segurança — 2026-11-11

## Referência e escopo

Revisão de `40a7e312791b0c87d2910017feb4c2a12fc63a38` com alterações locais não
commitadas. O HEAD isolado não contém as correções. O relatório original Orca
não foi disponibilizado; os nomes e IDs analisados vêm da solicitação registrada.
Não se atribuem CVEs ou ocorrências aos alertas originais sem evidência.

Estado atual: implantação sem Docker, Pint aprovado e **260 testes aprovados,
1035 assertions, zero falhas**. Não houve deploy, push, reescrita do histórico ou
alteração administrativa externa. Validação com SQLite em memória e dados
sintéticos, sem operações em bancos existentes.

## Tratativas e pendências

| Alerta/grupo | Evidência e tratamento | Status / validação | Pendência e responsável |
| --- | --- | --- | --- |
| Retirada do Docker | Removidos docker-compose.yml, docker/php/Dockerfile e docker/nginx/default.conf. deploy.sh usa PHP/Composer/npm locais e não executa Git pull. Requisitos web, filas, scheduler, banco e permissões no README. | Tratado no projeto; build, views, rotas/caches e Bash aprovados. | Ambiente deve provisionar/homologar serviços, migrar dados com backup e confirmar desativação dos containers antigos. |
| Vulnerabilidades Composer | Auditoria original: 47 avisos em 11 pacotes. Versões corrigidas, PHP mínimo 8.4, resolução contra plataforma 8.4.0 e nenhuma supressão. | Tratado para a auditoria atual: exit 0, zero avisos e abandonados. Histórico de versões e avisos em DEPENDENCIES.md. | Segurança deve correlacionar com o relatório original e executar novo scan. |
| Vulnerabilidades npm adicionais | Vite 7.3.6 → 7.3.8 e source-map-js 1.2.1 → 1.2.2, sem atualização principal forçada. | Auditoria atual exit 0, zero vulnerabilidades; assets compilados. | Avaliação contextual do aviso de eval no HTMX; não atribuir os achados adicionais ao relatório sem evidência. |
| Presença dos 13 pacotes | Um direto (Laravel) e 12 transitivos, todos de produção. Classificados separadamente como núcleo HTTP, integrações, bibliotecas funcionais ou auxiliares. Manifests dos consumidores exigem sua instalação. | Nenhum removido/substituído; versões instaladas conferidas com lockfile. Uso, cadeias e impacto por pacote em PACKAGE-REVIEW.md. | Engenharia/Segurança avaliam opções compatíveis/aceite contextual; não ocultar dependências nem reescrever arquitetura sem decisão explícita. |
| Quatro alertas de licença Nette | schema v1.3.5 e utils v4.1.3: versões, metadados e licenças oficiais conferidos com os instalados. Projeto adota BSD-3-Clause somente para esses dois pacotes; licença proprietária inalterada. Aviso integral preservado em docs/licenses/NETTE-LICENSE.md e identificado em THIRD-PARTY-NOTICES.md. CI/deploy verificam versões e avisos; distribuição isolada válida passou e casos sem aviso/aviso alterado falharam. | **BSD-3-Clause adotada e documentada em PACKAGE-REVIEW.md; aguardando validação de Segurança/Compliance.** | Segurança/Compliance deve validar adoção e distribuição efetiva, correlacionar os quatro alertas e confirmar novo scan. Não há encerramento institucional ou do scanner presumido. |
| orca-6571219 e orca-6571220 (JWT) | **TOKENS FIXOS REMOVIDOS DOS TESTES**: os dois literais históricos em AiGraderLtiControllerTest.php:374/:388 foram substituídos por geração em execução com claims preservadas, jti aleatório, algoritmo none e assinatura vazia. Requests/assertions mantidos. Busca atual: zero literais; histórico local: as mesmas duas fixtures. | Tratado no código quanto aos literais. 89 testes afetados e 260 da suíte completa aprovados; Pint passou. **Correlação de ambos os IDs continua pendente** das evidências originais ou de novo scan. | Segurança deve fornecer revisão/path/linha/regra/data e trecho sanitizado por ID ou resultado do novo scan. Nenhuma credencial real identificada nas fixtures; não houve revogação externa ou reescrita de histórico. Se houver evidência real, o dono avalia rotação/revogação e uso indevido. |
| 18 falhas de testes do estado inicial | Expectativas antigas de interface/metadados/publicação e defeito do idioma no mock corrigidos. Asserts de autorização, contexto, notas, feedback e aprovação do professor preservados/reforçados. | 260 testes passaram, incluindo quatro casos novos de idioma. Causas por teste em FAILURE-INVESTIGATION.md; resultados históricos em VALIDATION.md. | Homologação funcional no ambiente alvo por QA/Ambiente. |
| Padronização Pint | 58 arquivos PHP formatados: 45 em app, quatro em lang e nove em tests. Comparação AST normalizada sem diferenças de lógica/literais. | Pint global exit 0; suíte completa aprovada. Nenhum teste/arquivo removido para eliminar violações. | Workflow remoto e matriz PHP 8.4/8.5 ainda precisam de execução na plataforma. |
| Build obrigatório antes do merge | .github/workflows/checks.yml executa auditorias, assets, Pint, testes, views e rotas sem containers. | Arquivo versionável preparado; execução remota não comprovada. | Administrador Git deve exigir os checks e comprovar a regra na branch alvo. |
| Invalidar aprovações após novos commits | Não demonstrável por arquivos locais. | Dependente de ação externa. | Administrador Git: descartar aprovações obsoletas e comprovar configuração efetiva. |
| Impedir autoaprovação | Pipeline não comprova revisão independente. | Dependente de ação externa. | Administrador Git: exigir revisão independente e controlar autoaprovação/bypass. |
| Resolver comentários antes do merge | Não demonstrável pela existência do workflow. | Dependente de ação externa. | Administrador Git: exigir resolução das conversas e registrar evidência. |

## Alertas de containers

Status de todos os grupos abaixo: **proposta de não aplicabilidade após retirada
do Docker, sujeita à validação de Segurança e novo scan**. Remover arquivos não
comprova desativação da infraestrutura já implantada nem encerra alertas.

| Grupo | Arquivo removido/evidência | Controle remanescente / responsável |
| --- | --- | --- |
| Capabilities/privilégios | Compose dos cinco serviços; Dockerfile com build como root e runtime www-data, sem drop explícito de capabilities. | Ambiente: contas sem privilégio e hardening dos serviços nativos; Segurança confirma IDs/linhas originais. |
| Portas e bind | Compose publicava 8088:80 e 3307:3306 sem bind de loopback. | Ambiente: firewall e HTTPS público; MySQL/PHP-FPM privados. |
| HEALTHCHECK | Ausente em Dockerfile/Compose. | Ambiente: monitorar /up, banco, filas, falhas e scheduler separadamente. |
| apt-get/pacotes | Dockerfile instalava ferramentas/bibliotecas sem versões fixadas. | Ambiente: inventário, patches e scan de SO/PHP/web server. |
| Volumes compartilhados | Checkout montado em app/web/worker/scheduler e volume MySQL. | Ambiente: permissões mínimas, backups e plano de transferência; não apagar volumes/banco existente. |
| SELinux | Bind mounts sem labels declarados. | Ambiente: configurar labels/políticas para os serviços nativos quando aplicável; Segurança valida o achado original. |

## Evidências e reavaliação

- [VALIDATION.md](VALIDATION.md): checks atuais, ambiente e comparação histórica.
- [DEPENDENCIES.md](DEPENDENCIES.md): versões anteriores/atuais, decisões PHP e 47 avisos com fontes; JSONs de auditoria antes/depois preservados.
- [PACKAGE-REVIEW.md](PACKAGE-REVIEW.md): classificação, cadeias, impactos e licenças.
- [JWT-REVIEW.md](JWT-REVIEW.md): ocorrências sanitizadas, classificação e limites da busca.
- [FAILURE-INVESTIGATION.md](FAILURE-INVESTIGATION.md): diagnóstico individual dos 18 testes originalmente falhos.
- [REVALIDATION-INVENTORY.json](REVALIDATION-INVENTORY.json): hashes e inventário; fingerprint anterior preservado como histórico.
- [REVALIDATION-SUMMARY.md](REVALIDATION-SUMMARY.md): encaminhamento para Segurança.

Documentação consolidada para separar estado atual, evidências históricas e
pendências; não houve exclusão de resultados desfavoráveis. AGENTS.md, instruções
operacionais, prompts do produto, fixtures e avisos legais permanecem preservados.
Auditorias limpas e testes aprovados não encerram os alertas originais nem
representam homologação do produto.
