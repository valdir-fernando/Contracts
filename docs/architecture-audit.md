# Auditoria arquitetural — Contracts

## Escopo e plano de execução

Base examinada: `ee40009`, branch `main`; árvore de trabalho limpa ao início. Inspeção do código, rotas, testes, importadores, relatórios, configurações, migrations, Docker e scripts operacionais. Nenhum commit ou push será executado nesta missão.

Baseline executado em SQLite `:memory:`: 99 testes, 1.271 asserções, todos aprovados. Nenhuma migration no MySQL da instalação e nenhum volume removido.

Plano priorizado:

1. **Segurança:** reproduzir a aceitação de ator com versão de sessão antiga em `ContractWriter`/`ContractLifecycle` e a falta de reautorização no formulário retornado após conflito de edição. Corrigir com testes de regressão. Proteger `storage/app` no `.gitignore`, após conferir arquivos rastreados e histórico desse caminho.
2. **Domínio:** extrair a classificação temporal da vigência para regra PHP pura com referência explícita; manter a tradução SQL no Eloquent e conferir sua equivalência com a regra.
3. **Aplicação:** separar a validação contextual da escrita e explicitar os casos de uso de salvar/excluir/restaurar, preservando os serviços atuais como adaptadores de compatibilidade para controllers, comandos e consumidores existentes.
4. **Leitura:** retirar montagem de consultas do controller e compartilhar os filtros de identificação entre listagem e relatórios, preservando escopos e paginação.
5. **Verificação/documentação:** executar testes pertinentes após cada etapa e a suíte completa ao final; registrar riscos pendentes, concessões arquiteturais e próximos passos em `architecture.md` e neste relatório.

Mudanças de schema, módulos de atas/saldos, contratos públicos da API e migrações em massa ficam fora desta primeira etapa.

## Diagnóstico da arquitetura encontrada

O código de base é um monólito Laravel com serviços de escrita, policies, escopos Eloquent, importadores especializados e renderizadores de relatórios. Há separação útil entre HTTP e transações de contratos/aditivos/financeiro; não havia uma camada de domínio independente nem suites de regras puras. A estrutura não apresentava repositories genéricos, interfaces artificiais ou uma abstração de ORM paralela.

A avaliação dos cinco arquivos prioritários é baseada no snapshot `ee40009`:

| Arquivo e referência original | Avaliação |
| --- | --- |
| `app/Services/ContractWriter.php`, `save`, linhas 20–166 | A transação, locks de ator/contrato, policy, versão e auditoria são escolhas corretas. O método também reúne construção de regras, requisitos por tipo, normalização, escopo de destino, snapshots, vínculo de fornecedor, exercício e cronologia: há razões concretas e distintas para mudar a mesma classe. |
| `app/Services/ContractLifecycle.php`, `change`, linhas 18–41 | Fluxo curto e coeso, com confirmação, motivo, versão, autorização, soft delete e auditoria atômica. Não justificava criar um repository ou duplicar toda a implementação em duas classes. Precisava revalidar a versão de sessão do ator. |
| `app/Http/Controllers/ContractController.php`, `index` e `update` | `index` montava catálogo de filtros, busca, queries, ordenação e projeção. `update` delegava corretamente a escrita, mas o catch de conflito renderizava o estado atualizado sem uma nova autorização. |
| `app/Models/Contract.php`, `scopeVisibleTo`, `getValidityAttribute`, `scopeWithValidity`, `displayField` | Casts, soft delete e query scopes são integração natural do Eloquent. A classificação temporal dependia de `today()` e estava repetida na geração de relatórios; formatação e máscaras também estão no model, mas não justificam uma reescrita imediata. |
| `app/Policies/ContractPolicy.php`, `view/create/delete/restore/update` | Policies delegam visibilidade ao escopo central, bloqueiam usuários inativos e limitam exclusão/restauração a administradores. Não foi encontrada autorização horizontal permissiva no caminho HTTP comum. Manter dependência de Eloquent aqui é adequado para um adaptador Laravel. |

### Responsabilidades, SOLID e dependências

