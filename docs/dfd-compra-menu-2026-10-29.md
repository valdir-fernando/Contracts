# Janela de classes — DFD Compra

Clicar no menu DFD Compra abre a janela flutuante **DFD - Documento de Formalização de Demanda** sobre a página atual, inclusive com a barra lateral recolhida. O botão **X** usa a classe e o ícone `modal-close` dos demais painéis da suíte. Também fecha por Escape e clique fora, devolvendo o foco ao menu.

A janela consulta `GET /dfd-compra/classes` e apresenta os valores distintos, não vazios e em ordem alfabética de `tb_Produtos_Prodata.Classe`, considerando produtos ativos (`Inativo=0`). A resposta retorna somente `classes`, exige usuário autenticado e Fundo atual autorizado e usa `private, no-store`. Sessão com Fundo revogado é encerrada pelo middleware existente.

O filtro pesquisa sem diferenciar maiúsculas e acentos. Clicar em uma classe já seleciona a linha, destacando-a em azul e apresentando o nome acima da lista. Não existe botão Selecionar classe. A janela permanece aberta durante a seleção. Ao fechá-la pelo X, Escape ou clique fora, uma classe escolhida abre `/dfd-compra?classe=...`; o controller revalida ator, Fundo e existência da classe ativa. O painel apresenta o nome no campo somente leitura Classe / Categoria, escapado pelo Blade. Fechar sem escolher uma classe mantém a página e o valor anterior. O parâmetro na URL permite recarregar o painel mantendo a classe validada, sem armazenar a seleção em cache global ou localStorage.

Setas, Home e End permitem percorrer as linhas; Enter e espaço também selecionam a linha focada. O [painel de DFD](dfd-panel-2026-10-29.md) apresenta o formulário de informações gerais e produtos. A seleção da classe não grava DFD nem consome numeração. Fechar e reabrir limpa a seleção da janela e recarrega as classes; requisições antigas são abortadas e descartadas. Falha de consulta apresenta Tentar novamente. Os nomes são inseridos como texto, sem interpretar HTML.

A [numeração anual global](dfd-numbering-2026-10-29.md) continua independente desse seletor.

## Instalação local executada

A tabela `tb_Produtos_Prodata` estava ausente, conforme consulta `docker compose exec -T app php artisan db:table tb_Produtos_Prodata`.

- `docker compose exec -T app php artisan migrate --path=database/migrations/2026_10_29_000001_create_dfd_purchase_tables.php --force`: migration aditiva aprovada, criando as estruturas ausentes de catálogo, DFD e produtos. Não foi executado o conjunto completo de migrations nem a migration de numeração desta etapa.
- `docker compose exec -T app php artisan contracts:import-dfd --catalog-only`: simulação com 10.659 registros de catálogo.
- `docker compose exec -T app php artisan contracts:import-dfd --catalog-only --apply`: 10.659 importados, zero conflitos. Origem: dump local de referência. A nova opção limita a importação ao catálogo Prodata; DFDs históricas e seus produtos não são importados. Registra origem, execução e auditoria na mesma transação, sem sobrescrever registros existentes.

## Validação executada

Título do painel: `/dfd-compra` também exibe **DFD - Documento de Formalização de Demanda**, com 8px entre a linha do cabeçalho e a caixa do título, sem margem superior adicional no `h1`. O menu continua identificado como DFD Compra. Validação: `docker compose exec -T app php vendor/bin/phpunit --filter DfdCompraNavigationTest --do-not-cache-result` aprovado (5 testes, 35 asserções, SQLite em memória), `docker compose run --rm --no-deps node npm run build` e `git diff --check` aprovados. Sem validação visual no navegador nesta alteração.

Exibição da classe no painel ao fechar: build Docker aprovado; `docker compose exec -T app php vendor/bin/phpunit --filter DfdCompraNavigationTest --do-not-cache-result` aprovado com 5 testes e 35 asserções; Pint do teste alterado e `git diff --check` aprovados. O novo teste confere campo preenchido e escapado, ausência de classe, rejeição de classe inexistente e ausência de gravação/consumo de número. `node scripts/browser-dfd-classes.cjs` passou em desktop e celular, incluindo clique sem navegação imediata, classe no painel após fechar por X/Escape e preservação do valor anterior ao reabrir e fechar sem escolha. Sem novas migrations, importações, gravações no banco instalado ou execução da suíte completa nesta revisão.

Ajuste posterior de título, X e seleção direta: `docker compose run --rm --no-deps node npm run build`, `docker compose exec -T app php vendor/bin/phpunit --filter DfdCompraNavigationTest --do-not-cache-result` (4 testes, 28 asserções), Pint do teste alterado e `git diff --check` aprovados. A fixture `node scripts/browser-dfd-classes.cjs` foi atualizada para verificar o título completo, o ícone X, ausência de confirmação, seleção por clique/teclado com a janela aberta e sem navegação, em desktop e celular. A validação abaixo registra a entrega anterior; seu antigo destino de navegação foi removido neste ajuste. Nenhuma alteração de dados ou estrutura nesta revisão de interface.

- `docker compose exec -T app php vendor/bin/phpunit --filter 'DfdCompraNavigationTest|DfdNumberingTest|DfdNumberSequenceMigrationTest' --do-not-cache-result`: 18 testes, 85 asserções aprovados em SQLite em memória. Inclui autorização, projeção/lista de classes, menu/modal e importação isolada/idempotente do catálogo, além da regressão da numeração. O teste antigo de navegação passou a preparar Fundo e escopo autorizados, preservando as asserções de acesso e destaque; a negativa cobre ausência e revogação de acesso.
- `docker compose exec -T app php vendor/bin/pint --test app/Console/Commands/ImportDfd.php app/Infrastructure/Dfd tests/Feature/DfdCompraNavigationTest.php tests/Feature/DfdNumberingTest.php`: aprovado em 7 arquivos.
- `docker compose exec -T app composer check:architecture`: 3 testes aprovados.
- `docker compose exec -T app composer test:unit`: 81 testes aprovados.
- `docker compose run --rm --no-deps node npm test`: 144 testes aprovados; log local em `build/dfd-javascript-tests.log`.
- `docker compose run --rm --no-deps node npm run build`: aprovado após os ajustes finais.
- `node scripts/browser-dfd-classes.cjs`: aprovado com Playwright e Chrome local, em 1280×900 e 500×900. Fixture com o componente Blade e os assets reais compilados; respostas HTTP de classes sintéticas. Conferidos abertura sem navegação, pesquisa com acentos, seleção azul, rolagem, largura responsiva, botão amarelo, foco, Escape, falha/repetição, reabertura durante carregamento, destino da classe e texto sem interpretação HTML. Sem erros JavaScript. Capturas locais: `build/dfd-modal/1280.png` e `500.png`. Playwright instalado em `%TEMP%/contracts-dfd-browser`, referenciado por `NODE_PATH`; `CONTRACTS_BROWSER_EXECUTABLE` apontou para o Chrome instalado.
- `git diff --check`: aprovado.

Sem ensaio autenticado no navegador. A suíte PHPUnit completa não foi repetida nesta etapa; a tentativa anterior de `composer check` apresentou falhas e atingiu o timeout de 300 segundos, conforme o guia de numeração. A importação local do catálogo e a migration aditiva descritas acima foram as únicas mudanças de dados/estrutura desta etapa; testes de integração usaram armazenamento e banco isolados.
