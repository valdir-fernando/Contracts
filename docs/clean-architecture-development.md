# Evolução da Clean Architecture para desenvolvimento

## Escopo e resultado

Esta etapa parte da árvore já auditada, com **172 testes e 1.652 asserções** aprovados. As alterações anteriores foram preservadas. O objetivo foi estabelecer fronteiras verificáveis para novos desenvolvimentos, começando pelo núcleo existente de contratos, sem recriar o projeto nem modificar o banco.

`SaveContract` e `ChangeContractLifecycle` eram classes de Application que recebiam models e importavam Eloquent, facades, Validator, normalização legada e exceções HTTP. Agora dependem somente de tipos de Application/Domain e cinco interfaces específicas. É uma inversão efetiva de dependências: os casos de uso também são executados em testes com portas em memória, sem inicializar Laravel/banco.

A implementação não migra artificialmente todos os módulos. Serviços de aditivos, financeiro, revisão, administração e relatórios continuam nos caminhos atuais; têm autorização/auditoria e testes de integração, mas ainda não são casos de uso independentes do framework. A arquitetura atual e suas regras estão em [architecture.md](architecture.md), com instruções persistentes em [AGENTS.md](../AGENTS.md).

## Melhorias implementadas

| Antes / risco de manutenção | Depois / evidência |
| --- | --- |
| Use cases acoplados a models, DB, Gate, Validator e HTTP | Portas de transação, acesso, validação, catálogos e persistência; snapshots/tipos puros; testes unitários dos casos de uso. |
| Regras de CPF/CNPJ e decimal alojadas no importador legado | `BrazilianDocument` e `DecimalAmount` em Domain; `Legacy/Normalizer` delega as funções existentes. Verificadores, precisão, sinais e convenções foram preservados. |
| Regras de exercício, revisão legada e datas dependem do model | `ContractState` imutável; regras verificadas sem ORM; patches diferenciam omissão de `null` e preservam exceções históricas. |
| Classe de infraestrutura `LockActor` dentro de Application; verificações repetidas | Classe movida para Infrastructure; consumidores atualizados; aditivos, financeiro e revisão reutilizam o mesmo lock/validação de ator. |
| Mutação dependia de respeitar a transação apenas por convenção | `TransactionGuard` recusa lock, escopo de destino, duplicidade e persistência fora de transação. Store também recusa um snapshot de contrato diferente do contexto autorizado. |
| Exceções da aplicação representam transporte HTTP | Falhas puras `InputRejected`, `AccessDenied`, `ConcurrencyConflict`, `RecordNotFound`; tradução nos adaptadores/handler HTTP. JSON e formulários mantêm 403/404/409/422. |
| Risco de um mapper reaplicar casts sobre atributos já serializados e entregar `float` monetário do SQLite ao domínio | Mapper conserva atributos originais e aplica apenas diferenças; campos monetários usam as strings dos casts existentes, uniformizando tipos no domínio. Testes cobrem JSON, datas, dinheiro, campos omitidos e trilha de alterações. |
| Retorno poderia ser implementado como nova leitura global após commit | `ContractStore` retorna o snapshot salvo; serviço compatível reconstrói o model sem SQL após commit. Teste confirma que todas as leituras de contratos nessa operação permanecem na transação autorizada. |
| Fronteiras apenas documentadas | Suíte Architecture verifica imports/referências/helpers; CI executa o check; scripts Composer tornam a validação reproduzível. |

`ContractAccess` expõe `lockForSave` e `lockForLifecycle`, e escolhe internamente policies compatíveis com cada operação. O consumidor não pode fornecer uma string de ability para carregar um contrato com uma autorização inadequada à operação. `ActorIdentity` transmite somente ID/versão de sessão esperados; permissões vêm do ator atual bloqueado.

As interfaces descrevem necessidades concretas do núcleo, não um repository genérico. `ContractStore` reúne persistência e auditoria deliberadamente: não oferece uma gravação de contrato sem trilha. A transação permanece ao redor de todo o caso de uso, com até três tentativas e autorização/validação refeitas em cada tentativa. Não há estado mutável de ator/contrato guardado em singleton.

