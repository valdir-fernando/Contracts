# Renomeação e importação de OS

A tabela atual foi renomeada de `contract_service_orders` para `tb_OS`, mantendo os campos Laravel, IDs, vínculos, datas, timestamps e soft deletes. `ContractServiceOrder` aponta explicitamente para `tb_OS`. Não foi executado o DDL da tabela legada sobre o banco instalado.

As migrations `2026_10_20_000002_rename_service_orders_to_tb_os.php` e `2026_10_20_000003_add_service_order_import_provenance.php` foram aplicadas individualmente com `php artisan migrate --path=database/migrations/<arquivo> --force` no MySQL local. A primeira recusa coexistência das duas tabelas; a segunda acrescenta `legacy_record_id` anulável, único e com FK. O banco tinha zero OS antes da operação. Testes isolados confirmam a preservação de OS nativas e excluídas logicamente ao renomear.

O comando `contracts:import-service-orders [arquivo.sql] [--dry-run]` usa `SqlDumpReader` para ler somente literais de `tb_OS`, sem executar SQL do dump. Vínculos exigem contrato ativo único com o mesmo número, Fundo normalizado e processo. A importação usa lock compartilhado das importações de referência, transação, locks nos contratos, ator de sistema nulo, incremento da versão e auditoria por contrato na mesma transação. Não altera valores, vigência, snapshots, prazo histórico ou aditivos dos contratos.

O ID de origem fica em `legacy_records.legacy_id`, com `source_table=tb_OS`, payload integral, hash e execução. O ID local é independente para preservar eventuais OS nativas. Número, Data OS, Início, Final e Período são mapeados para `number`, `issued_on`, `starts_on`, `ends_on` e `deadline`. Os demais campos originais permanecem no payload. Datas impossíveis e períodos vazios não são importados. Registros divergentes, vínculos ambíguos e colisões com OS nativas geram pendências; nenhuma linha é sobrescrita. Repetir a mesma origem não duplica registros, mesmo após soft delete. Fonte alterada para um ID já importado gera conflito.

## Resultado local

- Simulação inicial: 22 registros, 21 importáveis, 1 sem vínculo confirmado.
- Importação efetiva: 21 OS em `tb_OS`; os 22 registros de origem preservados em `legacy_records`.
- Simulação posterior: 0 novas importações, 21 já importadas, 1 pendente; sem duplicatas.
- Verificação de schema: `tb_OS` existe; `contract_service_orders` não existe.

A pendência é a origem `ID_OS=16`, OS `002/25`, contrato municipal `108/25`. A OS informa processo `2025018920`; o contrato local do mesmo número/Fundo (ID 4120, origem 2001) informa `2025019123`. O outro contrato com esse número pertence à Saúde e tem outro processo. Não foi escolhido um vínculo com base apenas no número nem alterado o processo para forçar a importação. A linha completa e a pendência ficam preservadas para conferência.

## Validação

`ServiceOrderImportTest` passou (4 testes, 35 asserções), cobrindo idempotência, payload, soft delete, dry-run sem persistência, rollback de auditoria, vínculos inseguros, datas inválidas e renomeação com registros existentes. As falhas iniciais de fixture foram resolvidas ao recarregar a versão padrão do contrato persistido, sem mudar a regra de incremento.

`composer check:architecture` passou (3 testes, 4 asserções), `composer test:unit` passou (77 testes, 169 asserções), Pint passou e `git diff --check` passou. Testes são executados em SQLite em memória com storage/cache isolados; somente as migrations e a importação autorizadas foram executadas no MySQL local. Sem ensaio manual autenticado em navegador. Assets não foram alterados nesta operação.

Validação conjunta final: php artisan test --filter='ServiceOrderImportTest|ContractServiceOrdersTest|ContractWritingTest' passou (51 testes, 802 asserções). Pint --dirty repetido passou (21 arquivos). A suíte completa composer check não foi repetida nesta alteração; a validação direcionada incluiu migrations, importação, painel, segurança e PDF. git diff --check passou.
