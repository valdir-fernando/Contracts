# Filtros combinados em Relatórios de contratos

Em 28/10/2026, foi corrigido o descarte dos filtros de Tipo do contrato (Objeto), Processo, Licitação, Nº da licitação, Fornecedor e Somente Vigentes quando Final estava ativo. O controller limpava esses valores e os removia da query; isso impedia a redução dos resultados e desativava os indicadores visuais.

Agora os filtros são aplicados em conjunto. Selecionar Tipo do contrato mantém o intervalo Final; limpar um desses filtros preserva o outro. Paginação, título e PDF do painel mantêm a combinação. Final continua ignorando o exercício, com Ano exibindo Todos os anos e desabilitado. Fundo, Secretaria, permissões e validação das datas permanecem aplicados.

Exemplo conferido por leitura no banco local: FUNDO MUNICIPAL DE SAÚDE possui 362 contratos ativos; o intervalo Final de 28/10/2026 a 31/12/2028 seleciona 290; combinar esse intervalo com Credenciamento Saúde seleciona 253. Esses totais refletem os dados da conferência, sem serem constantes da aplicação.

Os testes de integração usam SQLite em memória e storage/cache temporários. A antiga asserção de limpeza foi substituída por verificação de interseção, com contratos que diferem em cada critério. Também são verificados indicadores ativos, seleção do tipo, navegação entre páginas, PDF completo, limpeza independente, vigência e exclusão de outros Fundos. O teste de Secretaria passou a esperar zero resultados para processo inexistente combinado com Final, preservando a restrição de Secretaria.

Validação executada:

- `docker compose exec -T app php artisan test --compact --filter='test_final_filter_combines_other_filters|test_report_final_and_type_filters|test_report_scope_selectors'`: 3 testes e 74 asserções aprovados.
- `docker compose exec -T app php artisan test --compact --filter='ContractBrowsingTest::test_.*report|ContractBrowsingTest::test_final_filter|ContractBrowsingTest::test_panel_pdf|ContractBrowsingTest::test_list_filters'`: 19 testes e 286 asserções aprovados, incluindo a verificação adicional de vigência.
- `docker compose exec -T app composer check:architecture`: 3 testes e 4 asserções aprovados.
- `docker compose exec -T app composer test:unit`: 81 testes e 195 asserções aprovados.
- `docker compose exec -T app vendor/bin/pint app/Http/Controllers/ReportController.php tests/Feature/ContractBrowsingTest.php`: aprovado.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `docker compose exec -T app composer check`: Pint aprovado nos 357 arquivos; PHPUnit interrompido pelo limite de 300 segundos do Composer, após registrar progresso de 756/837 e falhas. Sem resultado final da suíte completa. Log local: `build/report-combined-filters-check.log`.
- `docker compose exec -T app php artisan test --compact --filter=ContractBrowsingTest`: 29 aprovados e 2 falhas de apresentação dos detalhes, em `test_contract_information_panel_shows_saved_updated_values` e `test_detail_renders_all_fields_masks_personal_documents_and_escapes_source_text`. Ambos já constam no log anterior `build/report-composer-check-final.log` e na validação de 27/10 registrada em architecture.md; suas asserções não foram alteradas. Log atual: `build/report-combined-filters-browsing.log`.
- `git diff --check`: aprovado.
- Consulta HTTP local de `/login`: 200.

Sem alteração de dados instalados, migrations ou contratos HTTP. Sem conferência visual autenticada no navegador.

## Pessoa do fornecedor: PF e PJ

No filtro Fornecedor, abaixo de Licitação, Nº Licit e Fornecedor, selecione **PF — Pessoa física** ou **PJ — Pessoa jurídica** e pressione Aplicar. Os dois controles são radios do mesmo grupo: selecionar um desmarca o outro. Inicialmente nenhum é selecionado; nesse estado, a pessoa não restringe os resultados. Limpar remove os critérios de Fornecedor e desmarca PF/PJ, mantendo os demais filtros, como Final e Tipo do contrato.

O parâmetro opcional `report_person` aceita somente `PF`, `PJ` ou vazio. Arrays e valores desconhecidos recebem erro de validação, inclusive na rota do PDF. A seleção compõe o indicador verde de Fornecedor e o título, preservada na paginação e no PDF completo. A classificação usa o documento normalizado salvo no contrato (`supplier_document_key`): CPF com 11 caracteres identifica PF, CNPJ com 14 identifica PJ. Não depende de nome, tipo de contrato ou cadastro atual do fornecedor. Documentos ausentes ou com outro comprimento permanecem nos resultados sem seleção e ficam fora das duas classificações quando há seleção. A classificação não revalida dígitos verificadores nem altera dados históricos.

Validação executada para esta ampliação:

- `docker compose exec -T app php artisan test --compact --filter=test_report_person_filter`: 2 testes, 50 asserções, aprovados; cobrem PF/PJ, exclusividade dos radios, dados sem classificação, parâmetros inválidos, demais critérios, paginação, PDF, CPF não exposto e escopos de usuário comum.
- `docker compose exec -T app php artisan test --compact --filter='ContractBrowsingTest::test_.*report|ContractBrowsingTest::test_final_filter|ContractBrowsingTest::test_panel_pdf|ContractBrowsingTest::test_list_filters'`: 21 testes, 336 asserções, aprovados.
- `docker compose exec -T app composer check:architecture`: 3 testes, 4 asserções, aprovados.
- `docker compose exec -T app composer test:unit`: 81 testes, 195 asserções, aprovados.
- `docker compose exec -T app vendor/bin/pint app/Http/Controllers/ReportController.php tests/Feature/ContractBrowsingTest.php`: aprovado.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `git diff --check`: aprovado.

Não foi repetida a suíte completa `composer check`, interrompida por timeout na etapa anterior, com falhas anteriores de apresentação registradas acima. Sem validação visual autenticada no navegador ou alteração de dados instalados.

## Rolagem da lista

Em Relatórios de contratos, a área de trabalho ocupa a altura da janela. Cabeçalho da página, seletores, botões, mensagem dos filtros e controles Todos/Somente Vigentes permanecem visíveis. A rolagem vertical e horizontal fica na região da tabela; os títulos das colunas permanecem fixos no topo dela e a paginação fica acessível abaixo. A região recebe foco pelo teclado, com nome acessível. Os popovers continuam na camada superior do navegador, sem recorte pelo contêiner.

O ajuste usa flex/grid e cabeçalhos sticky, limitado à página identificada por `reports-page-heading`. A linha Todos/Somente Vigentes e Limpar filtros foi movida para fora da região rolável. Não muda consultas, filtros, dados ou PDF.

Validação desta alteração visual:

- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `docker compose exec -T app php artisan test --compact --filter='ContractBrowsingTest::test_.*report|ContractBrowsingTest::test_final_filter|ContractBrowsingTest::test_panel_pdf|ContractBrowsingTest::test_list_filters'`: 21 testes, 336 asserções, aprovados, com SQLite/storage/cache isolados.
- `node build/check-report-scroll.cjs`: aprovado em Microsoft Edge headless, usando fixture fictícia e o CSS compilado real, em janelas 1366×768, 800×900 e 390×844. Confirmou ausência de rolagem do documento, deslocamento das linhas de 250 px sem deslocamento dos títulos, paginação dentro da janela e abertura de popover. Sem autenticação ou leitura de dados reais nesse ensaio.
- `git diff --check`: aprovado.

Não foram repetidos arquitetura, unitários ou suíte completa para esta mudança restrita ao Blade/CSS. A suíte completa anterior e suas limitações permanecem registradas acima.
