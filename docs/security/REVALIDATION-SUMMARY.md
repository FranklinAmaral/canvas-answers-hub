# Encaminhamento para reavaliação de Segurança — 2026-11-11

## Referência da entrega

HEAD: `40a7e312791b0c87d2910017feb4c2a12fc63a38`, **com alterações locais ainda
não commitadas**, incluindo código/testes, manifests/locks, documentação, workflow
e remoções Docker. O HEAD sozinho não contém as correções. Nenhum commit, push,
deploy ou ajuste administrativo externo foi realizado.

Fingerprint SHA-256 dos 254 arquivos de fonte/configuração/testes e avisos revisados:
`7d554742975ab440218eada5b35cad6441bc29592def7c022db5fc013d084d61`.
O [inventário](REVALIDATION-INVENTORY.json) lista cada hash, método, versões,
arestas de dependência, auditorias e resultados sanitizados da busca JWT.
Documentação e artefatos gerados são excluídos desse fingerprint para evitar
circularidade; o scan solicitado deve incluir a entrega completa. O fingerprint
anterior e o histórico permanecem no inventário para rastreabilidade da
padronização e da substituição das fixtures JWT.

## Alterações e conclusão sobre dependências

Docker/Compose removidos, deploy/README adaptados à execução direta, dependências
vulneráveis corrigidas e 18 falhas de testes tratadas, preservando autorização,
isolamento entre cursos e aprovação do professor. A padronização final formatou
58 arquivos com Pint; a comparação AST normalizada não identificou alteração de
lógica ou literais. Documentos de acompanhamento/validação foram consolidados;
histórico de versões foi centralizado em DEPENDENCIES.md. Nenhum arquivo foi
excluído na limpeza documental. Prompts do produto, fixtures, instruções válidas,
licenças e evidências/pendências foram preservados.

**TOKENS FIXOS REMOVIDOS DOS TESTES:** as duas fixtures literais foram
substituídas por geração em execução, mantendo claims, cenários sem assinatura,
requests e assertions de rejeição/não exposição. Um jti aleatório evita tokens
fixos entre execuções. Cenários com assinatura válida mantêm chaves RSA
temporárias de teste. Não foram utilizadas credenciais reais ou acrescentados
logs de tokens.

Para Nette, a adoção BSD-3-Clause foi registrada expressamente, os avisos upstream
foram incluídos integralmente em docs/licenses/NETTE-LICENSE.md e identificados em
THIRD-PARTY-NOTICES.md. CI/deploy incluem o check de versões/avisos antes de
migrations. A suíte completa passou novamente (260 testes, 1035 assertions),
assim como Pint, composer validate, sintaxe Bash e verificações de distribuição
positiva/negativas. Não foi executado deploy nem pipeline remoto.

Reconfirmados **13 pacotes presentes**, um direto e 12 transitivos, todos em
produção; zero removidos/substituídos. A classificação foi refinada: dependências
obrigatórias dos manifests não equivalem a uso direto ou a núcleo estrutural.
Bibliotecas funcionais e auxiliares também foram identificadas. Nenhum pacote
dispensável na resolução atual foi comprovado; uma remoção legítima exige
alternativa compatível/adaptação dos consumidores, não ocultação da árvore.
Versões, cadeias e impactos por pacote em [PACKAGE-REVIEW.md](PACKAGE-REVIEW.md).

## Verificações executadas

Em PHP 8.5.12, SQLite em memória e fixtures sintéticas:

| Check | Resultado |
| --- | --- |
| Pint global após formatação dos 58 arquivos | Exit 0; zero divergências |
| Testes LTI após substituição dos literais JWT | 89 passaram, 436 assertions; zero falhas |
| Suíte completa após substituição dos literais JWT | 260 passaram, 1035 assertions; zero falhas |
| Busca de JWTs literais na árvore atual | Zero ocorrências no texto dos arquivos analisados; duas fixtures ainda no histórico local |
| Testes LTI/autorização/processamento/publicação, verificação anterior | 127 passaram, 582 assertions |
| Composer audit --locked | Exit 0; zero avisos aplicáveis e abandonados |
| npm audit | Exit 0; zero vulnerabilidades |
| Composer validate --strict / check-platform-reqs | Passaram |
| Build dos assets, compilação das views, config/route cache e listagem de rotas | Passaram; caches limpos após validação |
| Sintaxe Bash e git diff --check | Passaram |
| Licenças Nette locais versus arquivos oficiais dos tags instalados | SHA-256 idêntico em ambas as comparações |
| Metadados oficiais de schema v1.3.5 / utils v4.1.3 | SHA-256 idêntico aos manifests instalados; BSD-3-Clause disponível em ambos |
| Verificação de avisos na distribuição | Checkout/cópia isolada íntegros passaram; aviso ausente ou licença alterada na cópia rejeitados com exit 1 |

