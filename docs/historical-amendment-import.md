# Importação dos aditivos históricos

## Importação no banco local em uso em 28/10/2026

Após relato de lista vazia ao abrir Resumo pela lista de relatórios, a consulta confirmou zero registros em `tb_Aditivos` e zero históricos de aditivos no banco configurado do container app. A consulta de `ContractController::show` e o componente compartilhado estavam corretos: usam `contract_id`, paginação independente e exclusão lógica.

Foi utilizada somente a seção `tb_Aditivos` do arquivo local `database-example/u910323952_bdgestao_niq.sql`, sem executar o SQL. Um recorte privado foi comparado integralmente com os 985 registros lidos do arquivo original por `SqlDumpReader`, garantindo que nenhum produto fosse incluído. O procedimento local `build/recover-summary-amendments.php` combina os adaptadores existentes `HistoryImporter` e `ImportHistoricalAmendments`, sob lock compartilhado e transação externa única; sem `--apply`, reverte a simulação inteira.

Resultado aplicado: 985 históricos preservados, 978 aditivos importados e 577 contratos com aditivos. No Fundo Municipal de Saúde: 556 aditivos em 278 contratos. Cinco registros sem vínculo e dois com número em conflito continuam como pendências, sem associação arbitrária. Não houve sobrescrita de registros existentes ou importação de produtos.

Cada contrato afetado recebeu versões e auditorias de sistema dos importadores. O importador histórico marca `vigencia_em_revisao` conforme sua regra existente. Antes do commit, todos os atributos dos 2.145 contratos, incluindo excluídos logicamente, foram comparados por hash, permitindo diferenças apenas em versão, revisão, updated_at e updated_by. Valores, datas, tipo de contrato, origem e demais atributos foram preservados. A nova simulação confirmou zero novas importações, 985 históricos e 978 aditivos já existentes, mantendo as sete pendências.

Comandos e validação:

- `docker compose exec -T app php build/diagnose-summary-amendments.php`: confirmou zero aditivos antes e 978 após a operação; 985 históricos após.
- `docker compose exec -T app php build/recover-summary-amendments.php`: simulação inicial de 978 aditivos e nova simulação idempotente após aplicação.
- `docker compose exec -T app php build/recover-summary-amendments.php --apply`: resultado descrito acima, com preservação dos contratos conferida antes do commit.
- `docker compose exec -T app php artisan test --compact --filter=test_contract_summary_lists_imported_amendments_by_id`: 1 teste, 12 asserções, aprovado. O teste novo verifica resumo com aditivo importado, valores, data final, link de consulta, isolamento mesmo com outro contrato de mesmo número e exclusão lógica.
- `docker compose exec -T app php artisan test --compact --filter='test_list_reads_tb_aditivos_with_contract_and_soft_delete_filters|test_list_shows_amendment_validity_end|test_list_shows_each_amendment_accumulated_value'`: 3 testes, 29 asserções, aprovados.
- `docker compose exec -T app php artisan test --compact --filter='test_contract_summary_lists_imported_amendments_by_id|test_import_preserves_origin_exact_money|test_history_preserves_raw_normalizes|test_dry_run_and_truncated_history|test_list_reads_tb_aditivos_with_contract_and_soft_delete_filters'`: 5 testes, 56 asserções, aprovados.
- `docker compose exec -T app php vendor/bin/phpunit tests/Feature/HistoricalAmendmentImportTest.php tests/Feature/HistoryAndOperationsTest.php --do-not-cache-result`: 13 testes, 105 asserções, duas falhas de apresentação já existentes em `test_imported_amendment_is_visible_and_masked_but_cannot_be_edited_by_regular_users` e `test_ambiguous_history_requires_admin_review_and_never_changes_money_or_end_dates`. Ambas constam no log anterior `build/report-composer-check-final.log`. As asserções foram preservadas; log atual `build/summary-amendment-import-check.log`.
- `docker compose exec -T app vendor/bin/pint tests/Feature/ContractAmendmentsTest.php`: aprovado.
- `docker compose exec -T app composer check:architecture`: 3 testes, 4 asserções, aprovados.
- `docker compose exec -T app composer test:unit`: 81 testes, 195 asserções, aprovados.
- `git diff --check`: aprovado.

