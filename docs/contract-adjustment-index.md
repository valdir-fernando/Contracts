# Índice de reajuste do contrato

Na aba **4. Dados do Objeto**, **Indice Reajuste** aparece ao lado de **Escopo**. Os dois campos dividem a mesma célula antes ocupada somente por Escopo, preservando a posição dos demais campos. É uma seleção obrigatória no formulário, inicialmente vazia em novos contratos. Oferece IPCA (IBGE), INPC (IBGE), IGP-M (FGV), INCC (FGV), IST (Anatel) e ICTI (Ipea), conforme os índices disponibilizados pelo [Compras.gov.br Contratos](https://www.gov.br/compras/pt-br/acesso-a-informacao/manuais/comprasgovbr-contratos/manual-comprasgovbr-contratos.pdf). A escolha deve corresponder à contratação.

A migration `2026_10_28_000001_add_adjustment_index_to_contracts.php` acrescenta `contracts.indice_reajuste`, anulável para preservar registros anteriores. Foi aplicada ao ambiente local com `docker compose exec -T app php artisan migrate --force --path=database/migrations/2026_10_28_000001_add_adjustment_index_to_contracts.php`.

O adaptador `LaravelContractInputValidator` exige a seleção em `POST /contracts` e rejeita valores vazios ou fora da lista enviados pelo formulário de edição. Atualizações parciais que omitem o campo preservam o valor anterior; consumidores de compatibilidade podem manter contratos históricos sem índice. A persistência usa o fluxo existente de `SaveContract`, com autorização, locks, versão e auditoria na mesma transação. A seleção não calcula reajustes nem modifica valores ou cláusulas dos documentos.

O campo nativo fica separado de `ContractFields::MAP`, que preserva os 80 campos e o contrato de importação do legado.

O banco armazena somente a sigla (`IPCA`, `INPC`, `IGP-M`, `INCC`, `IST` ou `ICTI`). Ao consultar/reabrir o contrato, o seletor usa essa sigla para selecionar a opção e exibir a sigla acompanhada da descrição completa e da instituição. A descrição não é gravada na coluna.

Validação em Docker, com SQLite em memória e storage/cache temporários:

- `php artisan test --filter=adjustment_index --compact`: 2 testes, 93 asserções; passou, incluindo posição, ausência de seleção inicial, obrigatoriedade, opções, persistência e auditoria. Para todos os seis índices, confere a sigla diretamente no banco e a opção selecionada com descrição completa ao reabrir o contrato. Pint do arquivo de teste e `git diff --check` também passaram nesta conferência.
- `php artisan test --filter='adjustment_index|ContractNumberingTest|ContractTypeRulesTest|InspectorDefaultsTest|SupplierAutofillTest' --compact`: 34 testes, 438 asserções; passou. As fixtures de gravação receberam uma escolha explícita para atender ao novo campo obrigatório; asserções existentes preservadas.
- `composer check:architecture`: 3 testes, 4 asserções; passou.
- `composer test:unit`: 81 testes, 195 asserções; passou.
- `vendor/bin/pint` dos oito arquivos PHP alterados: passou.
- `docker compose run --rm --no-deps node npm run build`: passou.
- `git diff --check`: passou.
- `composer check`: Pint passou (361 arquivos), mas a suíte geral foi interrompida pelo timeout de 300 segundos do Composer, com falhas já sinalizadas e sem resumo final. Essa execução começou antes dos últimos ajustes de obrigatoriedade e fixtures; os testes direcionados listados acima foram executados depois desses ajustes. Não representa validação completa da suíte final.
- `php artisan test --filter=ContractWritingTest --compact`: 48 aprovados e 4 falhas de apresentação na página de detalhes (histórico, exclusão e endereço do fornecedor), já registradas na documentação de arquitetura; não houve alteração dessas asserções ou da página de detalhes.
- `php artisan test --filter='adjustment_index|ContractWritingTest::test_audit_failure_rolls_back_the_contract' --compact`: 3 testes, 66 asserções; passou.

Sem verificação visual autenticada em navegador. O endpoint local `/up` respondeu HTTP 200.
