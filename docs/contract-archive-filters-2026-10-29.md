# Consulta de contratos: filtros e fornecedor

Registro histórico: posteriormente, a listagem duplicada foi retirada. O comportamento vigente está em [consulta centralizada em Relatórios](contract-listing-retirement-2026-10-29.md).

No Acervo Contratual, inclusive no painel “Contratos vigentes”, Secretaria só fica habilitada quando o Fundo selecionado é MUNICIPIO DE NIQUELÂNDIA. A identificação usa a normalização municipal existente. Trocar o Fundo limpa a Secretaria no formulário; sem Fundo municipal, o servidor ignora o filtro de Secretaria. As opções continuam limitadas aos contratos visíveis do usuário.

O seletor de Vigência foi substituído por “Todos” e “Somente Vigentes”, com consulta automática ao selecionar uma opção. `validity=vigente` usa `withReportValidity`, o mesmo critério dos relatórios, mantendo os demais filtros e a paginação. As URLs existentes com outros valores de `validity` continuam aceitas.

A coluna Fornecedor compartilha a apresentação do relatório: modalidade/número da licitação, fornecedor em destaque, último aditivo não excluído e seu processo. Sem aditivo na tabela, utiliza o resumo legado, omitindo número vazio ou zero. Sem filtro de Fundo, informa também Fundo ou Secretaria, conforme o órgão. `ContractListingQuery` projeta os campos necessários e consulta o último aditivo por subqueries, sem carregar o histórico inteiro por registro. Nenhuma mudança de persistência ou autorização.

Comandos executados:

- `docker compose exec -T app php vendor/bin/phpunit --do-not-cache-result --filter='test_archive_department_filter|test_archive_validity_radios|test_archive_supplier_summary|test_scope_limits_rows|test_current_contracts_can_be_filtered|test_report_list_displays_updated'`: 6 testes e 74 asserções aprovados.
- `docker compose exec -T app composer check:architecture`: 3 testes e 4 asserções aprovados.
- `docker compose exec -T app composer test:unit`: 81 testes e 195 asserções aprovados.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `docker compose exec -T app composer check`: Pint aprovado nos 361 arquivos; a execução completa de 852 testes foi interrompida pelo timeout de 300 segundos do Composer, com falhas já sinalizadas antes da interrupção. Não há resultado consolidado dessa execução.
- `docker compose exec -T app php vendor/bin/phpunit --do-not-cache-result --filter=ContractBrowsingTest`: 36 testes, 34 aprovados e 2 falhas em `test_contract_information_panel_shows_saved_updated_values` e `test_detail_renders_all_fields_masks_personal_documents_and_escapes_source_text`. São as mesmas falhas de detalhes já registradas em `build/report-baseline-verified.log`, fora das telas alteradas. Log atual em `build/contract-archive-browsing-2026-10-29.log`.
- `git diff --check`: aprovado.

Testes usam SQLite em memória e armazenamento temporário isolado. Sem validação visual autenticada no navegador.
