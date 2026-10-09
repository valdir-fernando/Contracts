# Login

A tela `/login` contém somente um card centralizado, com título Contracts, usuário e senha. O visual segue a linha do shadcn e mantém o azul Del Rey.

A autenticação usa `users.username`, correspondente ao `Nick_Name` de `tb_Usuario` no arquivo `database-example/u910323952_bdgestao_niq.sql`. O nome é normalizado para maiúsculas. As senhas da referência são convertidas em hash pelo model, sem armazenar novas cópias em texto simples. O login mantém CSRF, bloqueio de usuários inativos, limite de cinco tentativas por usuário/IP e regeneração da sessão.

A migration preserva os registros existentes e atribui a eles o login `USER.<id>`. O seeder importa os 17 usuários de referência, incluindo nome, perfil, fundo e secretaria. A gestão de usuários aplica autorização por perfil e mantém vínculos relacionais de fundo/secretaria, conforme [Usuários e permissões](07_USUARIOS_E_PERMISSOES.md). Juliana Barbosa usa `JULIANA`; Juliana Mendes usa `JULIANA.MENDES`. Os demais usam seus apelidos originais. Executar o seeder novamente não redefine as senhas, duplica usuários nem reativa contas excluídas.

```powershell
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan db:seed --class=ReferenceUsersSeeder --force
docker compose exec -T app php vendor/bin/phpunit
```

Os testes usam SQLite em memória e cobrem validação, autenticação, logout, usuário inativo, limite de tentativas, normalização e importação idempotente dos usuários de referência.