## Compatibilidade e segurança preservadas

- Assinaturas de `ContractWriter::save` e `ContractLifecycle::change`, rotas, controllers consumidores, filtros, relatórios e API permanecem compatíveis. Os tipos internos de Application foram alterados para identidade/snapshot puro; consumidores novos devem usar essas assinaturas documentadas.
- Policies e escopos existentes continuam sendo usados. Ator bloqueado precede contrato; a ordem especial de locks dos administradores não foi alterada.
- Validação contextual permanece depois dos locks; campos pessoais/derivados continuam protegidos e o patch é explicitamente filtrado pelo mapa de campos.
- Términos/valores de aditivos não são aplicados automaticamente. Histórico, propriedade legada não conciliada, datas inconsistentes não alteradas, quatro casas decimais, soft delete e auditoria permanecem preservados.
- A consulta preventiva de duplicidade conserva o lock do fundo e a leitura corrente. É confirmação administrativa, não unicidade de banco.
- Modelos Eloquent, migrations, schema e dependências instaladas não foram substituídos. Não houve nova biblioteca, commit, deploy ou restart dos serviços.

## Verificação executada

PHP 8.4.26 em containers temporários. A suíte usou storage/cache temporários e SQLite em memória; o MySQL 8.4 usou rede interna própria, sem porta publicada e dados em tmpfs. Nenhuma migration/teste foi executado no banco instalado.

| Comando / ensaio | Resultado |
| --- | --- |
| Subsuite inicial escrita/ciclo de vida/autorização | 29 testes, 240 asserções, passou. |
| Subsuite adaptadores/aditivos/financeiro/revisão/conflitos | 41 testes, 460 asserções, passou. |
| `composer check:architecture` | 3 testes, 4 asserções, passou sem inicialização Laravel/banco. |
| `composer test:unit` | 51 testes, 97 asserções, passou sem inicialização Laravel/banco. |
| `composer check` | Pint aprovado em **165 arquivos PHP**; suíte SQLite aprovada com **226 testes, 1.775 asserções**. |
| `vendor/bin/phpunit -c /tmp/phpunit-architecture.xml --do-not-cache-result` | Suíte MySQL 8.4 aprovada com **226 testes, 1.775 asserções**. |
| `npm run build` | Passou, Vite 7.3.7. |
| `view:cache` em storage/cache temporários | Passou. |
| `composer validate --strict --no-check-publish` | Passou, composer.json válido e lockfile compatível. |
| `git diff --check` | Passou. |
| Dois processos reais de criação concorrente em MySQL | Um contrato e uma auditoria criados; segunda requisição exige confirmação de duplicidade. |

A conferência do SQLite confirmou que o PDO devolve um decimal de teste como `float`; o mapper passou a fornecer o valor textual do cast ao domínio, mantendo o original bruto para a auditoria. Não houve operação aritmética em float nem alteração dos valores persistidos.

Durante a construção do teste de retorno, a primeira asserção contava uma leitura de contrato, mas a policy preservada também executa uma consulta de visibilidade. O SQL foi investigado; a asserção passou a verificar o requisito correto: **nenhuma leitura de contrato fora da transação autorizada**, mantendo a consulta necessária da policy. A primeira suíte SQLite de 224 testes registrou essa falha de asserção; a suíte final acima passou após a correção. Não foram removidos testes nem consultas de autorização.

Os containers e a rede de validação foram removidos ao concluir, mantendo os serviços da instalação separados. A CI foi atualizada, mas sua execução remota não foi acionada. Não houve navegação manual, teste ofensivo externo, restauração de backup ou carga de produção. A fiscalização arquitetural é estática e não resolve dependências introduzidas por strings/reflection dinâmicas. Os demais módulos e riscos operacionais seguem indicados no guia de arquitetura e nas auditorias anteriores.

## Próximas migrações de arquitetura

