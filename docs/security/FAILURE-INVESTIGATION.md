# Investigação das 18 falhas do baseline — 2026-11-11

Os quatro arquivos afetados foram executados antes das correções: 104 testes,
18 falhas e 86 aprovações (404 assertions). Mensagens completas foram analisadas
nos logs locais ignorados, sem copiar HTML, sessões ou tokens para este documento.
Após as correções, os mesmos arquivos e quatro novos casos do mock passaram.

## Causas comprovadas

**T — expectativa de interface desatualizada.** A aplicação usa os textos UTF-8 de
`lang/pt_BR/ai-grader.php`, e `SetLocale` escolhe pt_BR por padrão nas rotas LTI.
Os testes ainda buscavam texto sem acentos, capitalização antiga ou mensagem
substituída. Mantidos status HTTP, URLs, conteúdo do curso, asserts de banco e
redirecionamentos; atualizados os literais exatos para o catálogo existente.

**M — expectativa de metadados técnicos desatualizada.** A view de detalhe não
expõe `question_mapping_strategy`. Mantida a checagem do item correto e de seus
metadados via `assertViewHas`, e acrescentada proibição de expor `position_type`
no HTML. Notas, resposta e feedback continuam sendo conferidos na interface.

**P — expectativa de publicação desatualizada.** `publicationModes()` admite só
`auto_publish` e `teacher_approval`; `normalizePublicationMode()` converte o modo
legado `auto_publish_with_review_flags` para aprovação. Request, processor,
pipeline e publisher usam essa política. Dois testes antigos contradiziam essa
normalização. Não se reativou publicação automática para dados legados. Os testes
agora verificam aprovação pendente, persistência de nota/feedback/flags e ausência
de chamada ao publisher, mesmo se o mock do processor retornar itens corrigidos.

**F — defeito da aplicação no provider mock.** O contexto já contém
`feedbackLanguage` e o builder usa o idioma da Blueprint (padrão pt_BR), mas o mock
retornava português ASCII fixo, inclusive com idioma en. Feedback simples,
rubrica e critérios agora usam traduções pt_BR/en e o idioma explícito do contexto,
independente do locale da interface. Os dois asserts originais de feedback
acentuado foram mantidos. Pontuação, normalização, quota e review flags preservadas.

## Classificação por teste originalmente falho

Nomes abaixo pertencem a `Tests\Feature`; abreviados apenas os nomes das classes.

| Teste original | Esperado antigo versus obtido / causa | Tratativa |
| --- | --- | --- |
| AiGraderLtiControllerTest — launch in child course shows teacher correction list | `Correcoes` versus `Correções` — T | Literal UTF-8; mantidos aluno, questão, status e proibição de controles de Blueprint. |
| AiGraderLtiControllerTest — launch uses legacy is_blueprint false when blueprint field is absent | Mesmo título antigo — T | Literal UTF-8, preservado teste da detecção legada e da ausência de controles administrativos. |
| AiGraderLtiControllerTest — allows instructor role without administrative login | Mesmo título antigo — T | Mantidos sessão LTI validada, papel Instructor e HTTP 200. |
| AiGraderLtiControllerTest — shows setup screen when blueprint course has no GraderAI configuration | `nao esta ... correcao` versus texto acentuado — T | Texto real de habilitação, preservado course_id e página de setup. |
| AiGraderLtiControllerTest — shows empty child course state when a child course mapping already exists | Mensagem antiga versus `Nenhum item encontrado com os filtros atuais.` — T | Mensagem atual da tabela e proibição do setup de Blueprint. |
| AiGraderLtiControllerTest — configured blueprint screen shows provider and modes | `Configuracao` versus `Configuração` — T | Mantidos provider e modos configurados. |
| AiGraderLtiControllerTest — saving blueprint settings again updates without 404 | Flash `Configuracoes` versus `Configurações` — T | Flash exato, redirecionamento e persistência continuam obrigatórios. |
| AiGraderLtiControllerTest — saving essay question instructions redirects back to the current question without 404 | Flash `Questao` versus `Questão` — T | Mantidos fragmento da questão, estado no banco e conteúdo ao reabrir. |
| AiGraderLtiControllerTest — blueprint screen exposes classic quizzes configuration action | Capitalização/acentos antigos versus catálogo atual — T | Mantidas todas as ações esperadas e proibição da ação antiga. |
| AiGraderLtiControllerTest — blocks LTI blueprint settings updates from another course context | Texto ASCII versus mensagem acentuada; HTTP 403 já correto — T | Mantido 403 e acrescentada checagem de que a publicação da Blueprint não mudou. |
| AiGraderLtiControllerTest — shows correction detail for current child course | Esperava `position_type` no HTML, ausente — M | Verificação de metadados no item da view; HTML não deve expor detalhes técnicos. |
| AiGraderLtiControllerTest — responds to LTI routes on grader domain | Título ASCII versus título acentuado — T | Mantidos domínio, aluno, HTTP e resposta JWKS sem chave configurada. |
| AiGraderLtiControllerTest — shows informational page for bare LTI URL without context | `URL e` versus `URL é` — T | Mensagem atual e ausência de erro técnico preservadas. |
| AiGraderLtiControllerTest — loads public LTI installation page on grader domain | `Instalacao`/`configuracao` versus acentos — T | Preservados usuário não autenticado, URLs oficiais e JSON. |
| AiGraderPendingProcessorTest — processes pending item with MockProvider and saves ai score and feedback | `Correção` esperada, mock retornava `Correcao` — F | Corrigido provider; assertion original mantida. |
| AiGraderPendingProcessorTest — changes auto publish with review flags to ai corrected when no flags exist | Esperava ai_corrected; obtinha pending_teacher_approval — P | Teste renomeado para documentar normalização legada; mais asserts de estado e resultado. |
| AiGraderPipelineJobsTest — run pipeline publishes for auto publish with review flags | Esperava publicação; pipeline a bloqueava — P | Teste renomeado; publisher não pode ser chamado, coleta/processamento continuam conferidos. |
| AiGraderServicesTest — AiGraderCorrectionEvaluator returns valid result for pending item | Mesmo feedback ASCII do mock — F | Provider corrigido; teste e assertions originais mantidos. |

Não foi comprovado defeito de autenticação/autorização, fuga de contexto ou falha
de gravação entre essas 18 mensagens. A suíte de segurança LTI e a suíte completa
foram executadas; isso não substitui homologação nem scan externo.

## Configuração e regressões

O problema local anterior de geração RSA no Windows foi resolvido por
`OPENSSL_CONF` apenas no processo; SQLite habilitado por flags, banco `:memory:`.
Arquivo `.env.testing` local sintético evita warnings de dotenv; não foi incluído
no produto. Esses problemas de configuração não são classificados como causa
das 18 falhas já reproduzidas com o ambiente correto.

Quatro novos testes cobrem feedback pt_BR/en, simples/com rubrica, com locale da
interface oposto; conferem também nota, percentual, identificação de critério e
flags. Os testes de bloqueio entre cursos verificam que Blueprint/questão não
foram alteradas. Nenhum teste foi desabilitado e nenhum status de autorização foi
flexibilizado. Uma eventual reintrodução do modo legado de publicação exige
decisão de Produto/Segurança; não foi necessária para corrigir esta suíte.
