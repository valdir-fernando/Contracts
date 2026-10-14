# Contracts

Banco de desenvolvimento na Hostinger: [criação das tabelas, conexão remota, importação e preparação da hospedagem](docs/hostinger-development.md). A configuração opcional `compose.hostinger-dev.yaml` preserva o banco local e usa storage/cache separados.

Sistema de gestão de contratos com Laravel 13, Livewire 4/Alpine, Blade, Tailwind 4, PHP 8.4, Nginx, MySQL 8.4 e Vite.

O login exige selecionar um Fundo autorizado. O Início exibe esse Fundo, e novos contratos usam sugestões do Fundo e do gestor cadastrado na `tb_Gestor`. [Comportamento, instalação e validação](docs/login-fundo-2026-10-13.md).

O Início mostra os contratos do Fundo selecionado e do exercício atual por padrão. O seletor **Ano dos contratos** permite consultar exercícios anteriores ou **Todos os anos**, atualizando indicadores, gráficos, alertas e a lista recente. Use **Fundo selecionado → Alterar Fundo**, no topo das telas, para trocar entre fundos autorizados sem sair da sessão.

Novos contratos aplicam máscara de CPF/CNPJ e preenchem os dados do fornecedor ao sair do campo ou selecionar um cadastro, usando a `tb_Fornecedor` importada e respeitando permissões. [Comportamento e validação](docs/supplier-autofill-2026-10-13.md).

A numeração de novos contratos é automática por Fundo e ano, atribuída ao salvar: `001/26` até `999/26`, continuando com `1000/26`. Identificação completa guarda a sequência sem máscara. A virada do ano inicia um contador independente, e gravação/auditoria/contador são transacionais. [Instalação e validação](docs/contract-numbering-2026-10-13.md).

Auditoria incremental: [correções de segurança, concorrência e exportações — 12/10/2026](docs/technical-audit-2026-10-12.md). Cadastros potencialmente duplicados agora pedem confirmação antes da gravação. Exportações anteriores à atualização do hash precisam ser solicitadas novamente.

Verificação posterior: [sete achados](docs/audit-findings-2026-10-12.md) e [correções e validação](docs/audit-corrections-2026-10-12.md). Campos protegidos permanecem intactos com entradas vazias; importações históricas alteram versão e auditoria; administração de usuários detecta formulários antigos; exportações verificam integridade e encerram gerações abandonadas.

Desenvolvimento: [arquitetura e fronteiras](docs/architecture.md), [evolução do núcleo de contratos](docs/clean-architecture-development.md) e [regras do repositório](AGENTS.md). `composer check` executa Pint e todas as suites; `composer check:architecture` verifica as dependências entre camadas; `composer test:unit` testa regras e casos de uso sem Laravel/banco.

Login, usuários e escopos, cadastro/edição dos 80 campos, exclusão/restauração, histórico de aditivos/itens, conciliação, relatórios PDF/XLSX/CSV, painel, auditoria, conta pessoal, fila, backup e imagens de produção. Guia: [MVP e operação](docs/11_MVP_E_OPERACAO.md). Evoluções: [documentos privados, calendário, alertas e pesquisa avançada](docs/13_EVOLUCOES_P1.md) e [cadastro de aditivos](docs/14_ADITIVOS.md). [Estado final e pendências externas](docs/17_ESTADO_FINAL_E_PENDENCIAS.md): dotação/fichas/empenhos, conferência administrativa, personalização de relatórios, gráficos, API interna, importação periódica e visual refinado.

## Iniciar no Linux/macOS

```bash
./scripts/start.sh
docker compose exec app php artisan contracts:create-admin ADMIN
```

A senha inicial é solicitada de forma oculta. Configure órgãos e escopos na aplicação; o dump legado é opcional.

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

A [revisão de segurança, CI e confiabilidade](docs/security-hardening-2026-10-12.md) registra o endurecimento de autenticação, CSP parcial, isolamento da verificação de backup, auditorias de dependências, análise estática e cobertura reproduzível.