1. Aplicar o mesmo padrão à conciliação de histórico e à governança de usuários, caracterizando primeiro último administrador, versões e rollback.
2. Separar validação/orquestração de aditivos e financeiro por operação, preservando locks de ator/contrato/filho e auditoria conjunta.
3. Definir interfaces de armazenamento/geração quando houver novos consumidores ou backends, mantendo arquivos privados e revalidação de acesso.

Esses itens não impedem desenvolver com as fronteiras já verificadas. Novos módulos devem seguir o núcleo puro desde o início; módulos existentes devem migrar quando uma alteração real justificar seu custo.

## Arquivos desta etapa

A árvore também mantém as mudanças da auditoria anterior. Esta relação considera somente a evolução arquitetural e seus testes/documentação:

- `.github/workflows/ci.yml`.
- `AGENTS.md`.
- `README.md`.
- `app/Application/Contracts/AuthorizedContract.php`.
- `app/Application/Contracts/ChangeContractLifecycle.php`.
- `app/Application/Contracts/ContractInputValidator.php — movido para Infrastructure/Ports`.
- `app/Application/Contracts/Ports/ContractAccess.php`.
- `app/Application/Contracts/Ports/ContractCatalog.php`.
- `app/Application/Contracts/Ports/ContractInputValidator.php`.
- `app/Application/Contracts/Ports/ContractStore.php`.
- `app/Application/Contracts/SaveContract.php`.
- `app/Application/Contracts/ValidatedContractInput.php`.
- `app/Application/Exceptions/AccessDenied.php`.
- `app/Application/Exceptions/ConcurrencyConflict.php`.
- `app/Application/Exceptions/InputRejected.php`.
- `app/Application/Exceptions/RecordNotFound.php`.
- `app/Application/Ports/TransactionManager.php`.
- `app/Application/Security/ActorIdentity.php`.
- `app/Application/Security/LockActor.php — movido para Infrastructure/Ports`.
- `app/Domain/Contracts/ContractState.php`.
- `app/Domain/Shared/BrazilianDocument.php`.
- `app/Domain/Shared/DecimalAmount.php`.
- `app/Http/Controllers/AccountController.php`.
- `app/Http/Controllers/AdministrationController.php`.
- `app/Http/Controllers/CatalogController.php`.
- `app/Http/Controllers/ContractAmendmentController.php`.
- `app/Http/Controllers/ContractFinanceController.php`.
- `app/Http/Controllers/ContractReviewController.php`.
- `app/Http/Controllers/ReportController.php`.
- `app/Http/Controllers/SavedReportController.php`.
- `app/Http/Controllers/UserController.php`.
- `app/Infrastructure/Contracts/ContractMapper.php`.
- `app/Infrastructure/Contracts/EloquentContractAccess.php`.
- `app/Infrastructure/Contracts/EloquentContractCatalog.php`.
- `app/Infrastructure/Contracts/EloquentContractStore.php`.
- `app/Infrastructure/Contracts/LaravelContractInputValidator.php`.
- `app/Infrastructure/Http/ApplicationErrors.php`.
- `app/Infrastructure/Persistence/LaravelTransactionManager.php`.
- `app/Infrastructure/Persistence/TransactionGuard.php`.
- `app/Infrastructure/Security/LockActor.php`.
- `app/Providers/AppServiceProvider.php`.
- `app/Services/AmendmentWriter.php`.
- `app/Services/ContractLifecycle.php`.
- `app/Services/ContractReview.php`.
- `app/Services/ContractWriter.php`.
- `app/Services/FinancialRecordWriter.php`.
- `app/Services/Legacy/Normalizer.php`.
- `app/Services/Reports/AuditReportRenderer.php`.
- `app/Services/Reports/ReportRenderer.php`.
- `bootstrap/app.php`.
- `composer.json`.
- `docs/architecture.md`.
- `docs/clean-architecture-development.md`.
- `docs/technical-audit-2026-10-12.md`.
- `phpunit.xml`.
- `tests/Architecture/DependencyBoundaryTest.php`.
- `tests/Feature/ContractArchitectureIntegrationTest.php`.
- `tests/Unit/ContractStateTest.php`.
- `tests/Unit/ContractUseCasesTest.php`.
- `tests/Unit/NormalizationTest.php`.