- **SRP:** violações concretas no writer original, no controller de usuários e nos renderizadores que combinam consultas/estado de exportação/formatos de arquivo. A primeira foi reduzida nesta etapa. Os outros casos permanecem priorizados abaixo.
- **DIP:** os serviços dependem diretamente de Eloquent, facades e, em alguns pontos, `app()`. Não há inversão completa de infraestrutura. A regra temporal agora tem dependência apenas de tipos PHP; os casos de uso conservam integração Laravel de maneira explícita. Renomear diretórios não seria suficiente para afirmar Clean Architecture completa.
- **OCP:** mapas de campos e listas de tipos centralizam o contrato legado de 80 campos. Não foram tratados como um problema apenas por conterem condicionais; não há necessidade demonstrada de uma hierarquia de estratégias para cada campo.
- **LSP/ISP:** não foi identificada violação concreta que justificasse mudanças nesta etapa. Não havia interfaces grandes nem subclasses de domínio substituídas de forma incompatível.
- **Testabilidade:** a suite anterior tinha bons testes integrados, mas nenhuma suite de domínio independente. A regra de vigência é agora exercitada sem banco e sem boot da aplicação.
- **Complexidade acidental evitada:** sem camada genérica de repositories, interface por classe, entidade duplicada do Eloquent, DTO por formulário ou event bus para uma auditoria que precisa ser transacional.

## Achados priorizados e estado

As referências abaixo usam métodos/linhas do snapshot original quando o arquivo foi extraído; os caminhos novos são os da árvore atual.

| ID | Gravidade e impacto | Evidência | Resultado |
| --- | --- | --- | --- |
| S01 | Alta: mutação com snapshot de sessão revogado na janela entre middleware e transação | `ContractWriter::save`/`ContractLifecycle::change` recarregavam o usuário, mas não comparavam `session_version` com a versão do chamador. Quatro casos reproduzidos: cadastro, edição, exclusão e restauração. | Corrigido com `Application/Security/LockActor.php:10`, chamado dentro da transação. |
| S02 | Alta: exposição do contrato após perda concorrente de escopo | Catch de `ContractController::update` chamava `form($contract->fresh(), ...)` sem repetir Gate. O teste alterou o fundo durante o conflito e recebeu 409 com o estado restrito em vez de 403. | Corrigido em `ContractController.php:109`: ator e contrato atuais, versão de sessão e policy antes do formulário. Também cobre exclusão concorrente com 404. |
| S03 | Alta: operação administrativa ainda aceita após revogação ocorrida depois do middleware | `UserController::store`, `UserController::mutate` e `CatalogController::store`: três testes de corrida injetaram revogação após o middleware e observaram persistência. | Corrigido com ator bloqueado/revalidado e autorização administrativa atual. Ordem dos locks de administradores preservada. |
| S04 | Alta operacional: teste de limpeza pode alcançar arquivos reais | `HistoryAndOperationsTest::test_queued_export_is_private_revoked_on_scope_change_and_pruned_after_expiry` chama `PruneReportExports`; o comando também percorre `storage/app/private/report-*`, `detail-*` e `audit-*`. O XML isolava o banco, mas não o filesystem. | Corrigido em `tests/bootstrap.php:5` e `phpunit.xml`. Storage, logs, views e caches são exclusivos do processo de teste. |
| S05 | Alta preventiva: dados privados podem ser adicionados ao Git/contexto de build | `.gitignore` não cobria `storage/app/private`, embora `SeedDemo.php:25` grave credenciais ali. `.dockerignore` excluía storage, mas não configuração compilada em `bootstrap/cache`. | Ignorados storage operacional, SQLite, arquivos de backup identificados e XML operacional; cache compilado excluído do build. Nenhum dado real removido ou histórico reescrito. |
| A01 | Média: duplicação pode divergir situação exibida e filtro | Accessor de vigência no model, SQL em `scopeWithValidity`, SQL e classificação de linha no `ContractReport`. | Regra PHP pura para classificação; relatórios usam `validityOn` e `withValidity` com referência explícita. Tradução SQL mantida no Eloquent e coberta por equivalência. |
| A02 | Média: excesso de responsabilidades na escrita | Método `ContractWriter::save`, regras estruturais e contextuais misturadas com persistência/auditoria. | `SaveContract` e `ContractInputValidator` separados, com adaptador compatível; não foi retirada a validação da transação. |
| A03 | Média: duplicação das consultas de identificação e controller de listagem espesso | Busca por número/processo/fornecedor/documento e filtros de fundo/secretaria repetidos em controller e relatório. | `ContractListingQuery` e `ContractSearch::applyIdentityFilters` compartilhados. Escolhas de filtros mantêm o escopo. |
| O01 | Alta operacional: debug habilitado em acesso público temporário | Instância local em execução tinha `APP_DEBUG=true` e um Quick Tunnel ativo; produção já especifica debug falso. | `.env` local ajustado para `APP_DEBUG=false`, com `config:clear`. Esse arquivo permanece fora do Git. |

