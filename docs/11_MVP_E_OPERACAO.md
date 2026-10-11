# MVP e operação

## Escopo entregue

A entrega completa o MVP funcional do backlog: autenticação e reset administrativo, usuários e escopos, contratos com os 80 campos, criação/edição auditada, exclusão/restauração, classificação administrativa separada da vigência, histórico legado de aditivos e itens, conciliação manual, relatórios e exportações PDF/XLSX/CSV, conta pessoal, painel Livewire, provisionamento inicial, fila, limpeza de arquivos, backup e imagem de produção.

A UI mantém os formulários Blade completos, com navegação por seções em Alpine integrado ao Livewire. O painel usa Livewire 4 para busca e paginação, com autorização e escopo em cada renderização. Não existe React nem segunda instalação de Alpine. O banco atual permanece MySQL 8.4, compatível com a camada Eloquent e com a leitura dos literais do dump MariaDB; migrar o servidor para MariaDB exige ensaio próprio. Redis não é obrigatório: fila em banco e cache em arquivos atendem à instalação atual.

A evolução P1 já inclui anexos privados, calendário, alertas internos configuráveis, pesquisa avançada, seleção assistida de fornecedores e requisitos configuráveis por tipo; veja [Evoluções P1](13_EVOLUCOES_P1.md). O [cadastro nativo de aditivos](14_ADITIVOS.md) inclui rascunhos, registro após conferência e exclusão/restauração auditada. A aplicação financeira e de vigência dos aditivos e ficha de dotação/empenhos permanecem pendentes de definição de regras. P2 inclui assinatura, integrações públicas, API e relatórios personalizados. Não se considera concluída a homologação da área nem um deploy em infraestrutura externa.

## Instalação local

Linux/macOS com Docker Compose e Bash:

```bash
./scripts/start.sh
docker compose exec app php artisan contracts:create-admin ADMIN --name="Administrador"
```

O segundo comando solicita senha e confirmação sem exibi-las. Configure fundos/secretarias em **Órgãos e permissões**, crie escopos e atribua-os aos usuários. Nenhum dump é necessário para começar.

No Windows, use `scripts/start.ps1` conforme o README. O script também inicia worker e scheduler. Acesse `http://127.0.0.1:8000`.

Para experimentar exclusivamente com dados fictícios:

```bash
docker compose exec app php artisan contracts:demo
docker compose exec app cat storage/app/private/demo-access.json
```

A demonstração só funciona em `APP_ENV=local`. As senhas são aleatórias, o arquivo é privado e não é versionado. Não use as contas de demonstração em produção.

## Uso diário

1. O administrador prepara órgãos e escopos e cadastra usuários. O reset de acesso fica em **Usuários → Editar → Redefinir senha**, sem depender de e-mail ou recuperar senhas antigas.
2. Cada usuário consulta e edita somente seus órgãos. **Minha conta** permite atualizar nome/e-mail e trocar senha mediante a senha atual. Trocar a senha invalida outras sessões.
3. No cadastro de contratos, use as sete seções ou mostre todas. A classificação administrativa é interna; vigência calculada não certifica situação jurídica.
4. Exclusão exige número (ou ID quando sem número), motivo e versão atual. Só administrador exclui/restaura. **Contratos excluídos** conserva vínculos e permite restaurar.
5. Em **Relatórios**, escolha período e semântica, referência, órgãos, tipo, modalidade e situação. O único indicador somado é **valor inicial**; acumulados e saldos não entram no total. Datas/valores sem conciliação são sinalizados. CPF fica mascarado por padrão; administrador precisa solicitar inclusão e justificar.
6. Exportações até 1.000 registros são imediatas. Acima disso, acompanhe **Minhas exportações**. Os arquivos ficam privados por 24 horas. Mudanças de permissões ou da relação de contratos acessíveis invalidam downloads anteriores. PDFs são gerados em blocos e reunidos com FPDI, mantendo paginação contínua. Worker e scheduler precisam estar ativos.
7. Administradores consultam **Auditoria e conciliação** para CRUD, acesso, exportações, importações, ocorrências e fila. A auditoria também é exportável em CSV/XLSX/PDF, com os filtros de ator, ação e período; diferenças brutas e documentos pessoais dos contratos não integram essa relação. Eventos não possuem endpoint de alteração/remoção.

## Importação e conciliação

```bash
docker compose exec app php artisan contracts:import --file=database-example/u910323952_bdgestao_niq.sql --dry-run
docker compose exec app php artisan contracts:import --file=database-example/u910323952_bdgestao_niq.sql
docker compose exec app php artisan contracts:import-history database-example/u910323952_bdgestao_niq.sql --dry-run
docker compose exec app php artisan contracts:import-history database-example/u910323952_bdgestao_niq.sql
```

O histórico preserva todos os campos brutos. A associação automática de aditivos exige número + documento do fornecedor + documento do fundo; itens exigem número + documento do fornecedor + fundo + processo. Múltiplos candidatos ficam ambíguos. Mudanças de origem preservam a versão importada e registram conflito. Não executar o SQL recebido: o parser lê apenas literais.

Conciliação manual registra ator e evidência e confirma apenas o vínculo. Ela não aplica valores, não prorroga contratos e não elimina a sinalização de revisão de vigência. A aplicação não inventa regras financeiras/jurídicas. O histórico importado pode ser consultado mesmo após exclusão/restauração.

## Backup e restauração

