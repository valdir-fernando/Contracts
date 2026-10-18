# Menu Cadastros — 17/10/2026

O menu Cadastros reúne Fiscal, Gestor, Fundo, Secretaria, Dotação e Fornecedor. **Contratos** mantém o acesso à listagem de contratos no menu principal, imediatamente abaixo de **Início**, fora do submenu Cadastros (ajuste de 18/10/2026). Fundo e Secretaria apontam às seções existentes de Órgãos e permissões; Fornecedor mantém sua tela e suas permissões.

O submenu adicional **Gestor_Fundo** consulta `/catalogs/list/manager-fund`: apresenta Fundo e Gestor, ordenados por Fundo, a partir das associações existentes em `contract_managers`. Mantém a restrição administrativa, a projeção explícita sem dados pessoais e o cache privado das demais listas. Não cria uma nova origem de dados nem altera vínculos.

Fiscal, Gestor e Dotação possuem listas paginadas de consulta dos catálogos existentes. Não foram adicionadas operações de gravação nesses três cadastros. As rotas `/catalogs/list/inspector`, `/catalogs/list/manager` e `/catalogs/list/budget` exigem administrador no servidor, projetam colunas explícitas e retornam cache privado sem armazenamento. Não expõem payloads nem documentos pessoais de fiscais/gestores.

Validação executada em Docker, com SQLite em memória nos testes:

- `php artisan test --filter=CatalogNavigationTest`: 2 testes, 12 asserções, passou.
- `composer check:architecture`: 3 testes, 4 asserções, passou.
- `composer test:unit`: 57 testes, 129 asserções, passou.
- `composer check`: Pint passou; suíte com 433 testes, 4057 asserções, sem falhas e 1 teste ignorado.
- `npm run build`: passou.
- `php artisan view:cache` e `php artisan view:clear`: passaram.

Não houve validação visual em navegador.

Validação do retorno de Contratos ao menu principal em 18/10/2026: `docker compose exec -T app php vendor/bin/phpunit --filter CatalogNavigationTest --do-not-cache-result` passou com 2 testes e 21 asserções, incluindo a ordem Início → Contratos → Cadastros; `git diff --check` passou. Alteração somente em Blade, teste e documentação, sem necessidade de recompilar assets.

Validação da inclusão de Gestor_Fundo: `docker compose exec -T app php artisan test --filter=CatalogNavigationTest` passou com 2 testes e 22 asserções (acesso administrativo, vínculo exibido, link, cache e ausência de dados pessoais); `composer check:architecture` passou com 3 testes/4 asserções; `composer test:unit` passou com 57 testes/129 asserções. `docker compose run --rm --no-deps node npm run build` passou. Testes executados com SQLite em memória. A primeira execução do teste de navegação falhou por espaçamento do atributo HTML na asserção; a verificação do destino do link foi ajustada e passou.

`docker compose exec -T app composer check` também passou: Pint, 433 testes e 4067 asserções, com 1 teste ignorado. `git diff --check` passou. A inclusão não foi validada visualmente em navegador.
