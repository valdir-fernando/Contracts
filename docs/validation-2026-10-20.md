# Validação antes do push — 20/10/2026

Comandos executados no ambiente Docker:

- `docker compose exec -T app composer check:architecture`: passou, 3 testes e 4 asserções.
- `docker compose exec -T app composer test:unit`: passou, 77 testes e 169 asserções.
- `docker compose exec -T app composer check`: Pint passou (323 arquivos); PHPUnit concluiu 680 testes, 6.788 asserções e 1 teste ignorado, sem falhas.
- `docker compose run --rm --no-deps node npm test`: passou, 108 testes.
- `docker compose run --rm --no-deps node npm run build`: passou.
- `git diff --check` e `git diff --cached --check`: passaram.

PHPUnit usou SQLite em memória e storage/cache temporários conforme a configuração do repositório. Nenhuma migration ou importação operacional foi executada nesta validação. Não foi realizado ensaio manual autenticado em navegador.

Prévias geradas em `build/` e imagens locais `Reference/Fernando_01.jpg` e `Reference/Fernando_02.jpg` ficaram fora dos commits.
