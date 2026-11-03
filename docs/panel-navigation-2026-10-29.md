# Navegação entre painéis

Os quatro formulários com navegação entre seções usam o acabamento de Novo contrato: contratos novos/edição, Gestor do Fundo, Gestor de Contratos e Fiscal de Contratos.

A classe compartilhada `contract-section-navigation` define a linha superior do painel e o estilo da aba ativa: fundo branco, texto azul em negrito, contorno claro, cantos superiores arredondados e borda inferior integrada ao conteúdo. O contêiner `editor-panels` reúne as seções dos três cadastros sob uma única borda, com cantos inferiores arredondados. As seções não recebem margens externas, evitando lacunas quando a primeira seção está oculta. Expandir Tudo preserva o espaçamento entre as seções visíveis.

Contratos mantêm `contract-panels-scroll` e sua rolagem existente. Produtos e Expandir Tudo de Novo contrato preservam os estilos próprios. Expandir Tudo dos três cadastros usa o estilo das demais abas, conforme o ajuste solicitado anteriormente para Gestor do Fundo.

As três abas dos cadastros permanecem na mesma linha inclusive em telas pequenas, permitindo que qualquer aba selecionada se conecte à borda. Gestor do Fundo, Fiscal de Contratos e Gestor de Contratos usam largura reduzida em 60% em telas acima de 600px, com as abas juntas à esquerda. Campos, ações, permissões e gravação permanecem com os mesmos contratos.

## Validação executada

**Revisão antes do push (03/11/2026):** `git fetch origin` confirmou a base sincronizada; `git diff --check`, Pint dos quatro PHP alterados e `composer check:architecture` via Docker passaram. A execução final de `docker compose exec -T app php vendor/bin/phpunit --filter 'InspectorContractsTest|InspectorRegistrationTest' --do-not-cache-result` passou com 19 testes e 209 asserções. O build já passou após o último ajuste de apresentação. A suíte completa não foi repetida nesta revisão; a falha de detalhes/aditivos e o timeout estão registrados abaixo. Arquivos de referência e `build/` locais não integram a entrega. O botão Carregar permanece apenas visual, conforme solicitado; o diário ainda não possui fluxo de gravação de ocorrências.

**Referência legal no diário (03/11/2026):** ao selecionar Diario do Fiscal, aparece à direita do título da página, abaixo da área da foto do usuário, um bloco com o art. 117 da Lei 14.133/2021 (caput, §§ 1º–4º e incisos). Fica oculto nas demais abas, inclusive Expandir Tudo. O texto foi conferido na publicação do DOU disponibilizada pela ANA, páginas 53–54, com link de consulta no próprio bloco. A altura é limitada a 170px com rolagem e foco por teclado; em telas pequenas, ocupa a largura disponível.

Validação da referência: `docker compose exec -T app php vendor/bin/phpunit --filter InspectorRegistrationTest --do-not-cache-result` aprovado (13 testes, 140 asserções); build Docker e `git diff --check` aprovados. Sem ensaio visual autenticado ou mudança no banco/rotas.