```bash
./scripts/backup.sh
./scripts/verify-backup.sh backups/contracts-ARQUIVO.tar.gz
```

O backup reúne snapshot InnoDB, storage e configuração/chave em arquivo local restrito, com SHA-256. Retenção operacional padrão: 30 dias, configurável por `BACKUP_RETENTION_DAYS`; a retenção documental precisa ser homologada separadamente. Copie os arquivos para armazenamento seguro externo conforme a operação da instituição.

O ensaio verifica checksums, restaura em schema novo, compara os INSERTs de todas as tabelas e recupera storage/configuração em diretório temporário. O schema temporário é removido ao final. Não modifica o banco aplicativo. Requer Python 3.12+ no host, pois a extração usa filtros de segurança do tarfile.

Para recuperação real em infraestrutura nova: pare tráfego e workers; restaure o SQL em banco vazio; recupere o diretório storage no volume; injete a chave e a configuração do backup; execute migrations da versão correspondente; valide `/ready`, acessos, contagens, vínculos e arquivos; então reabra tráfego. Nunca restaure com `migrate:fresh` sobre um banco de produção.

## Produção

Prepare `.env.production` a partir de `.env.production.example`, com chave própria, banco externo e URL HTTPS. Gere uma chave com `docker compose exec app php artisan key:generate --show` e transfira-a para o segredo de produção; o comando apenas mostra a nova chave e não modifica a instalação local. Esse arquivo não é versionado nem copiado para a imagem. Compile a base PHP e as imagens imutáveis:

```bash
docker compose build app
APP_VERSION=release-1 docker compose --env-file .env.production -f compose.production.yaml build
APP_VERSION=release-1 docker compose --env-file .env.production -f compose.production.yaml run --rm app php artisan migrate --force
APP_VERSION=release-1 docker compose --env-file .env.production -f compose.production.yaml up -d
```

O proxy TLS deve publicar a porta interna `127.0.0.1:8080`, encaminhar o Host público e impedir acesso externo direto ao backend. A aplicação gera URLs HTTPS em produção. Cookies são Secure/HttpOnly/SameSite=Lax em produção, `APP_DEBUG=false`, sem segredo dentro da imagem. Não se deve considerar TLS testado numa infraestrutura ainda não configurada. Configure HSTS no proxy que termina TLS; a aplicação também o aplica a requisições identificadas como HTTPS.

A imagem não inclui dependências de desenvolvimento; assets compilados fazem parte dela; OPcache não verifica alterações em produção. Recrie os containers a cada release. `/up` verifica bootstrap e `/ready` consulta banco e tabela de fila. Monitore também processos worker/scheduler, falhas da fila e tempo de geração; retry_after (960s) excede o timeout do worker (900s).

Os scripts de backup/restauração fornecidos operam o banco do Compose local. Para o banco externo de produção, configure a rotina do provedor/operador e ensaie a recuperação com o mesmo conteúdo (banco, storage e chave).

Antes de promover uma versão: faça backup, teste a restauração isolada, execute CI e valide a amostra histórica com a área. Rollback de código não reverte migrations automaticamente; mantenha migrations compatíveis e restore separado para recuperação.

## Verificação e limites de aceite

Os testes usam dados fictícios, incluindo datas zeradas, moeda BR, duplicidades, concorrência, rollback da auditoria, escopos, mascaramento, exportações, fórmulas em CSV/XLSX, Livewire e história ambígua. A suíte inclui 0, 1, 100 e 2.145 registros nos testes de relatório. O arquivo de CI executa testes em SQLite e MySQL isolado, formatação e build.

O script `scripts/browser-smoke.cjs` usa Playwright 1.58.2 e acessos gerados pelo comando de demonstração. Ele cria/edita/exclui/restaura um contrato fictício, testa downloads e isolamento do usuário e verifica largura móvel. Para repetir com o ambiente já iniciado:

```bash
docker compose exec app php artisan contracts:demo
docker cp contracts-app-1:/var/www/html/storage/app/private/demo-access.json /tmp/contracts-access.json
chmod 600 /tmp/contracts-access.json
docker run --rm --network container:contracts-web-1 \
  -v "$PWD/scripts/browser-smoke.cjs:/tmp/test/smoke.cjs:ro" \
  -v /tmp/contracts-access.json:/tmp/access.json:ro \
  -e CONTRACTS_URL=http://127.0.0.1 \
  mcr.microsoft.com/playwright:v1.58.2-noble \
  bash -c 'cd /tmp/test && npm install --no-audit --no-fund playwright@1.58.2 && node smoke.cjs'
rm /tmp/contracts-access.json
```

O nome dos containers corresponde ao projeto Compose padrão. Os contratos de smoke ficam identificados por `SMOKE-` e são fictícios.

Dependências consultadas: [Livewire 4](https://livewire.laravel.com/docs/4.x/installation), [Dompdf](https://github.com/dompdf/dompdf), [PhpSpreadsheet](https://phpspreadsheet.readthedocs.io/en/latest/), [FPDI](https://manuals.setasign.com/fpdi-manual/v2/the-fpdi-class/examples/).

Limites externos do aceite: dados reais do dump não estão no checkout; regras marcadas [PENDENTE], retenção institucional e correspondência com documentos oficiais dependem da área; domínio/TLS, segredos, destino de backup e publicação dependem da infraestrutura escolhida. Esses itens não são substituídos por testes sintéticos.
