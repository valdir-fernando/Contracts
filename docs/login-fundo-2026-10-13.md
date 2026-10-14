# Fundo no login e dados do gestor — 13/10/2026

O login exige escolher um Fundo cadastrado e autorizado para a conta. O cabeçalho do Início apresenta o nome atual do Fundo selecionado. Novos contratos sugerem Fundo, documento, endereço e dados do gestor provenientes de `tb_Gestor`. CPF/RG são sugeridos somente para administradores. Campos já digitados prevalecem sobre sugestões, e a edição de contratos existentes preserva seus dados históricos.

A migration adiciona somente `contract_managers`. O comando de importação preserva os oito registros de `tb_Gestor` do dump local, sem alterar contratos. Todos os oito gestores encontraram Fundo correspondente. Vínculos ambíguos não são escolhidos automaticamente. A seleção não modifica a matriz de permissões. A evolução posterior limita os indicadores e contratos recentes do Início ao Fundo da sessão e oferece troca de Fundo autenticada no cabeçalho.

Sessões anteriores sem Fundo podem escolher o contexto em **Fundo selecionado → Alterar Fundo**, no cabeçalho. Acesso restaurado por cookie de lembrar login sem contexto exige novo login. Revogação de acesso ao Fundo selecionado encerra a sessão.

## Validação executada

| Comando/checagem | Resultado |
| --- | --- |
| `docker compose exec -T app php vendor/bin/phpunit --filter='LoginTest\|SelectedFundTest\|UserManagementTest\|ContractImportTest' --do-not-cache-result` | 37 testes e 286 asserções aprovados na validação inicial |
| `docker compose exec -T -e QUEUE_CONNECTION=database app composer check` | Versão final: Pint aprovado em 178 arquivos; 274 testes e 2.114 asserções aprovados |
| `docker compose exec -T app composer check:architecture` | 3 testes e 4 asserções aprovados |
| `docker compose exec -T app composer test:unit` | 51 testes e 97 asserções aprovados |
| `docker compose run --rm --no-deps node npm run build` | Build final aprovado |
| `node --check` em `resources/js/app.js`, `scripts/browser-smoke.cjs` e `scripts/browser-localization.cjs` | Sintaxe aprovada |
| `git diff --check` | Sem erros |
| `php artisan migrate --path=database/migrations/2026_10_13_000001_create_contract_managers.php --force` no container app | Tabela adicional criada no banco local |
| `php artisan contracts:import-managers` no container app | 8 registros importados; 0 rejeitados |
| Consulta agregada de gestores/fundos no container app | 8 gestores, todos com Fundo correspondente |
| Requisição HTTP local a `/login` | HTTP 200, Fundo obrigatório e 8 opções |

Os testes utilizaram SQLite em memória e armazenamento/cache temporários. `QUEUE_CONNECTION=database` foi explicitado porque o `.env` local usa `sync` e o teste existente de escala espera uma exportação pendente. Não houve teste MySQL, ensaio de navegador ou publicação externa; Playwright não está disponível no host. A troca de Fundo no navegador tem validação de sintaxe/build; autorização, projeção e sugestões são cobertas pelos testes de integração. Não foram criados commits.