**Histórico do diário (03/11/2026):** o card principal apresenta tabela de leitura com Contrato e Data (110px cada) e Histórico (largura restante, com quebra de texto). Não há campos ou ações de edição/exclusão na lista. O estado inicial é vazio: ainda não há fonte própria de ocorrências de fiscalização nem fluxo de inclusão integrado. Esta entrega apenas prepara a apresentação; não implementa persistência imutável nem declara atendimento completo à obrigação legal. A referência funcional é o [art. 117, § 1º da Lei 14.133/2021](https://normas.leg.br/?urn=urn%3Alex%3Abr%3Afederal%3Alei%3A2021-04-01%3B14133%21art117%21text), que prevê registro próprio de ocorrências.

Validação da tabela: `docker compose exec -T app php vendor/bin/phpunit --filter InspectorRegistrationTest --do-not-cache-result` aprovado (13 testes, 136 asserções, SQLite isolado), `docker compose run --rm --no-deps node npm run build` e `git diff --check` aprovados. Sem conferência visual autenticada; nenhuma alteração de banco ou rota.

**Linhas compactas e opções de vigência (03/11/2026):** acima da lista de contratos do fiscal, as opções exclusivas Vencidos/Vigentes enviam o filtro ao servidor ao mudar a seleção. Vigentes inicia marcado. A paginação preserva a escolha, e trocar de opção retorna à primeira página. A classificação usa o término atualizado e a mesma regra dos relatórios (posterior a hoje: vigente; até hoje: vencido; sem término: fora de ambas). As células usam espaçamento vertical de 4px, mantendo as segundas linhas de Secretaria e Aditivo.

Validação desse filtro: `docker compose exec -T app php vendor/bin/phpunit --filter 'InspectorContractsTest|InspectorRegistrationTest' --do-not-cache-result` aprovado (19 testes, 202 asserções), incluindo padrão selecionado, limites de data, paginação e rejeição de filtros inválidos; arquitetura via Docker aprovada (3 testes); Pint dos três PHP alterados, build Docker e `git diff --check` aprovados. SQLite em memória e storage temporário; sem ensaio visual autenticado. A suíte geral não foi repetida; suas limitações anteriores seguem registradas abaixo.

**Contratos do Fiscal (03/11/2026):** o botão abre uma janela suspensa nativa, com título, botão X e lista paginada (25 por página). A consulta é carregada e autorizada novamente a cada abertura. Colunas: Contrato, Tipo_Cont (tipo de contrato salvo), Fornecedor, Fundo & Secretaria, Objeto & Aditivo e Final (término atualizado, em dia/mês/ano). Secretaria aparece em linha própria somente para Município de Niquelândia; o número do último aditivo aparece abaixo do objeto, usando o resumo legado quando não há aditivo normalizado. Ausência de aditivo não gera uma segunda linha. Contratos excluídos e contratos fora dos escopos atuais não aparecem. A associação usa CPF salvo, ou nome único no órgão para contratos sem CPF; não usa parâmetros do navegador como autorização.

Validação da janela: `docker compose exec -T app php vendor/bin/phpunit --filter 'InspectorContractsTest|InspectorRegistrationTest' --do-not-cache-result` aprovado (18 testes, 174 asserções, SQLite em memória/storage temporário); `composer check:architecture` via Docker aprovado (3 testes); `composer test:unit` via Docker aprovado (81 testes); Pint dos PHP novos/alterados, build Docker e `git diff --check` aprovados. Sem teste visual autenticado no navegador ou mutação do banco instalado.

`docker compose exec -T app composer check` passou no Pint (381 arquivos), mas a execução PHPUnit excedeu o limite de 300 segundos do Composer, com falhas sinalizadas e sem relatório final. A investigação por `docker compose exec -T app php -d memory_limit=1536M vendor/bin/phpunit --stop-on-failure --do-not-cache-result` executou 175 testes e identificou a primeira falha em `ContractAmendmentsTest::test_contract_summary_embeds_paginated_amendments_with_actions_and_contract_scope` (linha 96): a tela de detalhes não apresenta “Excluir contrato” na ordem esperada. Esse teste e a tela de detalhes não foram alterados nesta entrega; a suíte geral não está aprovada. A rota foi confirmada por `php artisan route:list --path=inspectors --except-vendor`, e `/login` retornou HTTP 200.

Em 03/11/2026, Fiscal de Contratos ganhou a aba **Diario do Fiscal**, com painel vazio e botão azul del rey (`#002395`, texto branco), mantendo o formato e as dimensões das abas existentes. A navegação desse cadastro passa a ter quatro botões na mesma linha; Expandir Tudo continua exibindo os dados cadastrais. A aba é apenas visual, sem persistência.

A aba fica após Expandir Tudo. Ao selecionar Diario do Fiscal, a barra de ações e a lista de fiscais ficam ocultas, e o painel vazio ocupa a altura disponível da área de trabalho. Ao voltar às abas cadastrais, os cards reaparecem. O estado da aba fica no contêiner da página para controlar também a lista externa ao formulário.

O painel do diário agora mostra uma mensagem com nome, decreto, Fundo e Secretaria do fiscal salvo, sem campos de formulário. Sem fiscal selecionado, orienta a selecionar um cadastro. Fundo não municipal apresenta Secretaria como “Não se aplica”; Município sem vínculo apresenta “Sem secretaria”. Usa os dados já autorizados da página, sem novas consultas ou rotas.

Apresentação ajustada: texto diretamente no card principal, sem caixa verde, com Fiscal e Decreto na primeira linha e Fundo e Secretaria na segunda, separados por `|`. Validação: build Docker e `git diff --check` aprovados; teste `test_form_registers_native_inspector_and_audits_without_changing_contracts` aprovado em SQLite isolado. Sem conferência visual autenticada.

Validação da mensagem: `docker compose exec -T app php vendor/bin/phpunit --filter InspectorRegistrationTest --do-not-cache-result` aprovado (13 testes, 133 asserções em SQLite isolado); `docker compose run --rm --no-deps node npm run build` e `git diff --check` aprovados. Sem conferência visual autenticada.

Validação desse ajuste: `docker compose exec -T app php vendor/bin/phpunit --filter InspectorRegistrationTest --do-not-cache-result` aprovado (13 testes, 127 asserções em SQLite isolado); `docker compose run --rm --no-deps node npm run build` e `git diff --check` aprovados. Sem conferência visual autenticada no navegador.

Validação de 03/11/2026: `docker compose exec -T app php vendor/bin/phpunit --filter InspectorRegistrationTest --do-not-cache-result` aprovado (13 testes, 125 asserções, SQLite em memória e storage temporário); `docker compose run --rm --no-deps node npm run build` e `git diff --check` aprovados. `/login` retornou HTTP 200. Sem conferência visual autenticada no navegador ou execução da suíte completa.

- `docker compose exec -T app php vendor/bin/phpunit --filter 'InspectorRegistrationTest|ContractStewardRegistrationTest|ManagerRegistrationTest|CatalogNavigationTest' --do-not-cache-result`: 35 testes, 381 asserções aprovados, com SQLite em memória.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `git diff --check`: aprovado.
- Chrome headless com `--dump-dom`, `--virtual-time-budget=3000` e janelas de 1280×900 e 500×900: fixture representativa dos quatro formulários carregando o CSS compilado local. Doze estados por tamanho (primeira aba, segunda aba e expansão) passaram nas verificações de cor, peso do texto, contorno, ausência de margem externa e ligação geométrica entre abas e painéis.

Fixture e saídas em `%TEMP%/contracts-panel-navigation-check`. Sem ensaio autenticado dos fluxos no navegador, mutações no banco instalado ou execução adicional da suíte PHPUnit completa.

Após estender a redução de largura a Fiscal de Contratos e Gestor de Contratos, `docker compose run --rm --no-deps node npm run build` e `git diff --check` passaram novamente. Ajuste apenas de CSS, sem nova execução de PHPUnit ou conferência no navegador.
