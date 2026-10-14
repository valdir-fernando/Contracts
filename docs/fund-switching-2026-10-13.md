# Indicadores por Fundo e troca de contexto — 13/10/2026

O Início mostra somente contratos do Fundo selecionado na sessão, combinando esse filtro com o exercício escolhido. O recorte aplica-se também a administradores e inclui cards, valores iniciais exatos, distribuição de vigência, gráficos, alertas, opções de anos e contratos recentes. Os links dos cards/listagem levam o Fundo e o ano para a consulta de contratos. Selecionar **Todos os anos** continua limitado ao Fundo atual.

O formulário **Fundo selecionado → Alterar Fundo**, no topo das telas, lista apenas fundos autorizados e permite alternar sem encerrar a sessão. A confirmação abre o Início, preservando o ano quando a troca é feita nele. O cabeçalho e as sugestões para novos contratos usam o novo Fundo. Nenhum contrato existente ou escopo é modificado.

`POST /session/fund` exige autenticação e CSRF, valida a entrada, bloqueia/revalida o ator antes do Fundo e registra `fund_selected` na mesma transação. A sessão só muda após o commit. Falhas de validação, permissão ou auditoria preservam a escolha anterior. O Fundo usado no painel vem da sessão e é reautorizado em cada renderização Livewire; parâmetros URL e snapshots do cliente não substituem esse contexto. Escopos de secretaria continuam sendo aplicados além do filtro de Fundo.

Sessões antigas sem contexto exibem um pedido de seleção e nenhum indicador/carteira. O usuário pode escolher um Fundo no cabeçalho sem novo login. Revogação de acesso ao Fundo atual continua encerrando a sessão. As demais consultas mantêm seus contratos HTTP, permissões e filtros existentes; a mudança não aplica uma restrição global implícita a todos os módulos.

## Validação executada

Na tela Novo Contrato, o espaço entre o cabeçalho com o nome do Fundo e o seletor de alternância foi reduzido para 8px, inclusive em janelas menores. Ajuste restrito à rota de criação. Verificação do ajuste: `php vendor/bin/phpunit --filter=FundSwitchingTest --do-not-cache-result` aprovado (8 testes, 85 asserções), `npm run build` aprovado e `git diff --check` sem erros. Não houve ensaio visual em navegador.

- `docker compose exec -T app php vendor/bin/phpunit --filter='FundSwitchingTest|OverviewYearTest|ContractBrowsingTest|ExpirationCalendarTest|HistoryAndOperationsTest|TableNavigationTest|SelectedFundTest|InternalEvolutionsTest' --do-not-cache-result`: 52 testes e 630 asserções aprovados.
- Pint nos 13 arquivos PHP alterados nesta etapa: aprovado após ordenação de imports.
- `docker compose exec -T -e QUEUE_CONNECTION=database app composer check`: Pint aprovado em 181 arquivos; 288 testes e 2.263 asserções aprovados, incluindo Architecture e Unit. A fila foi explicitada porque o `.env` local usa `sync` e o teste existente de escala espera uma exportação pendente.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `node --check resources/js/app.js`: aprovado.
- `git diff --check`: sem erros.

Os oito testes novos verificam indicadores do Fundo para administradores, troca com manutenção de autenticação/ano, atualização do cabeçalho e sugestões, escopos de secretaria, opções autorizadas, alvos inválidos, reautorização do perfil sob lock, rollback em falha de auditoria, seleção em sessão antiga e recusa de substituição pelo parâmetro URL/usuário não autenticado.

SQLite em memória e armazenamento/cache temporários foram utilizados. Não houve migrations, alterações no banco local, ensaios de navegador, testes MySQL, commits ou publicação externa nesta etapa.