### Proteção de arquivos sensíveis

Antes de alterar os ignores, foram examinados `git ls-files storage`, arquivos rastreados com nomes de configuração/credenciais/chaves/dumps e objetos do histórico nos caminhos `.env`, `.env.production`, `storage/app`, `database-example`, `auth.json`, `*.pem` e `*.key`. Não foram identificados arquivos de dados privados nesses caminhos. Em storage, o Git rastreava apenas arquivos `.gitignore` de diretórios operacionais.

A inspeção adicional de marcadores de chave privada e chave AWS no conteúdo rastreado também não produziu achados. Isso não equivale a uma certificação de inexistência de segredos em todo o histórico; não foi executado um scanner dedicado de alta entropia. Credenciais sintéticas em testes/CI e placeholders de exemplos foram distinguidos de dados reais. Nenhum `git rm`, exclusão de dados ou reescrita do histórico foi utilizado.

## Auditoria de segurança por superfície

| Superfície | Verificação no código e situação |
| --- | --- |
| Autenticação/sessão | `LoginController::store` normaliza usuário, usa Auth/hash, rate limit e regeneração de sessão. Logout invalida sessão/CSRF. `EnsureActiveUser` verifica atividade e versão. Reset administrativo e troca da própria senha invalidam sessões/remember token. As janelas transacionais corrigidas estão em S01/S03. |
| Perfil/órgão e acesso horizontal | `Contract::scopeVisibleTo` fecha o acesso em usuário inativo, propriedade não resolvida e secretaria desconhecida. Policies e consultas de fornecedores/API/relatórios passam pelo escopo. IDs de aditivos, registros financeiros e anexos são buscados com `contract_id`, evitando acesso a filhos de outro contrato. S02 protege também a resposta de conflito. |
| Upload/download | `ContractAttachmentController::store` valida extensão, MIME por conteúdo e tamanho; UUID e armazenamento fora de `public`. Download aplica escopo, SHA-256, `attachment`, `no-store` e `nosniff`; alteração/remoção mantém metadados e auditoria. Há compensação de arquivo se a transação falhar. Não há antivírus ou inspeção semântica completa de PDFs. |
| Dados pessoais | `Contract::displayField`, apresentação de aditivos/financeiro e relatórios mascaram documentos pessoais para não administradores. API proíbe `include_personal`; exportação completa exige administrador e motivo. Raw payload do legado permanece privado/admin. Campos de texto e documentos podem conter informações pessoais adicionais: a lista de máscaras não substitui classificação e política de retenção. |
| CSRF | Rotas mutáveis estão no middleware web padrão e formulários Blade usam `@csrf`; não há exclusão customizada de rotas no bootstrap. Testes Feature do Laravel desativam CSRF por padrão, portanto seus sucessos não provam isoladamente resistência a CSRF. O fluxo de navegador exercita formulários reais. |
| Mass assignment | Models de contrato têm `guarded = ['id']`, mas os caminhos HTTP de escrita atribuem somente dados validados/mapeados ou arrays explicitamente construídos. Não foi encontrado `fill($request->all())` para models persistidos. Continua necessário evitar uso futuro de `create/fill` com payload bruto. |
| SQL injection | Sort, direção, coluna do período e colunas de relatório têm whitelist. Valores de busca/filtros usam bindings. Expressões `COALESCE` e outras expressões raw são fixas, sem concatenar entrada HTTP. O leitor de dump interpreta literais e não executa o SQL importado. |
| Concorrência/auditoria | Escritas de contrato/aditivo/financeiro usam locks, versões e auditoria transacional; há constraints únicas no banco. Último administrador usa locks ordenados. Os testes de corrida são determinísticos, não um teste de carga com múltiplos processos. Auditorias não têm UI de edição/exclusão, mas não são armazenamento inviolável contra um operador de banco. |
| Logs/erros | Job e importação sanitizam exceções para não publicar bindings/dados de origem. Produção tem debug falso e páginas genéricas. Falhas SQL não tratadas em outras rotas ainda podem incluir bindings nos logs: redaction estruturada é uma pendência, sem descartar os erros. |
| Dependências | `composer audit --no-interaction --format=json`: sem advisories ou pacotes abandonados. `npm audit --json`, incluindo dependências de build: zero vulnerabilidades reportadas. Resultados refletem os feeds disponíveis na execução, não prova de ausência de falhas. |
| Docker/produção | Banco local não publica porta; web escuta loopback; storage privado não é servido pelo Nginx. `.dockerignore` exclui `.env`, dumps, chaves, dados e agora cache compilado. Produção força HTTPS e cookies secure/httpOnly/sameSite. Configuração de produção foi validada sintaticamente. Tags/base de imagem mutáveis e uso da imagem local `contracts-app:latest` exigem melhoria de reprodutibilidade. |
| Importação | `ContractImporter`/`HistoryImporter` preservam versão da origem e conflitos, não sobrescrevem contratos locais e não aplicam aditivos automaticamente. `SyncLegacy` envolve as duas importações em transação e confere hash antes/depois. Locks de cache são de 1.800 s; importações muito longas precisam de estratégia de lease/coordenação melhor. |

