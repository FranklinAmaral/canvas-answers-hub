# Revisão dos alertas de JWT — 2026-11-11

Referência: commit `40a7e312791b0c87d2910017feb4c2a12fc63a38` e árvore local com
alterações não commitadas. **Nenhum valor de token, payload ou chave é registrado
neste documento ou no inventário de evidências.**

## Estado confirmado

**TOKENS FIXOS REMOVIDOS DOS TESTES.** Os dois literais foram substituídos por
chamadas a `aiGraderLtiUnsignedToken()` em
`tests/Feature/AiGraderLtiControllerTest.php:382` e `:398`. O helper gera header e
payload em execução com JSON/base64url e um jti aleatório por chamada, preservando
as claims sintéticas originais. O algoritmo continua none e a assinatura continua
vazia, pois são cenários de rejeição; não precisam de chave de assinatura.
Os cenários que exigem assinatura válida continuam usando chaves RSA temporárias
geradas em execução em `AiGraderLtiSecurityTest.php`. Nenhuma credencial real foi
usada nem foi acrescentado registro de tokens em logs.

Os dois testes mantêm os mesmos requests, HTTP 400, motivos de rejeição e
`assertDontSee($token)`. Passaram os 89 testes afetados (436 assertions), depois
os 260 da suíte completa (1035 assertions), e Pint global. A busca de literais
atual encontrou zero ocorrências no texto dos arquivos analisados. Isso trata os
literais locais; **não encerra nem correlaciona automaticamente os IDs Orca**.

## Limitação do relatório original

O anexo disponível contém a solicitação de trabalho, não o relatório Orca.
Não foram disponibilizados repositório/revisão escaneados, caminho, linha,
trecho tarjado, regra de detecção ou identificação sanitizada da ocorrência
para cada ID. Sem isso, não se pode afirmar que as duas fixtures históricas são
`orca-6571219` e `orca-6571220` apenas porque existem duas ocorrências locais.

| Alerta original | Localização confirmada pelo relatório | Classificação do alerta | Status e responsável |
| --- | --- | --- | --- |
| orca-6571219 | Não disponibilizada | Depende de evidência adicional | Aberto para correlação; Segurança deve fornecer revisão/caminho/linha/regra e evidência sem valor do token. |
| orca-6571220 | Não disponibilizada | Depende de evidência adicional | Aberto para correlação; mesma evidência necessária, individualmente por ID. |

Também são necessários a data do scan e o contexto do artefato: checkout atual,
histórico, branch, arquivo gerado ou artefato de implantação. Um fingerprint
gerado por Segurança pode ajudar a correlacionar sem distribuir a credencial.
Não publicar o token integral para obter essa evidência.

## Busca local e correção da conclusão anterior

A busca inicial era restrita ao formato de JWT com assinatura não vazia.
**Ela não detectava as duas fixtures sem assinatura e sua conclusão de ausência
de ocorrências foi corrigida.** A verificação atual busca formas compactas
JWT/JWS com assinatura inclusive vazia e candidatos JWE, validando em memória
que o primeiro segmento representa um header JSON com alg/enc. Somente path,
linha, formato e classificação foram emitidos; valores não foram impressos.

Após substituir os literais, foram examinados 264 arquivos existentes rastreados
ou não ignorados; quatro binários foram identificados e não analisados como
texto. Nenhum literal foi encontrado na árvore atual. Foram também examinados os
253 arquivos do único commit disponível em todas as refs/reflogs locais, com
quatro binários igualmente excluídos. O histórico ainda contém as duas fixtures
nas linhas originais 374/388; nenhuma reescrita foi realizada. O inventário contém
as contagens, os achados históricos sanitizados e os fingerprints anteriores.

Não se trata de scan completo de segredos: a busca não cobre tokens divididos
entre strings, codificação adicional, arquivos binários, arquivos ignorados
(vendor, node_modules, caches, builds/logs locais), histórico remoto ou objetos
Git não alcançáveis por refs/reflogs. Não houve acesso a banco, credenciais de
ambientes Canvas ou infraestrutura externa. A ausência de outra ocorrência
nesse escopo não comprova ausência de exposição real no scan original.

## Ocorrências encontradas e classificação fundamentada

| Localização atual / cenário | Histórico local | Classificação | Justificativa e validação |
| --- | --- | --- | --- |
| tests/Feature/AiGraderLtiControllerTest.php:382 | Literal removido da árvore atual; mesmo path:374 no commit de referência | Token sintético gerado em execução, sem validade operacional LTI | Algoritmo none e assinatura vazia; claims originais preservadas, jti aleatório. Teste `does not accept an unsigned id token or a caller-supplied state` continua exigindo 400, invalid_or_replayed_state e não exposição do token. |
| tests/Feature/AiGraderLtiControllerTest.php:398 | Literal removido da árvore atual; mesmo path:388 no commit de referência | Token sintético gerado em execução, sem validade operacional LTI | Algoritmo none e assinatura vazia; claims originais preservadas, jti aleatório. Teste `does not use flat custom claims from an unverified token` continua exigindo 400, missing_state e não exposição do token. |
| tests/Feature/AiGraderLtiSecurityTest.php:24 e :139 | Helpers de testes no commit de referência | Tokens/chaves sintéticos gerados em execução | Chaves RSA novas, claims de fixtures, endpoints de teste e HTTP fake; helper constrói JWTs para testar assinatura, nonce, expiração, replay, instalação e papéis. Não são credenciais de ambientes Canvas reais. |
| tests/Feature/AiGraderLtiSecurityTest.php:189, :195 e :418; teste de diagnóstico GET login em AiGraderLtiControllerTest.php | Fixtures/testes no histórico local | Exemplos inválidos ou marcadores de teste sem validade operacional | Cobrem formato inválido/algoritmo proibido e sanitização de diagnóstico/logs. Marcadores e dados de entrada não são credenciais nem são aqui reproduzidos. |

O serviço LTI exige RS256, assinatura e contexto OIDC; não foi flexibilizado.
A rejeição de JWT sem assinatura é coberta também pela suíte específica de
segurança, não apenas por testes sem state. Após a substituição dos literais,
foram executados 89 testes LTI e depois os 260 da suíte completa,
todos aprovados. Isso confirma a regressão local, não a correlação dos IDs Orca.

Não foi identificada credencial real nessas ocorrências examinadas. Os cenários
sintéticos foram mantidos com geração de valores em execução; a retirada dos
literais não é uma rotação e não encerra um possível alerta no histórico.
Não houve remoção de segredo real, revogação externa ou reescrita do histórico.
Caso o relatório mostre ocorrência real, o responsável pela credencial deverá
avaliar revogação/rotação e uso indevido, além da retirada/configuração externa.

## Encaminhamento

Segurança deve correlacionar individualmente os dois IDs com as evidências
sanitizadas, confirmar ou rejeitar a classificação de fixtures e executar novo
scan sobre a árvore revisada. Até essa confirmação, **os dois alertas originais
permanecem dependentes de evidência adicional**, sem declaração de encerramento.
