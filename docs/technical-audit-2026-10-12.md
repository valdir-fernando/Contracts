# Auditoria técnica incremental — 12/10/2026

> Este relatório registra o snapshot da auditoria incremental. A [etapa seguinte de Clean Architecture](clean-architecture-development.md) moveu validação/locks para Infrastructure e desacoplou os casos de uso; consulte [architecture.md](architecture.md) para a estrutura atual.

## Escopo e evidências

Base: `2eb363f`, árvore limpa ao início. Inspecionados README, documentação funcional/operacional e arquitetural, rotas, configuração, Docker, migrations, policies, models, controllers, Livewire, casos de uso, serviços, importadores e testes. Foram preservados schema, campos, registros históricos, tecnologias, escopos e regras financeiras. Nenhum commit, deploy ou migration foi executado no banco da instalação.

A suíte original passou em SQLite: **140 testes, 1.496 asserções**. Os novos testes de segurança/exportação foram executados também em uma cópia temporária do commit original: **17 falhas em 21 casos**, demonstrando mutações após revogação, exposição de formulários de conflito com ator desatualizado, hash sem leitura do ator atual, ausência de invalidação por conteúdo/expiração durante geração e substituição de arquivo pronto por entrega duplicada. Três dessas falhas são diferenças de resposta para contratos excluídos (403 antes, 404 agora), não exposição de conteúdo. A execução adversarial original também reportou um teste arriscado por handlers não removidos depois de uma falha; isso não ocorreu nas suites corrigidas.

## Problemas confirmados e correções

| Gravidade / impacto | Evidência e arquivos | Correção |
| --- | --- | --- |
| Alta — sessão revogada ainda conclui mutação | `AccountController::update`, `AdministrationController::reconcile`, `SavedReportController::destroy`; middleware de teste incrementa a versão de sessão depois do middleware real e as rotas anteriores retornam sucesso | Reutilização de `LockActor::lock` dentro da transação antes da escrita, mantendo ordem dos locks e auditoria atômica. |
| Alta — autorização desatualizada em resposta de conflito | `ContractAmendmentController`, `ContractFinanceController`, `ContractReviewController`; serviço simulado revoga sessão ou remove perfil administrativo antes de lançar conflito; versões anteriores renderizam 409 | `LockActor::current`, reload do contrato ativo e policy com ator atual antes de renderizar; guard também recebe o ator atual. Contrato/registro financeiro excluído deixa de ser apresentado como ativo. |
| Alta — PDF individual sem revalidação depois de operação demorada | `ReportController::detail`; revogação injetada durante a montagem da view | Ator/contrato atuais antes da geração, comparação do hash após renderização, cleanup de arquivo em falha de autorização ou auditoria, evento apenas quando autorizado. Exportação síncrona de auditoria também compara hash, incluindo sessão. |
| Média — consumo de memória/CPU proporcional à coleção inteira | `ReportExport::accessHash`; `get()->toArray()` de todos os contratos visíveis em cada solicitação, job e download | SHA-256 incremental sobre projeção SQL em lotes de 500 IDs, sem hidratação dos contratos. Ator atual e escopos permanecem parte do hash; versão e timestamp de contratos também invalidam exportações com dados alterados. |
| Média — transação inclui renderização PDF/XLSX e duas leituras dos contratos | `ReportRenderer::write`, `AuditReportRenderer::write`; Dompdf, planilha e gravação final executavam dentro de `DB::transaction` | Uma leitura captura linhas formatadas e totais/grupos em arquivo temporário privado; a renderização ocorre após o commit. Auditoria também calcula contagem na mesma leitura dos eventos. |
| Média — job duplicado gera novo arquivo e evento | `GenerateReport::handle`; entrega repetida antes substituía o caminho de exportação pronta | Claim condicional `pending → processing`, demais estados não geram novamente. Expiração/permissões/conteúdo são rechecados após processamento. Publicação e evento são transacionais; falha remove arquivo e limpa caminho; perda de autorização marca `revoked`. |
| Média — alerta de duplicidade somente depois da gravação | `ContractController::savedResponse`, `SaveContract::execute`; teste anterior permitia segundo cadastro sem confirmação | No cadastro nativo, consulta preventiva dos registros acessíveis com número/fundo/exercício/processo iguais. Falha de validação preserva formulário e pede checkbox explícito; confirmação permite gravar e fica na auditoria. Lock do fundo e leitura corrente do candidato serializam criações por atores diferentes. Não há unicidade nova, mesclagem ou remoção de registros. |
| Baixa — SQL de vencimentos repetido | `ContractReport::query`, `ExpirationCalendar::query` | Relatório vencido usa `withValidity`; relatórios a vencer/calendário/alertas usam `endingBetween`. Limites civis inclusivos, término atualizado prioritário e exclusão de revisão/datas incompletas permanecem iguais. |

