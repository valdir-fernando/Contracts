# Exercício dos contratos no Início — 13/10/2026

O painel inicia no ano atual, obtido do relógio e fuso da aplicação. O campo **Ano dos contratos** oferece o exercício atual, exercícios anteriores existentes nos contratos autorizados e **Todos os anos**. O critério é `contracts.exercicio`, compartilhado com a listagem e os relatórios; não é o ano do vencimento.

A [evolução da troca de Fundo](fund-switching-2026-10-13.md) acrescenta o recorte do Fundo da sessão: anos disponíveis, indicadores e contratos recentes seguem simultaneamente Fundo e exercício.

A seleção atualiza cards, distribuição de vigência, valores iniciais exatos, órgãos, vencimentos mensais, alertas e contratos recentes. A busca permanece dentro do exercício escolhido; a troca do ano reinicia a paginação. Links de cards/listagem mantêm o ano, e `year` na URL permite restaurar a seleção. Registros sem exercício aparecem em todos os anos. Vigências e janelas de alerta continuam relativas à data atual, inclusive ao consultar um exercício anterior.

Todas as consultas mantêm `visibleTo`, policies e soft delete. Anos de contratos inacessíveis não aparecem como opções. Não houve alteração de dados, migrations, regras financeiras ou permissões.

## Validação

- `docker compose exec -T app php vendor/bin/phpunit --filter='OverviewYearTest|ContractBrowsingTest|ExpirationCalendarTest|HistoryAndOperationsTest|TableNavigationTest|SelectedFundTest|InternalEvolutionsTest' --do-not-cache-result`: 44 testes, 544 asserções aprovados.
- Pint nos nove arquivos PHP alterados nesta etapa: aprovado.
- `docker compose exec -T -e QUEUE_CONNECTION=database app composer check`: Pint aprovado em 179 arquivos; suíte completa com 280 testes e 2.176 asserções aprovados, incluindo Architecture e Unit. A fila foi explicitada porque o `.env` local usa `sync` e o teste existente de escala espera exportação pendente.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `git diff --check`: sem erros.

Os testes novos cobrem exercício atual, anterior, todos os anos, registros sem ano, restrições de escopo, totais decimais exatos, alertas, links, URL, paginação, entradas inválidas e mudança do ano de referência. Fixtures anteriores de calendário agora informam exercício; cenários antigos destinados a todos os registros escolhem `year=all` explicitamente. O teste do cabeçalho foi atualizado para a classe da fonte responsiva adicionada na etapa anterior.

Testes usam SQLite em memória e armazenamento/cache temporários. Não foram executados testes MySQL nem ensaios de navegador nesta etapa. Nenhum commit ou publicação externa foi realizado.
