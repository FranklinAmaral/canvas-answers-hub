# Revisão de dependências — 2026-11-11

Referência: `40a7e312791b0c87d2910017feb4c2a12fc63a38` **com alterações locais não
commitadas**. Requisitos e versões de dependências preservados; o código recebeu
padronização Pint, validada por comparação AST e suíte completa. O relatório
original não foi disponibilizado; o escopo são os 13 nomes registrados no pedido
e no acompanhamento. Vite/source-map-js são achados adicionais de auditoria, não
pacotes atribuídos ao relatório sem evidência.

## Critério de classificação e evidência

A expressão genérica anterior “todos são componentes estruturais do Laravel” foi
revisada. **Obrigatoriedade na instalação não comprova uso direto nem execução de
uma funcionalidade.** Há núcleo HTTP, bibliotecas de integração, funcionalidades
distribuídas com o framework e bibliotecas auxiliares transitivas.

Foram conferidos `composer.json`, `composer.lock` (incluindo `packages-dev`),
`vendor/composer/installed.json`, os manifests instalados e `composer depends
--locked` da investigação anterior. Os 13 nomes estão em `packages`, com versões
instaladas iguais às travadas. Só Laravel é dependência direta da aplicação;
os outros 12 são transitivos. Nenhum é exclusivo de desenvolvimento. O plugin
Laravel do Pest também consome o framework em desenvolvimento, mas não é a causa
da sua presença na distribuição de produção.

`L` na tabela significa `GraderAI → laravel/framework`, requisito direto `^13.0`.
As arestas citadas são requisitos `require`, não `suggest` ou `require-dev`.
`0` significa **zero avisos aplicáveis na auditoria Composer atual**, exit 0;
não significa encerramento do alerta original. Os JSONs de auditoria e o
[inventário verificável](REVALIDATION-INVENTORY.json) acompanham a análise.