O hash é versionado (`v2`). Exportações pendentes/prontas com o formato anterior ficam inacessíveis e precisam ser solicitadas novamente; não há conversão permissiva de hashes antigos. Alterações em qualquer contrato visível podem revogar um relatório mesmo que esse contrato não integre seu filtro: invalidação conservadora deliberada. Novos relatórios são snapshots, não uma cache reutilizada sem checagem.

## Hipóteses não confirmadas e controles preservados

- **`/contracts?review=1` já funciona.** `ContractController` incorpora `ContractSearch::rules`; `ContractListingQuery` chama `ContractSearch::apply`, que reutiliza `pendingReview`. `ContractBrowsingTest` cobre dashboard, combinação de busca, validação e escopo regular/administrativo. Não foi aplicada correção redundante.
- **Classificação de vigência já centralizada.** `ContractValidity` é PHP puro, recebe a referência explicitamente; `Contract::validityOn` e linhas de relatório a reutilizam. Testes existentes cobrem início/término no dia, datas ausentes, datas históricas inconsistentes e prioridade do término atualizado. Foi consolidada apenas a tradução SQL de janela de vencimentos, com regressão de limites/calendário/relatório.
- **`app/Application/Contracts/ContractWriter.php` não existe.** O adaptador é `app/Services/ContractWriter.php`, que delega a `Application/Contracts/SaveContract.php`. Ambos, além de `ChangeContractLifecycle`, foram examinados.
- Policies e `visibleTo` continuam aplicados no backend, com acesso por fundo/secretaria e recusa de propriedade não conciliada para usuários comuns. Anexos, aditivos e registros financeiros são consultados pelo contrato pai; não foi confirmado bypass horizontal ordinário nesses fluxos.
- Uploads mantêm validação de extensão, conteúdo MIME, tamanho, armazenamento privado, nomes gerados e SHA-256 no download. PDF mantém PHP e acesso remoto desabilitados. CSV e XLSX mantêm proteção contra fórmulas e precisão textual.
- Escritas usam payloads validados/mapeados; models com `guarded = ['id']` não foram transformados em destino de entrada HTTP irrestrita. Campos derivados/pessoais protegidos, último administrador, soft delete, versões e rollback da auditoria permanecem cobertos pelos testes existentes.
- As migrations já possuem índices de fundo/secretaria, exercício, datas e vínculos de documentos/aditivos/financeiro. Não foram adicionados índices sem medição de planos de consulta reais. Não foi confirmado N+1 nesses fluxos revisados; telas de auditoria/documentos já usam eager loading de atores.

## Arquitetura antes e depois

O projeto já era um monólito Laravel modular com domínio temporal independente, aplicação para escrita/ciclo de vida e adaptadores Eloquent. `SaveContract` e `ChangeContractLifecycle` continuam recebendo models e usando Gate, Validator, DB e exceções HTTP; portanto, a camada de aplicação ainda depende do framework. Não foi criada uma camada de repositories que apenas espelharia o ORM.

A melhoria concentra-se em fronteiras efetivas: `ReportRenderer` injeta seus colaboradores; `ContractReport` compartilha o acumulador entre resumo e captura; `ReportRows` é um adaptador de arquivo temporário usado pelos dois renderizadores; `LockActor` compartilha a validação atual de ator em mutações e respostas sensíveis. Regras e autorização permanecem junto de suas transações, e persistência/auditoria continuam atômicas. O SQL de vencimentos fica no adaptador Eloquent; não foi colocado no domínio puro.