Os testes usaram SQLite em memória e storage/cache temporários. Nenhum teste, migration ou restauração de banco foi executado sobre o banco instalado. Não houve mudança de código funcional ou assets; build e suíte completa não foram repetidos. Sem conferência visual autenticada.

## Histórico da implementação

A lista de aditivos agora aparece diretamente no resumo do contrato, abaixo dos dados e objeto, com cadastro, consulta e paginação independente (`amendments_page`). A tabela compartilhada em `amendments/list.blade.php` preserva a listagem anterior e suas rotas. O resumo não abre mais o modal “Gerenciar aditivos”; os links de retorno do cadastro/detalhe do aditivo levam à lista integrada. Leituras continuam autorizadas pelo contrato e limitadas ao seu ID, excluindo registros apagados logicamente.

Validação dessa integração: testes de aditivos e navegação executados (40 aprovados, uma falha em `test_detail_renders_all_fields_masks_personal_documents_and_escapes_source`); esse teste ainda exige os detalhes completos, pendências e documentos pessoais removidos do resumo por solicitação anterior. Sua asserção foi preservada. Pint dos três PHP alterados e `git diff --check` passaram. O teste novo verifica ações, valores, término, paginação e isolamento entre contratos.

A lista também apresenta **Fim da vigência**, obtido exclusivamente de `payload.Final_Prorrog` de cada aditivo, em dia/mês/ano. Ausência aparece como “Não informado”; não usa término do contrato ou de execução do serviço como substituto. Validação: `docker compose exec -T app php artisan test --filter=ContractAmendmentsTest --compact` (10 testes, 164 asserções), Pint do teste e `git diff --check` aprovados.

A coluna `VL_Acumulado` da lista apresenta `payload.VL_Acumulado` do próprio aditivo, formatado como dinheiro pelo helper existente. Alterar o acumulado do contrato não modifica o valor exibido nessa coluna. Validação dessa apresentação: `docker compose exec -T app php artisan test --filter=ContractAmendmentsTest --compact` (9 testes, 154 asserções), Pint do teste e `git diff --check`, aprovados. Sem conferência visual autenticada.

O painel **Gerenciar aditivos** consulta a tabela local `tb_Aditivos` pelo model `ContractAmendment`. A migration `2026_10_25_000002_rename_contract_amendments_to_tb_aditivos.php` renomeia `contract_amendments`, preservando IDs, registros, exclusões lógicas e chaves estrangeiras. A estrutura Laravel e os campos históricos em `payload` permanecem; não é necessário reimportar. Se ambas as tabelas existirem, a migration recusa a operação sem sobrescrever dados.

`composer check` após a renomeação: Pint aprovado (352 arquivos); 816 testes, 8.479 asserções, uma falha e um ignorado. A única falha é a já documentada abaixo em `SelectedFundTest::test_editing_a_contract_preserves_its_existing_manager`, por proibir o rótulo existente “Gestor do Fundo”. Nenhuma asserção desse teste foi alterada. `git diff --check` aprovado. Sem conferência visual autenticada.

Aplicação local em 25/10/2026: migration executada pelo caminho específico com `--force`; consulta posterior confirmou 978 registros na `tb_Aditivos`. Validação via Docker: 26 testes de aditivos/importação/OS (322 asserções), `composer check:architecture` (3 testes), `composer test:unit` (81 testes), Pint dos cinco PHP alterados e `npm run build` passaram. Testes usam SQLite e storage temporários.

