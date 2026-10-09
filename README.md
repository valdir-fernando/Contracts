# Contracts

Base Laravel 13 com PHP 8.4, Nginx, MySQL 8.4 e Vite, em Docker para desenvolvimento local.

## Iniciar no Windows

Instale e abra o Docker Desktop com containers Linux. Na pasta do projeto, execute:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start.ps1
```

O script cria `.env` se necessário, constrói a imagem PHP, instala as dependências, gera a chave da aplicação apenas se estiver vazia, inicia o banco, executa as migrations e inicia Nginx e Vite.

Acesse http://127.0.0.1:8000. O endpoint de saúde é http://127.0.0.1:8000/up. Esse endereço evita o atraso de tentativa IPv6 de `localhost` quando as portas estão publicadas apenas em IPv4.

Por padrão, o script compila os assets e mantém o Vite desligado. Para editar CSS/JavaScript com recarregamento automático, execute `powershell -ExecutionPolicy Bypass -File .\scripts\start.ps1 -HotReload`. O serviço `node` usa o perfil `assets` e só inicia quando solicitado explicitamente.

## Comandos úteis

O dump legado e os dados locais não são versionados. Para importar dados reais, disponibilize o arquivo localmente em `database-example/u910323952_bdgestao_niq.sql`; os testes usam dados fictícios e não dependem desse arquivo. Os comandos de seed/importação legada exigem o dump, mas a inicialização do ambiente não o exige.

```powershell
docker compose up -d
docker compose down
docker compose logs -f
docker compose exec app php artisan migrate
docker compose exec app php artisan make:model Contract -m
docker compose exec app composer require vendor/package
docker compose run --rm --no-deps node npm run build
```

O MySQL usa um volume persistente e fica acessível aos containers em `db:3306`. As credenciais locais estão em `.env.example`. Alterar as credenciais após criar o volume também exige atualizar o usuário no banco.

Os arquivos da aplicação são montados nos containers; alterações em PHP são aplicadas diretamente e o Vite recarrega os assets. `docker compose down` preserva o banco. Após interromper o Vite, remova `public/hot` para usar os assets de um build estático.

## Desempenho no Windows

O código permanece montado a partir do Windows. Dependências PHP (`vendor`), arquivos de execução (`storage`) e cache do bootstrap usam volumes Linux (`php_vendor`, `app_storage`, `app_cache`) para evitar milhares de acessos ao disco do Windows em cada requisição. Instale dependências PHP pelo container, usando `docker compose exec app composer install`; a pasta `vendor` do Windows não é sincronizada com o volume. Logs e sessões ativos também ficam no volume: consulte-os com `docker compose exec app sh`.

O script de inicialização prepara esses volumes. `docker compose down` os preserva; `docker compose down -v` apaga também sessões, caches, dependências e banco. As mudanças no PHP continuam sendo detectadas, com verificação do OPcache a cada segundo. Alterações em `docker/php/local.ini` exigem reiniciar o serviço `app`.

Para repetir o diagnóstico de leitura de arquivos, inicialização do Laravel e consulta ao banco:

```powershell
docker compose exec -T app php scripts/diagnose-performance.php
curl.exe -s -o NUL -w "TTFB=%{time_starttransfer} total=%{time_total}\n" http://127.0.0.1:8000/login
```

Documentação: https://laravel.com/docs/13.x
