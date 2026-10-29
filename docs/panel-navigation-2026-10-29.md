# Navegação entre painéis

Os quatro formulários com navegação entre seções usam o acabamento de Novo contrato: contratos novos/edição, Gestor do Fundo, Gestor de Contratos e Fiscal de Contratos.

A classe compartilhada `contract-section-navigation` define a linha superior do painel e o estilo da aba ativa: fundo branco, texto azul em negrito, contorno claro, cantos superiores arredondados e borda inferior integrada ao conteúdo. O contêiner `editor-panels` reúne as seções dos três cadastros sob uma única borda, com cantos inferiores arredondados. As seções não recebem margens externas, evitando lacunas quando a primeira seção está oculta. Expandir Tudo preserva o espaçamento entre as seções visíveis.

Contratos mantêm `contract-panels-scroll` e sua rolagem existente. Produtos e Expandir Tudo de Novo contrato preservam os estilos próprios. Expandir Tudo dos três cadastros usa o estilo das demais abas, conforme o ajuste solicitado anteriormente para Gestor do Fundo.

As três abas dos cadastros permanecem na mesma linha inclusive em telas pequenas, permitindo que qualquer aba selecionada se conecte à borda. Gestor do Fundo, Fiscal de Contratos e Gestor de Contratos usam largura reduzida em 60% em telas acima de 600px, com as abas juntas à esquerda. Campos, ações, permissões e gravação permanecem com os mesmos contratos.

## Validação executada

- `docker compose exec -T app php vendor/bin/phpunit --filter 'InspectorRegistrationTest|ContractStewardRegistrationTest|ManagerRegistrationTest|CatalogNavigationTest' --do-not-cache-result`: 35 testes, 381 asserções aprovados, com SQLite em memória.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `git diff --check`: aprovado.
- Chrome headless com `--dump-dom`, `--virtual-time-budget=3000` e janelas de 1280×900 e 500×900: fixture representativa dos quatro formulários carregando o CSS compilado local. Doze estados por tamanho (primeira aba, segunda aba e expansão) passaram nas verificações de cor, peso do texto, contorno, ausência de margem externa e ligação geométrica entre abas e painéis.

Fixture e saídas em `%TEMP%/contracts-panel-navigation-check`. Sem ensaio autenticado dos fluxos no navegador, mutações no banco instalado ou execução adicional da suíte PHPUnit completa.

Após estender a redução de largura a Fiscal de Contratos e Gestor de Contratos, `docker compose run --rm --no-deps node npm run build` e `git diff --check` passaram novamente. Ajuste apenas de CSS, sem nova execução de PHPUnit ou conferência no navegador.
