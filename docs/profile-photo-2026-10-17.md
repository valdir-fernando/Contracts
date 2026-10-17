# Foto de perfil em Minha conta

O usuário pode clicar no círculo do cabeçalho ou em **Minha conta**, escolher uma foto, conferir a prévia e clicar em **Salvar foto**. A imagem passa a aparecer no cabeçalho e no menu lateral. **Remover foto e usar minha inicial** restaura a letra inicial. A foto tem formulário próprio, sem exigir alteração de nome, e-mail ou senha.

São aceitos JPEG, PNG e WebP de até 2 MB, entre 32 e 3.000 pixels por dimensão. O servidor valida tipo/conteúdo/tamanho/dimensões, decodifica a imagem e grava um JPEG novo de 256 × 256 pixels com recorte central. O arquivo original e seus metadados não são publicados. A exibição é circular; a seleção tem prévia local, mas somente Salvar foto persiste a imagem.

## Persistência e acesso

- A migration aditiva `2026_10_17_000001_add_profile_photo_to_users.php` acrescenta `avatar_path` e `avatar_sha256` opcionais em `users`, sem reescrever usuários existentes. Ambos ficam fora da serialização do model e da atribuição em massa.
- Os arquivos ficam no disco privado `local`, em `profile-photos`, com nome aleatório. Não há link público nem necessidade de `storage:link`.
- `POST /account/avatar` recebe o arquivo; `DELETE /account/avatar` remove a foto; `GET /account/avatar` entrega exclusivamente a foto da própria conta autenticada, verificando integridade SHA-256 e respondendo com `private, no-store` e `nosniff`.
- Não há ID de usuário para escolher o dono. Autenticação e middleware de atividade/sessão existentes protegem as três rotas. `ProfilePhoto` revalida e bloqueia o ator para persistir foto e auditoria (`profile_photo_updated`/`profile_photo_removed`) na mesma transação. A sessão não é invalidada por trocar foto.
- Processamento da imagem ocorre antes da transação. Falha de persistência/auditoria remove apenas o novo arquivo e mantém a foto anterior. Arquivos substituídos/removidos são apagados somente após commit.

## Instalação local executada

O GD da imagem Docker anterior não oferecia JPEG/WebP. O Dockerfile PHP foi estendido para habilitá-los, e `docker compose build app` passou. A imagem de produção e a de cobertura herdam essa base.

Foi executada somente a migration aditiva desta funcionalidade:

```powershell
docker compose run --rm --no-deps app php artisan migrate --path=database/migrations/2026_10_17_000001_add_profile_photo_to_users.php --force
docker compose up -d app worker scheduler
```

A migration passou e os serviços foram recriados. Conferência somente de leitura confirmou colunas presentes e suporte a JPEG/WebP. Não foram criados usuários ou fotos fictícias no banco instalado. Login e `/up` retornaram HTTP 200.

## Validação

`ProfilePhotoTest` passou com 7 testes e 70 asserções (SQLite em memória e storage fake temporário): conversão/recorte, integridade, armazenamento privado, projeção do perfil, troca/remoção, isolamento entre contas, tentativas de escolher outro usuário, tipo/tamanho/dimensões inválidos, rollback por falha de auditoria e identidade de sessão antiga. A primeira execução identificou a ordem normalizada do cabeçalho Cache-Control; a segunda evidenciou que o guard precisava receber o usuário atualizado após salvar/remover, o que foi corrigido sem enfraquecer a verificação da foto exibida.

Arquitetura passou (3 testes, 4 asserções), análise estática passou, npm test passou (48 testes), npm run build e php artisan view:cache passaram. Edge headless com fixture Blade sem banco confirmou círculo de 96 px, seleção/prévia PNG, recusa de SVG e envio multipart pelo botão Salvar foto, independente do formulário de senha. O HTTP dessa conferência foi simulado; persistência e autorização foram verificadas nos testes PHP. `git diff --check` passou.

Verificação final: composer check passou (Pint em 232 arquivos; 418 testes, 417 aprovados e 1 ignorado por exigir MySQL isolado; 3.924 asserções). A conta do PHP (www-data) tem permissão de escrita no storage privado; banco, worker e scheduler estão saudáveis após recriação. git diff --check passou.