| Pacote / versão instalada | Vínculo com a aplicação / escopo | Cadeia obrigatória atual | Classificação, utilização e impacto concreto da remoção/substituição | Auditoria |
| --- | --- | --- | --- | --- |
| laravel/framework 13.42.0 | Direta / produção | GraderAI exige `^13.0`; Pest Laravel também o requer em dev | Framework central: bootstrap, container, ORM, Blade, autenticação, rotas e filas. Removê-lo impede inicialização e exige migração de framework/arquitetura, fora do escopo. Mantido corrigido. | 0 |
| symfony/http-foundation 7.4.23 | Transitiva / produção | L → http-foundation `^7.4.0` ou `^8.0.0`; também http-kernel e fruitcake/php-cors | Base HTTP usada pelas requests/responses/cookies/sessões do Laravel. Troca exige compatibilidade com essas APIs e middleware/CORS, incluindo LTI; remover quebra o processamento HTTP. | 0 |
| symfony/http-kernel 7.4.23 | Transitiva / produção | L → http-kernel `^7.4.0` ou `^8.0.0` | Integração HTTP e tratamento de erros do framework. Substituição afeta pipeline de requisição/exceções; não é pacote opcional de testes. | 0 |
| symfony/routing 7.4.23 | Transitiva / produção | L → routing `^7.4.0` ou `^8.0.0` | Compilação/geração de rotas; o produto usa domínio isolado LTI. Remoção afeta cache/matching/URLs e exige substituir a integração do roteador do Laravel. | 0 |
| guzzlehttp/guzzle 7.15.2 | Transitiva / produção | L → guzzle `^7.8.2` ou `^8.0` | Cliente HTTP ativo via `Http` em CanvasApiClient, LTI JWKS e LmStudioAiGraderProvider. Remoção afeta Canvas, validação de lançamentos e IA. Trocar só chamadas da aplicação não elimina o requisito nominal do Laravel. | 0 |
| guzzlehttp/psr7 2.13.2 | Transitiva / produção | L → psr7 `^2.9` ou `^3.0`; L → Guzzle → psr7 `^2.13` | Mensagens/streams/factories utilizados pelo cliente HTTP. Alternativa PSR-7 precisa atender às APIs concretas exigidas pelos consumidores e aos manifests, não apenas à interface PSR. Retirar o pacote sem adaptação quebra clientes/requests. | 0 |
| symfony/mailer 8.0.15 | Transitiva / produção | L → mailer `^7.4.0` ou `^8.0.0` | Biblioteca funcional de transporte de e-mail, não núcleo de toda request. Integra notifications/recuperação de senha (`PasswordResetLinkController`); o exemplo usa transporte log. Desabilitar e-mail não elimina o requisito do framework; substituir requer adaptar os transportes/contratos e validar recuperação de senha. | 0 |
| symfony/mime 7.4.22 | Transitiva / produção | L → mime `^7.4.0` ou `^8.0.0`; mailer → mime `^7.4` ou `^8.0` | Biblioteca funcional de composição de mensagens. Remoção afeta a integração de e-mail e seu formato/headers; substituir só o transporte não satisfaz os requisitos do Laravel/Mailer. | 0 |
| symfony/polyfill-intl-idn 1.43.0 | Transitiva auxiliar / produção | L → mime → polyfill `^1.10`; L → egulias/email-validator → polyfill `^1.26` | Compatibilidade IDN; não núcleo estrutural. A extensão intl está habilitada no host de validação e pode suprir operações em runtime, mas não remove as exigências de pacote nos manifests. Para retirá-lo legitimamente, os consumidores precisam dispensá-lo/suportar outro requisito; `replace` fictício só esconderia a dependência. | 0 |
| league/commonmark 2.10.7 | Transitiva funcional / produção | L → commonmark `^2.8.1` | Funcionalidade Markdown distribuída com o framework/mail; nenhuma chamada direta da aplicação foi demonstrada. Não é classificado como núcleo HTTP. Ainda é exigência nominal do Laravel: retirar/adotar parser diferente requer mudança compatível dessa integração e do manifesto upstream, com validação do mail/Markdown. | 0 |
| league/flysystem 3.36.0 | Transitiva funcional / produção | L → flysystem `^3.25.1`; L → flysystem-local → flysystem `^3.0.0` | Filesystem/discos fornecidos pelo framework; nenhuma chamada direta de Storage na aplicação foi demonstrada. Não se presume que todos os discos estejam em uso. Mesmo assim, remover da distribuição atual viola os requisitos do Laravel/adaptador local; substituir exige adaptar serviços/adaptadores e validar operações de arquivos. | 0 |
| nette/schema 1.3.5 | Transitiva auxiliar / produção | L → commonmark → league/config 1.2.0 (`^1.1.1`) → schema `^1.2` | Valida schemas da configuração do parser, sem utilização direta comprovada no código da aplicação. Não é núcleo estrutural do Laravel. Removê-lo quebra o consumidor league/config; alternativa válida seria versão compatível desse consumidor/parser sem a cadeia, não um `replace` nominal. | 0 |
| nette/utils 4.1.3 | Transitiva auxiliar / produção | Mesma cadeia → nette/schema → utils `^4.0` | Utilitários necessários ao schema, sem uso direto comprovado pela aplicação. Remoção depende de eliminar/substituir legitimamente seu consumidor; não basta remover uma entrada do manifesto da aplicação. | 0 |

**Resultado: zero removidos e zero substituídos.** Não foi encontrado pacote
dispensável por alteração local dos requisitos preservando a distribuição atual.
Isso não afirma que todas as funcionalidades sejam indispensáveis ao produto,
nem que uma migração de arquitetura seja a única alternativa possível no futuro.
O impedimento comprovado é a árvore obrigatória dos manifests atuais; remoções
funcionais/auxiliares dependem de opção upstream compatível ou adaptação explícita
dos consumidores, ainda não demonstrada. Não se introduziu fork ou reescrita para
eliminar bibliotecas já corrigidas. Remover ferramentas dev não altera essas cadeias.