Os resultados e a comparação histórica constam de [VALIDATION.md](VALIDATION.md).
O aviso existente de eval no HTMX permanece no build. Os checks do pipeline foram
preservados, incluindo Pint global, que agora passou localmente.
Não foram executados PHP 8.4, workflow remoto, instalação/homologação no ambiente
alvo nem operações em bancos reais.

## Situação dos alertas JWT

| ID original | Conclusão atual |
| --- | --- |
| orca-6571219 | Depende de evidência adicional; localização original não fornecida. Não encerrado. |
| orca-6571220 | Depende de evidência adicional; localização original não fornecida. Não encerrado. |

A busca ampliada identificou duas fixtures sintéticas sem assinatura no commit
inicial, em `tests/Feature/AiGraderLtiControllerTest.php:374` e `:388`. Os literais
foram removidos da árvore atual, substituídos por chamadas ao helper nas linhas
382/398. A verificação atual encontrou zero literais no texto analisado e as duas
fixtures permanecem somente no histórico, sem reescrita. Sem relatório original
ou novo scan, não se pode correlacionar esses locais aos IDs por coincidência de
quantidade. Não foi identificada credencial real nas fixtures; isso não exclui
exposição em outros artefatos/revisões. Ver [JWT-REVIEW.md](JWT-REVIEW.md).

Segurança deve fornecer, por ID: revisão/repositório/branch, path/linha, data,
regra e trecho sanitizado/fingerprint da ocorrência, sem distribuir o token.
Se a evidência revelar credencial real, o dono deve avaliar revogação/rotação e
uso indevido; remover o arquivo não basta para encerrar o achado. Nenhuma
credencial externa foi revogada ou rotacionada nesta revisão.

## Pendências e solicitação de novo scan

- **Containers:** Dockerfile, Compose e configuração nginx de container removidos;
  execução direta documentada. Alertas de capabilities, portas/binds, HEALTHCHECK,
  apt-get, volumes e SELinux ficam como **proposta de não aplicabilidade após
  retirada do Docker, sujeita à validação de Segurança e novo scan**. Ambiente
  deve confirmar desativação da infraestrutura antiga e controles equivalentes
  de rede, privilégios, patches, monitoramento, arquivos e políticas do host.
- **Licenças:** **BSD-3-Clause adotada e documentada em PACKAGE-REVIEW.md; aguardando validação de Segurança/Compliance.**
  A adoção abrange somente nette/schema v1.3.5 e nette/utils v4.1.3, sem alterar a
  licença proprietária. Metadados e avisos oficiais coincidem com os instalados.
  THIRD-PARTY-NOTICES.md e docs/licenses/NETTE-LICENSE.md preservam os avisos;
  tools/check-nette-licenses.php é executado por CI/deploy e deve conferir também
  o artefato final. Compliance valida a adoção/distribuição efetiva e Segurança
  correlaciona os alertas/novo scan; não há aprovação ou encerramento presumido.
- **Branches:** administrador Git deve comprovar descarte de aprovações obsoletas,
  build obrigatório, revisão independente sem autoaprovação/bypass e resolução
  de comentários. Workflow versionado e checks locais aprovados não comprovam
  regras ativas nem execução remota do pipeline.
- **Homologação:** Ambiente/QA deve validar PHP 8.4, web/HTTPS, banco, filas,
  scheduler, permissões, backups e conectividade Canvas/IA na implantação sem
  Docker. Decisões sobre remoções funcionais/auxiliares e aceites de risco cabem
  a Engenharia/Segurança; nenhuma migração arquitetural foi realizada.

**Solicitação a Segurança:** executar novo scan da árvore revisada identificada
acima, incluindo locks e arquivos novos/não commitados, correlacionar cada alerta
com as evidências e validar as propostas/pendências individualmente. Quando houver
commit de entrega autorizado, registrar o SHA desse commit como referência do
scan definitivo. Auditorias limpas e testes aprovados não encerram os alertas
originais. **O produto não é declarado homologado.**