As recomendações de autorização por policy seguem a [documentação do Laravel](https://laravel.com/framework/docs/13.x/authorization). A avaliação de uploads usa como referência as camadas de validação e armazenamento privado descritas no [guia da OWASP](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html). A evidência dos achados acima é o código/teste local, não a documentação do projeto.

## Pendências e riscos que permanecem

| Prioridade | Arquivo/referência atual | Impacto e próxima etapa |
| --- | --- | --- |
| P1 | `Jobs/GenerateReport.php:26`, `:37`, `:51` | Não há claim atômico/idempotente do estado pending; duas execuções do mesmo export podem gerar arquivos/eventos duplicados. Estado ready e evento também não são gravados na mesma transação. Adicionar caracterização de duplicidade/falhas antes de definir claim e recuperação. |
| P1 | `ReportController::detail/download`, `AdministrationController::export/reconcile`, `AccountController::update`, `SavedReportController::destroy` | Revalidação transacional de sessão/escopo não é uniforme. Alguns fluxos já revalidam perfil/hash, outros só a autorização inicial. Investigar as janelas específicas e estender o padrão com testes, inclusive autorizações imediatamente antes da entrega de arquivos. |
| P1 | `UserController::mutate`, `AdministrationController::reconcile` | Ainda há casos de uso, snapshots e auditoria em controllers. Extrair governança de usuários e conciliação com preservação dos locks, do último administrador e da revisão obrigatória. |
| P1 | `ContractImporter::import`, `HistoryImporter::import`, `ReferenceUsersSeeder::run` | Commands isolados não têm a mesma conferência final de hash do SyncLegacy; o seeder de usuários usa parser próprio e aceita senhas legadas. Usar snapshot imutável, caracterizar o parser e definir procedimento de troca de senhas sem reset automático. |
| P1 | Exceções de persistência e política de logs | Planejar redaction de bindings/PII com código de erro e diagnóstico útil; não simplesmente silenciar QueryException ou deixar de registrar falhas. |
| P2 | `Models/ReportExport.php:16`, `Services/OverviewMetrics::for`, `Services/Reports/ContractReport.php:85` | Hash carrega todos os contratos visíveis e o painel recalcula agregações por cursor; custo linear em várias requisições. Medir e otimizar sem transformar valores decimais em float ou cachear permissões antigas. |
| P2 | `Application/Contracts/SaveContract.php:44`, `Models/Contract.php:40`, `Services/Legacy/ContractImporter::resolveUserScopes` | Regras de escopo/vínculo têm representações por IDs e labels; normalização nativa ainda depende de `Legacy/Normalizer`. Evoluir componentes compartilhados e caracterizar dados incompletos antes de unificar. |
| P2 | `Services/Reports/CsvCell.php:9` | A whitelist numérica reconhece ponto decimal, mas não vírgula; valores brasileiros negativos podem receber o prefixo conservador contra fórmulas. Caracterizar a exportação desses valores antes de ajustar a whitelist sem permitir fórmulas. |
| P2 | `ContractController::form/show/savedResponse`, Models `displayField/moneyLabel`, Livewire `Overview::render` | Restam queries de leitura, formatação e localização junto ao model/controller e chamadas via service locator. Movimentar somente quando houver ganho concreto, com DI em pontos que exijam substituição em testes. |
| P2 | `docker/production.Dockerfile`, `compose.production.yaml`, `docker/php/entrypoint.sh` | Base local, tags não fixadas, chown recursivo e ausência de limites/healthchecks específicos no compose de produção. Preparar build reprodutível e validação operacional sem executar deploy nesta missão. |
| P2 | Migrations `add_user_governance`, modelos de auditoria | Soft delete preserva histórico, mas o FK de `user_audits.user_id` tem cascade em exclusão física. Modelo de retenção/imutabilidade e mudanças de FK dependem de aprovação e migration dedicada. |

**Limites da verificação:** não houve teste de carga/concorrência real com vários workers, varredura ofensiva externa, antivírus, teste de restauração de backup real ou build/deploy completo das imagens de produção. Scripts de backup/restauração foram inspecionados, não executados. A verificação de MySQL usa dados sintéticos, não avalia o conteúdo dos contratos municipais existentes.

**Nota sobre a baseline:** a primeira execução utilizou o isolamento de banco já existente; a ausência de isolamento de storage foi identificada depois na inspeção do teste de prune. Não há evidência registrada de exclusão de arquivo real, mas a possibilidade não foi ignorada. As verificações finais foram realizadas com isolamento de storage e caches, e o ensaio MySQL sempre usou mounts próprios.

## Refatorações efetivamente realizadas

- Caso de uso `SaveContract` extraído e validação contextual separada, sem mudar sua posição dentro da transação ou o mapa de 80 campos.
- Ciclo de vida com métodos explícitos de exclusão/restauração e serviços antigos mantidos como adaptadores.
- Classificação temporal e cronologia em componente PHP puro; consultas e linhas de relatório reutilizam a regra/tradução existente.
- Query de listagem retirada do controller e filtros de identificação compartilhados com relatórios.
- Revalidação de sessão/ator em transações de contratos, usuários e catálogos; reautorização da resposta de conflito.
- Proteção de arquivos operacionais no Git/contexto Docker e isolamento por processo dos arquivos/caches de testes.
- Suite Unit e testes de caracterização, paridade, corrida de autorização, restauração atômica e isolamento de ambiente.

Não foram adicionadas dependências, migrations ou módulos. A aparência, rotas, mensagens ordinárias, documentos, auditoria, escopos, situação administrativa, valores e termos originais/atualizados foram preservados. As diferenças de resposta limitam-se aos casos inseguros/concorrentes reproduzidos, agora 403/404.

## Verificações executadas

| Verificação | Resultado |
| --- | --- |
| Baseline original, SQLite | **99 testes, 1.271 asserções**, aprovados. |
| Regressões de segurança antes da correção | **5 falhas reproduzidas** em contratos/conflito e **3 falhas reproduzidas** em usuários/catálogos. Falhas registradas, não ocultadas. |
| Caracterização de vigência antes de integrar a extração | **14 testes, 160 asserções**, aprovados contra model/relatórios existentes. |
| Etapa de segurança de contratos | **22 testes, 205 asserções**, aprovados. |
| Extração de domínio/aplicação | **58 testes, 493 asserções**, aprovados após preservar também o default falso de revisão em models não persistidos. |
| Consultas/listagem/relatórios/calendário | **68 testes, 716 asserções**, aprovados. |
| Segurança administrativa | **28 testes, 219 asserções**, aprovados. |
| Isolamento de storage/anexos/prune | **22 testes, 152 asserções**, aprovados. |
| Segurança final/conflictos/rollback/isolamento | **12 testes, 45 asserções**, aprovados. |
| Domínio sem boot Laravel/banco | **14 testes, 18 asserções**, aprovados usando PHPUnit diretamente. |
| Suite final SQLite com storage/cache isolados | **140 testes, 1.496 asserções**, aprovados. |
| Suite final MySQL 8.4 descartável com storage/cache isolados | **140 testes, 1.496 asserções**, aprovados. |
| Navegador em prévia SQLite privada | Fluxo completo aprovado: login, Livewire, fornecedores, contratos, exclusão/restauração, documentos privados, aditivos, financeiro, revisão, calendário, relatórios, API e CSV/XLSX/PDF. |
| Pint | **134 arquivos PHP**, aprovados. |
| Composer/npm audit | Sem advisories Composer e zero vulnerabilidades npm reportadas. |
| Compose de produção | Configuração validada com `--no-env-resolution --quiet`, sem subir produção. |
| Assets/Blade/diff | Build Vite, compilação Blade e `git diff --check` aprovados. |

Os logs dos ensaios estão em `/tmp/contracts-architecture-*.log` nesta máquina, fora do Git. A configuração MySQL temporária e os acessos sintéticos de navegador também permanecem fora do repositório. Os containers/rede descartáveis usados na auditoria são removidos ao concluir; os serviços da instalação e seu túnel permanecem separados.

## Arquivos alterados/criados

| Grupo | Arquivos |
| --- | --- |
| Proteção operacional | `.gitignore`, `.dockerignore` |
| Domínio | `app/Domain/Contracts/ContractValidity.php` |
| Aplicação | `app/Application/Contracts/SaveContract.php`, `ContractInputValidator.php`, `ChangeContractLifecycle.php`; `app/Application/Security/LockActor.php` |
| Leitura e compatibilidade | `app/Infrastructure/Contracts/ContractListingQuery.php`; `app/Services/ContractWriter.php`, `ContractLifecycle.php`, `ContractSearch.php`, `ContractReview.php`, `Reports/ContractReport.php` |
| Integração Laravel | `app/Models/Contract.php`; `app/Http/Controllers/ContractController.php`, `UserController.php`, `CatalogController.php` |
| Testes | `phpunit.xml`, `tests/bootstrap.php`, `tests/Unit/ContractValidityTest.php`; `tests/Feature/ContractValidityParityTest.php`, `ContractMutationSecurityTest.php`, `AdministrativeMutationSecurityTest.php`, `TestEnvironmentIsolationTest.php` |
| Documentação | `docs/architecture.md`, `docs/architecture-audit.md` |

A mudança de `APP_DEBUG` no `.env` é operacional/local e não será versionada. O Git permaneceu no commit de base durante a missão: não houve commit ou push.

## Continuidade administrativa

Contratos/processos, órgãos/fundos/secretarias, fornecedores, dotações/empenhos, aditivos, termos de vigência, classificação administrativa, histórico, documentos, auditoria e relatórios continuam nos mesmos fluxos. Não foi presumida possibilidade jurídica de prorrogação nem aplicada alteração de prazo/valor a partir de aditivo automaticamente.

Atas de Registro de Preços e controle de saldos/quantitativos são lacunas funcionais para modelagem futura, descritas em [architecture.md](architecture.md). Dependem de unidade, item, origem documental, utilização por órgão e trilha de eventos, não apenas de subtrair as somas existentes. Nenhum desses módulos foi criado nesta tarefa.