Fontes oficiais correspondentes às versões: [Laravel 13.42.0](https://github.com/laravel/framework/blob/v13.42.0/composer.json),
[CommonMark 2.10.7](https://github.com/thephpleague/commonmark/blob/2.10.7/composer.json),
[League Config 1.2.0](https://github.com/thephpleague/config/blob/v1.2.0/composer.json).
As referências Git exatas e todos os pais diretos constam do inventário.

## Nette: adoção BSD-3-Clause e evidências

**O projeto AdminHub GraderAI adota expressamente a alternativa BSD-3-Clause
para utilização de nette/schema 1.3.5 e nette/utils 4.1.3.** A escolha se limita
a essas duas dependências e não altera a licença proprietária do projeto.
Não modifica os metadados oficiais, os arquivos instalados ou o lockfile.

**BSD-3-Clause adotada e documentada em PACKAGE-REVIEW.md; aguardando validação de Segurança/Compliance.**
Esta é uma adoção documentada pelo projeto, não aprovação institucional nem
encerramento dos quatro alertas pelo scanner.

As versões exatas em composer.lock e vendor/composer/installed.json foram
reconfirmadas como v1.3.5 e v4.1.3, respectivamente. Os arquivos oficiais dessas
versões foram baixados e comparados byte a
byte por SHA-256 com `vendor/nette/schema/license.md` e
`vendor/nette/utils/license.md`; **ambas as comparações coincidiram**.

| Pacote | Referência Git travada | Arquivo oficial da mesma versão |
| --- | --- | --- |
| nette/schema 1.3.5 | `f0ab1a3cda782dbc5da270d28545236aa80c4002` | [license.md v1.3.5](https://raw.githubusercontent.com/nette/schema/v1.3.5/license.md) |
| nette/utils 4.1.3 | `bb3ea637e3d131d72acc033cfc2746ee893349fe` | [license.md v4.1.3](https://raw.githubusercontent.com/nette/utils/v4.1.3/license.md) |

Os dois arquivos têm SHA-256
`b3bc3d38b9ec29d98bc83bd5fa44e583e8303cd97b62d203a9e5e21935126de9`.
O texto permite **escolher** New BSD, GPL v2 ou GPL v3; não são três obrigações
simultâneas. Os manifests indicam BSD-3-Clause, GPL-2.0-only e GPL-3.0-only.
Os metadados oficiais também foram comparados com os manifests instalados:

| Fonte oficial de metadados | SHA-256 oficial e instalado (iguais) |
| --- | --- |
| [schema v1.3.5 composer.json](https://raw.githubusercontent.com/nette/schema/v1.3.5/composer.json) | `4c16ab6bde2c4602b3bfb88445797298a51e9f9ebd3b3c5d32fdbe1f41c4569d` |
| [utils v4.1.3 composer.json](https://raw.githubusercontent.com/nette/utils/v4.1.3/composer.json) | `2d3a56e2e42853e6915d7867e513c053138214b90323d61052ef7f130c5057c1` |

A alternativa BSD exige preservar avisos de copyright, condições e disclaimer
na redistribuição de fonte/binários, e respeitar a condição de não endosso.
O aviso inclui copyright de David Grudl e as três condições BSD, mantidos sem
edição na cópia fiel [NETTE-LICENSE.md](../licenses/NETTE-LICENSE.md), comum aos
dois pacotes. O texto upstream completo, inclusive suas alternativas GPL, foi
preservado por integridade; a alternativa adotada é BSD-3-Clause.

### Preservação na distribuição

O deploy instala dependências por Composer sem etapas de stripping de licenças;
os arquivos originais continuam em vendor/nette/schema/license.md e
vendor/nette/utils/license.md, além dos copyrights nos fontes. Não foi encontrado
outro pipeline de empacotamento no repositório. A documentação de distribuição
agora inclui [THIRD-PARTY-NOTICES.md](../../THIRD-PARTY-NOTICES.md) e a cópia fiel
do aviso; esses caminhos não são excluídos por export-ignore em .gitattributes.
Devem acompanhar distribuições de fonte e os materiais/documentação de releases
binários/empacotados. README registra esse requisito.

`php tools/check-nette-licenses.php` verifica versões adotadas, alternativa BSD
nos metadados e integridade dos avisos instalados e da cópia fiel. CI e deploy
executam o check após Composer e antes de migrations. Ausência/alteração do aviso
ou versão não revisada interrompe o check; upgrades exigem nova conferência e
atualização dos avisos/documentação. Não há download de licença em produção.
O mesmo check deve ser executado no diretório do artefato final; etapas externas
de empacotamento não foram inspecionadas ou homologadas.

O check passou no checkout e em cópia isolada de distribuição. Nessa cópia,
ausência do aviso e alteração da licença instalada foram corretamente rejeitadas
com exit 1; vendor real e lockfiles permaneceram intactos. Segurança/Compliance
deve validar a adoção documentada, a distribuição efetiva e a correlação dos
quatro alertas originais. Não houve aprovação institucional.

## Encaminhamento

Manter as versões auditadas, solicitar novo scan e decisão contextual de Segurança
para os pacotes presentes. Avaliar com Engenharia opções upstream compatíveis se
a política exigir remoção das bibliotecas funcionais/auxiliares; não presumir
rejeição por GPL quando há alternativa BSD. A obtenção do relatório original,
o aceite de risco/licença e a homologação do ambiente permanecem externos.