`contracts:import-amendments` simula a cópia dos registros de `contract_history_records` de tipo `amendment`, originários de `tb_Aditivos`, para `tb_Aditivos`. `--apply` persiste a execução. Não depende de um novo dump.

Antes de executar, aplique a migration `2026_10_25_000001_add_amendment_import_origin.php`. Ela acrescenta a referência única e anulável `legacy_record_id` e permite autoria de sistema (`created_by`/`updated_by` nulos). O cadastro humano continua exigindo ator autenticado. O rollback dessa migration preserva esses dados.

Somente vínculos `matched` ou `manual_confirmed` são utilizados. Vínculos ausentes, datas obrigatórias inválidas e números já reservados (inclusive excluídos) geram pendências em `import_issues`; nenhum contrato é escolhido por número isolado. A referência à origem impede nova cópia em reexecuções, inclusive após edição ou exclusão lógica do aditivo. Mudanças de versão da origem ou de vínculo são conflitos, sem sobrescrita.

Os aditivos recebem situação **Importado do legado**, sem declarar conferência administrativa. Snapshots vêm da origem, sem atualização pelos cadastros atuais. Datas e valores monetários são normalizados para o formato do sistema; campos opcionais inválidos ficam nulos na projeção e geram pendências. Todo o conteúdo original continua em `legacy_records`, e o histórico existente permanece intacto. Usuários comuns consultam os importados com o mascaramento existente; somente administradores podem editá-los.

O comando é manutenção explícita de sistema, sem atribuir a importação a usuário humano. Usa o lock de importações existente e uma transação integral, bloqueando contratos antes dos históricos e origens. Cada contrato alterado incrementa sua versão uma vez, junto com auditoria `amendments_imported`, referências de origem e execução. Falha de auditoria reverte a operação. Não modifica valores, vigência, situação de revisão, produtos ou saldos.

```powershell
docker compose exec -T app php artisan contracts:import-amendments
docker compose exec -T app php artisan contracts:import-amendments --apply
```

Testes usam SQLite em memória e storage temporário: `php vendor/bin/phpunit tests/Feature/HistoricalAmendmentImportTest.php tests/Feature/ContractAmendmentsTest.php --do-not-cache-result`.

## Execução local em 25/10/2026

Migration aplicada pelo seu caminho específico, seguida de simulação e `--apply`. Execução 23: 985 históricos, 978 importados, 577 contratos auditados, 5 sem vínculo e 2 em conflito. Os IDs legados 55, 56, 57, 58 e 146 exigem vínculo; 704 e 706 têm número 1 no mesmo contrato e datas diferentes, sem escolha automática de um deles. Todos permanecem na origem. Foram registradas 2.944 normalizações de campos opcionais, principalmente datas zeradas.

Verificação após aplicação: 978 aditivos nativos e 985 históricos preservados; o contrato 001/26, processo 2025004986, possui o aditivo 1 na nova lista. Nova simulação: zero importações, 978 ignorados por já estarem importados e as mesmas 7 pendências.

Validação executada via Docker: testes específicos de importação e cadastro (13 testes, 187 asserções), `composer check:architecture` (3 testes), `composer test:unit` (81 testes), Pint dos arquivos alterados, `git diff --check` e `npm run build`, todos aprovados. `composer check` também foi executado; a suíte identificou a falha em `SelectedFundTest::test_editing_a_contract_preserves_its_existing_manager`: a asserção proíbe o texto “Gestor do Fundo”, mas esse já é o rótulo em `ContractFields`, alterado antes desta tarefa. A execução isolada de `SelectedFundTest` reproduziu a mesma falha. Os arquivos e a asserção desse comportamento anterior foram preservados.

Resultado completo de `composer check`: 810 testes, 8.432 asserções, 1 falha descrita acima e 1 teste ignorado; Pint aprovou 351 arquivos. A suíte completa foi iniciada antes da adição do teste de números históricos duplicados; a execução específica posterior inclui esse caso e passou.
