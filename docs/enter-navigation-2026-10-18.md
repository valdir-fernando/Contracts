# Navegação com Enter

A navegação de preenchimento é centralizada em `resources/js/enter-navigation.js`, importado pelo bundle comum ao login e às telas autenticadas. Um listener delegado de `keydown` cobre também elementos inseridos por Livewire e janelas abertas depois do carregamento.

## Comportamento

- Enter em campos de texto, senha, e-mail, telefone, URL, busca, número e textarea de uma linha impede envio implícito e avança ao próximo campo editável visível.
- Campos ocultos, desabilitados (incluindo fieldsets), somente leitura, fora da navegação por Tab ou em regiões ocultas/inertes são ignorados como destino.
- Ao terminar os campos visíveis, o foco vai para uma ação de envio disponível, sem acioná-la. Se não houver botão de envio, usa uma ação seguinte disponível; sem destino, mantém o foco. Nunca muda para outro formulário.
- Botões selecionados continuam acionáveis por Enter. Links, resumos de detalhes, seletores, calendários nativos, arquivos e outros controles nativos mantêm a interação própria. Enter em checkbox/radio aciona somente a escolha, impedindo envio implícito.
- Textareas com mais de uma linha e edição de conteúdo mantêm a quebra de linha. Modificadores de teclado, composição IME e eventos já tratados não são interceptados.
- A busca pelo número do contrato agora exige ativar o botão Buscar; Enter no número apenas foca esse botão. Em produtos, Enter na quantidade não lança o item: o lançamento depende de selecionar e acionar Salvar produto. As consultas existentes ao sair dos campos permanecem.
- A validação específica do CPF do fiscal continua antes da navegação; CPF inválido abre a caixa padrão, e CPF correto permite avançar ao RG.

O escopo é o ancestral mais próximo de formulário, diálogo ou `[data-enter-scope]`; fora deles, usa a região principal. Número do contrato e painel de produtos possuem escopo próprio para manter o foco nas ações corretas. `[data-enter-native]`, combobox/listbox e inputs com datalist preservam Enter nativo. `[data-enter-action]` pode identificar uma ação preferida no final dos campos.

## Validação

- `node --test tests/JavaScript/*.test.mjs`: 53 testes aprovados.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `docker compose exec -T app composer check`: Pint aprovado em 248 arquivos; PHPUnit com 442 testes, 4.143 asserções, sem falhas e um teste ignorado por exigir MySQL isolado para concorrência. Execução com SQLite em memória e storage/cache temporários.
- `node --check scripts/browser-enter-navigation.cjs` e `git diff --check`: aprovados.
- `scripts/browser-enter-navigation.cjs`, com Playwright e Edge headless: aprovado. Verifica ordem de foco, exclusão de campos ocultos/desabilitados/somente leitura/fora do Tab, login, filtros, busca, checkbox/radio, produtos, modais, texto multilinha, links, detalhes, edição de conteúdo, controles dinâmicos, formulário de campo único sem botão e CPF inválido seguido de correção. Usa HTML fictício e intercepta submissões; não lê nem altera o banco instalado.

Para repetir a conferência de navegador, disponibilizar Playwright no ambiente Node, definir `CONTRACTS_BROWSER_EXECUTABLE` se necessário e executar `node scripts/browser-enter-navigation.cjs` com os assets servidos pelo ambiente local. `CONTRACTS_URL` permite alterar a origem (padrão `http://127.0.0.1:8000`).

Não há alterações de API, banco, permissões ou regras transacionais.
