# Importação dos aditivos históricos

`contracts:import-amendments` simula a cópia dos registros de `contract_history_records` de tipo `amendment`, originários de `tb_Aditivos`, para `contract_amendments`. `--apply` persiste a execução. Não depende de um novo dump.

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