A captura reduz a transação à consulta, formatação e escrita temporária, que ainda são proporcionais ao volume. Linhas e totais derivam do mesmo cursor SQL, evitando divergência mesmo se os dados forem alterados depois do commit. O hash em lotes usa a transação de leitura existente e foi verificado no MySQL 8.4 com o isolamento padrão; `REPEATABLE READ` mantém o snapshot entre leituras consistentes ([manual MySQL](https://dev.mysql.com/doc/refman/8.4/en/innodb-consistent-read.html)). Configurações de isolamento diferentes exigem validação própria. O cursor de captura ainda pode ter buffering do PDO, conforme a [documentação Laravel](https://laravel.com/docs/13.x/eloquent#cursors); não se afirma memória constante para a exportação completa.

## Desempenho medido

Ensaio sintético em PHP 8.4.26/SQLite em memória, 30.000 contratos, mesma instância, sem dados pessoais reais:

| Implementação de hash | Tempo observado | Pico adicional de memória PHP |
| --- | ---: | ---: |
| Original, models + array completo | 3.936,39 ms | 126.938.712 bytes |
| Nova, projeção SQL e lotes de 500 | 115,69 ms | 504.592 bytes |

Uma medição local, não um benchmark de produção. Complexidade continua **O(N)** em contratos visíveis e **O(S)** em escopos; há aproximadamente `ceil(N/500)` consultas de contratos, mais uma consulta final vazia se N for múltiplo de 500. Não foi usada soma/MAX como substituto de fingerprint, pois isso não preservaria a detecção de todas as alterações de visibilidade. O teste de 1.200 registros confirma três consultas limitadas e zero models Contract hidratados.

## Verificações executadas

Os comandos PHP abaixo foram executados em containers temporários com imagem `contracts-app:latest`, código montado e volume de vendor somente para leitura. `tests/bootstrap.php` isolou storage e caches por processo. O MySQL foi criado em rede interna própria, sem porta publicada e com dados em tmpfs; nenhum teste acessou o banco/volumes operacionais.

| Comando / ensaio | Resultado |
| --- | --- |
| `vendor/bin/phpunit` original | 140 testes, 1.496 asserções, passou; warning de cache PHPUnit por mount somente leitura. Execuções seguintes usam `--do-not-cache-result`. |
| Regressões contra cópia do commit original | 17 falhas reproduzidas em 21 casos; não usadas como validação de sucesso. |
| Subsuite inicial escrita/vigência/relatórios | Primeiro ensaio mostrou erro de limite de data introduzido na extração SQL; corrigido usando limite superior exclusivo no dia seguinte, preservando SQLite com valores de data armazenados com hora. |
| Subsuite regressões/escrita/vigência | 57 testes, 492 asserções, passou. |
| `vendor/bin/phpunit --do-not-cache-result`, SQLite final | 172 testes, 1.652 asserções, passou. |
| `vendor/bin/phpunit -c /tmp/phpunit-audit.xml --do-not-cache-result`, MySQL 8.4 final | 172 testes, 1.652 asserções, passou. XML temporário aponta somente ao banco `contracts_audit_test`. |
| Subsuite segurança/financeiro/snapshot após ajuste de reload financeiro | 34 testes, 283 asserções, passou. |
| Dois processos reais de criação concorrente, MySQL | Ambos aguardaram o mesmo lock de fundo; um salvou e o outro pediu confirmação; exatamente um contrato e uma auditoria criados. |
| `vendor/bin/pint --dirty` e `vendor/bin/pint --test` | Formatação aplicada e verificação completa aprovada em 138 arquivos PHP. |
| `npm ci && npm run build`, Node 22 | Passou, Vite 7.3.7. Antes, build sem binário Vite e instalação como UID sem acesso a node_modules falharam; repetição com usuário padrão do container concluiu. |
| `composer audit --locked --no-interaction` | Nenhum advisory reportado. |
| Auditoria npm durante `npm ci` | Zero vulnerabilidades reportadas. |

Templates Blade compilados com `view:cache` em storage/cache temporários: passou. `git diff --check`: passou. A suíte final inclui regressões adicionais para exclusão concorrente do registro filho e limpeza de PDF quando a auditoria falha. Dependências continuam as do lockfile; não houve nova dependência ou alteração de lockfile. Containers e rede temporários foram removidos ao concluir; o banco e os serviços da instalação permaneceram separados.

## Riscos remanescentes e decisões pendentes

1. Homologação administrativa das datas, termos, revisão e contratos históricos. Contratos/aditivos aceitam valores negativos pela normalização existente, enquanto registros financeiros nativos exigem valores não negativos: a convenção para supressões/créditos precisa ser definida por campo; não foi imposta uma proibição global que alteraria dados e regras históricas. Aditivos não aplicam automaticamente prorrogação, acréscimo, supressão, reajuste ou saldo; fórmulas, eventos e critérios oficiais dependem de regra institucional. Contratos a iniciar continuam podendo aparecer na janela de vencimentos, preservando o comportamento existente.
2. A chave de possível duplicidade permanece a já utilizada: número/fundo/exercício/processo, comparação do banco. Não foi presumida equivalência por remoção de espaços/acentos, nem exclusividade entre secretarias. Só candidatos acessíveis ativos geram confirmação; isso evita revelar contratos de outro escopo. Edições continuam com aviso posterior. Importações administrativas mantêm suas regras de identidade legada e não usam o lock de cadastro nativo.
3. Escopos e versões devem ser alterados pelos fluxos governados. SQL externo que altera permissões e depois desfaz a alteração sem qualquer versão/auditoria pode escapar a fingerprints de estado; não foram adicionados triggers ou uma revisão global de permissões nesta missão. Download revalida imediatamente antes de entregar o arquivo, mas não cancela bytes de uma transferência já iniciada.
4. Exportações grandes ainda custam CPU/disco. PDO pode bufferizar o cursor, planilhas guardam células em memória, PDFs acumulam páginas e agrupamentos crescem com o número de grupos. O hash segue linear; um mecanismo persistente de revisões exigiria cobrir também importações/SQL operacional e uma migration própria. Não foi implementado cache inseguro para esconder esse custo.
5. Worker morto sem executar `failed()` pode deixar status `processing` até expiração; a claim impede concorrência/reexecução automática de estados finais, mas não cria recuperação automática de jobs abandonados. Observabilidade operacional e política de retomada precisam de decisão. A instalação existente tinha worker reiniciando; serviços não foram reiniciados nesta auditoria.
6. Retenção, antivírus, imutabilidade de auditoria e recuperação de backups dependem de política operacional. Soft delete preserva histórico; FKs de algumas auditorias têm cascade em exclusão física, que não foi realizada nem modificada.
7. Não houve teste ofensivo externo, carga representativa em produção, comparação com dump municipal real, restauração de backup, navegação manual ou deploy. Os testes verificam autorização e integração com fixtures sintéticas; não constituem certificação completa de segurança.

## Arquivos alterados

- `app/Application/Contracts/ContractInputValidator.php`, `SaveContract.php`.
- `app/Application/Security/LockActor.php`.
- `app/Http/Controllers/AccountController.php`, `AdministrationController.php`, `SavedReportController.php`, `ReportController.php`, `ContractAmendmentController.php`, `ContractFinanceController.php`, `ContractReviewController.php`.
- `app/Jobs/GenerateReport.php`.
- `app/Models/Contract.php`, `ReportExport.php`.
- `app/Services/ExpirationCalendar.php`.
- `app/Services/Reports/ContractReport.php`, `ReportRenderer.php`, `AuditReportRenderer.php`, novo `ReportRows.php`.
- `resources/views/contracts/form.blade.php`.
- `tests/Feature/ContractWritingTest.php`, `ContractValidityParityTest.php`, novos `AuditSecurityRegressionTest.php`, `ReportExportSecurityTest.php`, `ReportSnapshotTest.php`.
- `README.md`, `docs/architecture.md`, este relatório.
