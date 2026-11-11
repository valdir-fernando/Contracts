# Painel global de acompanhamento de atas — 09/11/2026

O botão **Nova ATA** abre `GET /atas/create` (`atas.create`), com cabeçalho no padrão de Novo contrato: **FUNDO GERENCIADOR**, **Nome / Razão Social** e campo **Nº ATA** com lupa. No card, **Fundo Gerenciador** lista todos os Fundos ativos, em ordem alfabética, do catálogo `contract_funds` importado de `tb_Fundo`, inclusive de outros escopos, conforme a consulta global de atas. Somente ID e nome são projetados nas opções. **Voltar** retorna ao painel de atas. Fundo e fornecedor do cabeçalho são textos iniciais; a lupa permanece sem ação. A página mantém autenticação, usuário ativo e validação do Fundo da sessão pelos middlewares existentes, além de revalidar o ator no controller.

Após o card de Fundo Gerenciador, as abas **1. Dados Processuais** e **2. Dados do Fornecedor** alternam painéis, com Dados Processuais aberta por padrão. O seletor de Fundo permanece visível nas duas abas. Dados Processuais contém **Processo ATA**, **Licitação**, **Nº Licit** e o seletor **Fundos Participantes**. Este exclui o gerenciador selecionado e os participantes já adicionados; trocar o gerenciador limpa uma seleção participante que coincida com o novo gerenciador. Dados do Fornecedor permanece vazia.

## Cadastro de participantes antes de salvar a ata — 11/11/2026

**Adicionar** envia `POST /atas/participants` (`atas.participants.store`), com CSRF, autenticação e limitação de frequência. Exige Processo ATA, gerenciador e participante ativos distintos. O servidor obtém nome/CNPJ do catálogo atual e exige CNPJ válido; ignora autoria, snapshots, número da ata e tipo enviados pelo cliente. `Infrastructure/Atas/AtaParticipants` bloqueia/revalida o ator antes do rascunho e bloqueia Fundos por ID crescente, verifica o Fundo da sessão, a versão e o proprietário do rascunho. O módulo de atas permanece global para usuários ativos, mas um usuário não acessa rascunhos de outros usuários.

A migration `2026_11_11_000001_create_ata_participants.php` cria somente `ata_participant_drafts` e `tb_Participantes`. A primeira mantém UUID, autor, gerenciador, processo e versão. A segunda contém **ATA** (nullable), **Processo_ATA**, **CNPJ_Fundo**, **FUNDO**, **Tipo_Fundo**, além de ID, vínculos de rascunho/Fundo, timestamps e soft delete. Os índices únicos de rascunho/Fundo e rascunho/CNPJ impedem duplicação. A operação registra `ActivityEvent.action=ata_participant_added` na mesma transação; falha de auditoria reverte participantes, contexto e versão. Nenhuma tabela ou registro anterior foi substituído.

`Tipo_Fundo` recebe sempre **Participante** e `ATA` permanece `NULL`, mesmo que o campo de número esteja preenchido. A futura gravação da ata deverá preencher esse número usando o vínculo de rascunho, nunca somente Processo ATA. Até lá, o botão não cria `tb_ATA` nem consome numeração. Processo e gerenciador são salvos com cada inclusão; mudar o processo e adicionar outro participante atualiza os participantes anteriores do mesmo rascunho, com contexto anterior/atual na auditoria. Um Fundo já participante não pode passar a gerenciador por essa operação. Licitação e Nº Licit continuam locais até a implementação da gravação da ata.

Cada abertura de Nova ATA gera um token criptografado associado ao ator e à versão de sua sessão, sem escrever no banco. O navegador mantém esse token em `?draft=...` para recarregar o mesmo rascunho inclusive após falha de rede; um novo clique em Nova ATA inicia outro rascunho. Tokens de outros usuários, adulterados ou de sessões antigas recebem 403. O token não substitui identidade autenticada, revalidação do ator ou verificação de propriedade. A resposta JSON contém somente versão, contexto processual e projeção explícita dos participantes; conflitos de versão recebem 409 sem retornar dados atuais.

Instalação local executada: `docker compose exec -T app php artisan migrate --path=database/migrations/2026_11_11_000001_create_ata_participants.php --force` passou; somente a migration aditiva nova foi aplicada. Nenhum participante real foi inserido para validação.

Validação executada nesta entrega:

- `docker compose exec -T app php vendor/bin/phpunit --filter='AtaParticipantsTest|AtasOverviewTest|AtaReportsTest' --do-not-cache-result`: **37 testes, 368 asserções**, SQLite em memória e storage temporário; snapshots do catálogo, ATA nula, isolamento de usuários/abas, validações, duplicatas, versão, troca de processo e rollback de auditoria.
- `docker compose exec -T app composer check:architecture`: **3 testes, 4 asserções**; `composer test:unit`: **86 testes, 210 asserções**.
- `node --test tests/JavaScript/ata-process-fields.test.mjs`: **5 testes aprovados**; cobre POST com CSRF, respostas confirmadas, tratamento de erros, recuperação, cliques repetidos e filtros.
- Pint dos seis PHP envolvidos, build Docker e `git diff --check`: aprovados.
- `docker compose exec -T app composer check`: Pint geral aprovado (**439 arquivos**); PHPUnit interrompido pelo limite padrão de **300 segundos** do Composer antes de concluir os 1.031 testes, já com falhas sinalizadas. Sem resultado final da suíte PHP completa nesta entrega; a execução específica de atas acima concluiu sem falhas.
- `npm.cmd test`: **150 de 151 testes aprovados**. A falha isolada em `dfd-product-filter.test.mjs:66` (`window is not defined`, `resources/js/dfd-product-filter.js:180`) também ocorre ao executar somente esse arquivo; ambos os arquivos permanecem iguais ao HEAD. Nenhuma asserção foi enfraquecida.
- Conferência com Chrome/Playwright em 1440 e 500 pixels, usando HTML produzido por um teste SQLite e POST simulado: filtro do gerenciador, requisição com CSRF, inclusão na lista, escape dos nomes, retirada da opção adicionada e alternância de abas passaram. Artefatos locais em `build/ata-participants-browser/`; sem inclusão de dados reais ou ensaio autenticado contra a gravação real.

## Validações anteriores da apresentação

Validação dos campos processuais: `docker compose exec -T app php vendor/bin/phpunit --filter=AtasOverviewTest --do-not-cache-result` passou (7 testes, 89 asserções); `node --test tests/JavaScript/ata-process-fields.test.mjs` passou (2 testes, incluindo troca de gerenciador e limpeza do participante conflitante). Pint dos testes PHP, build Docker e `git diff --check` passaram. Sem ensaio em navegador; Playwright não está instalado no ambiente local.

Validação do seletor e abas: `docker compose exec -T app php vendor/bin/phpunit --filter=AtasOverviewTest --do-not-cache-result` passou (7 testes, 87 asserções, SQLite em memória e storage temporário). Pint dos testes e `git diff --check` passaram. A cobertura inclui fundos fora do escopo, ordem, exclusão lógica, escape e ausência de dados privados e gravações. A alternância das abas não foi conferida em navegador autenticado.

Validação do cabeçalho: `docker compose exec -T app php vendor/bin/phpunit --filter='AtasOverviewTest|AtaReportsTest' --do-not-cache-result` passou (28 testes, 286 asserções, SQLite em memória e storage temporário); Pint de `tests/Feature/AtasOverviewTest.php`, build Docker e `git diff --check` passaram. Sem conferência visual autenticada.

Validação dessa navegação: `docker compose exec -T app php vendor/bin/phpunit --filter=AtasOverviewTest --do-not-cache-result` passou (6 testes, 70 asserções, SQLite em memória e storage temporário); Pint de `routes/web.php` e `tests/Feature/AtasOverviewTest.php`, `docker compose run --rm --no-deps node npm run build` e `git diff --check` passaram. Sem execução da suíte completa ou conferência visual autenticada nesta alteração.

Produtos de aditivos são mantidos como histórico em `tb_PRODUTOS_ATA`, identificados por `ADITIVO`/`ID_ADITIVO`. O painel filtra itens originais (`ADITIVO=0` e `ID_ADITIVO IS NULL`), evitando duplicar valores nas prorrogações. [Importação dos produtos de aditivos](ata-amendment-products.md).

**Base consolidada após importar 2025:** 82 atas, 1.111 itens e R$ 33.568.681,74; na referência de 09/11/2026, 30 vigentes, 52 vencidas e nenhuma sem vigência definida. Selecionar **2025** ou **Todos os anos** para consultar o histórico. [Carga adicional, aditivos e pendências](atas-2025-import.md). Os números da primeira carga aparecem abaixo como histórico.

Em **Início → ATAS**, `/atas` consulta `tb_ATA` e `tb_PRODUTOS_ATA` e apresenta indicadores e gráficos dos registros importados. Conforme orientação do usuário, as atas são uma base **global**, independente do Fundo escolhido no login e dos órgãos gerenciadores/participantes de `tb_FUNDOS_ATA`. O cabeçalho identifica Atas de Registro de Preços, enquanto o painel de contratos conserva seu Fundo selecionado.

## Indicadores e consulta

- Quantidade de atas, itens e processos licitatórios no exercício escolhido.
- Contagens de vigentes e vencidas, gráfico com as quatro situações: vigente, vencida, a iniciar e sem vigência definida.
- Alertas conforme ativação e antecedência já salvas na conta do usuário, reconsultadas a cada atualização. O painel não altera as preferências.
- Próximos vencimentos, a partir de amanhã, agrupados no mês atual e nos cinco seguintes.
- Valor registrado, calculado pela soma decimal exata dos `VL_TOTAL` dos itens ativos das atas ativas. Cada item participa uma vez; a quantidade de órgãos participantes não multiplica valores. Não representa consumo, saldo ou rateio.

O filtro de exercício usa o ano do **número da ata**, aceitando ano com dois ou quatro dígitos e espaços legados. Não utiliza `DATA_ATA`, pois há documentos de numeração 2026 com datas escritas de 2025. O ano atual é o padrão; a opção Todos os anos reúne a base ativa. Números sem ano reconhecido aparecem apenas em Todos os anos. O seletor inclui os exercícios documentais até o atual.

Por solicitação do usuário, a lista do relatório de atas foi retirada do painel em 09/11/2026, junto da pesquisa, paginação visível e botão Consultar atas. Indicadores, gráficos, alertas e filtro de ano permanecem. A consulta interna conserva sua assinatura e projeções por compatibilidade; não houve alteração de registros ou de autorização.

Validação da retirada: `docker compose exec -T app php artisan test --filter='AtasOverviewTest|AtasOverviewDataTest' --compact` passou com **11 testes e 114 asserções**, usando SQLite em memória. As verificações passaram a conferir o filtro de ano pelos valores agregados e a proteção/escape de fornecedores pelos alertas; busca/paginação internas continuam verificadas sem depender da lista removida. Pint dos dois arquivos de testes, `docker compose run --rm --no-deps node npm run build` e `git diff --check` aprovados. A suíte geral não foi repetida nesta mudança de apresentação.

## Vigência

`Domain/Atas/AtaValidity` recebe início, término e data de referência explícita. Regras documentais do painel:

| Situação | Critério |
| --- | --- |
| Sem vigência definida | Falta início ou término, ou término anterior ao início |
| Vencida | Intervalo válido com término até a data de referência, inclusive |
| A iniciar | Intervalo válido com início posterior à referência |
| Vigente | Intervalo válido iniciado, com término posterior à referência |

Os términos até hoje seguem o mesmo limite civil usado pelos indicadores atuais de contratos. Atas com datas ausentes/incoerentes são contadas e exibidas explicitamente como sem vigência definida. Alertas e próximos vencimentos utilizam apenas intervalos conhecidos ainda a vencer. A renderização lê as datas salvas; o preenchimento por data da ata/período/aditivo é manutenção explícita pela [regra posteriormente solicitada pelo usuário](ata-validity-update.md).

A consulta inicial somente leitura ao MySQL local confirmou **25 atas, 222 itens, quatro processos e R$ 3.088.516,53**, inicialmente sem datas. Após a [atualização autorizada da vigência](ata-validity-update.md), os totais foram preservados e a referência de 09/11/2026 passou a mostrar **2 vigentes, 23 vencidas e zero sem vigência definida**.

## Autorização e fronteiras

`Infrastructure/Atas/AtasOverviewQuery` revalida usuário ativo e versão atual de sessão por `LockActor::current` a cada renderização. Qualquer usuário autenticado/ativo pode consultar a base global de atas, inclusive sem escopos de Fundo; essa regra foi solicitada explicitamente para este módulo. Os controles de sessão existentes da suíte permanecem: uma sessão cujo Fundo foi revogado continua sendo encerrada pelo middleware, e perfis inativos/sessões antigas são recusados.

Somente campos necessários são selecionados/projetados. CPF/RG, endereços, contatos, representantes, arquivos de origem, hashes e pendências internas não integram HTML, alertas ou itens de paginação. Saídas textuais são escapadas pelo Blade e as respostas autenticadas mantêm `private, no-store`.

Não há consulta a contratos para compor indicadores, nem alterações no banco ou auditorias de leitura. A camada Application permanece intacta. A classificação temporal fica em PHP puro no Domain; consultas, agregação decimal, projeções e revalidação ficam em Infrastructure; Livewire trata filtros e paginação. O formatador monetário existente é reutilizado apenas na apresentação.

## Validação executada

- `docker compose exec -T app php artisan test --filter='AtaValidityTest|AtaNumberingTest|AtaReferenceImportTest|AtasOverviewTest|AtasOverviewDataTest' --compact`: **37 testes, 225 asserções aprovados**, usando SQLite em memória e storage temporário. Inclui globalidade entre Fundos, ausência de dupla contagem por participantes, exclusões lógicas de atas/itens, precisão decimal, quatro classificações, limite de término hoje, alertas, exercício pelo número, pesquisa/paginação, validação de filtros, sessão revogada, escape de HTML e proteção de campos internos.
- `docker compose exec -T app composer check:architecture`: **3 testes, 4 asserções aprovados**.
- `docker compose exec -T app composer test:unit`: **83 testes, 202 asserções aprovados**.
- Pint dos seis PHP envolvidos: aprovado.
- `docker compose run --rm --no-deps node npm run build`: aprovado, inclusive após os ajustes de CSS.
- `git diff --check`: aprovado.
- Conferência visual com Chrome headless em 1440×1500 e 500×1800, sobre HTML fictício gerado pelos testes. Ajustados alinhamento do seletor de ano, largura de pesquisa e identificação global no cabeçalho. Os artefatos ficam em `build/atas-overview-preview/`, ignorado pelo Git. O servidor temporário de prévia foi encerrado; os scripts Livewire são removidos somente dessa prévia estática. As interações de filtros/paginação foram verificadas pelos testes Livewire, não por um ensaio autenticado no navegador.

Para gerar os HTML de prévia: `docker compose exec -T -e ATAS_PREVIEW_DIR=/tmp/atas-overview-preview app php artisan test --filter=AtasOverviewDataTest --compact`; os arquivos `global.html` e `statuses.html` foram copiados individualmente com `docker compose cp` para a pasta de prévia local. Uma consulta CLI somente leitura conferiu os indicadores reais sem executar PHPUnit no banco operacional.

Não foram necessárias migrations ou alterações dos documentos importados. A suíte geral não foi repetida: a [validação anterior](atas-reference-import.md#validação) registra falhas em outros testes de contratos e o limite de 300 segundos do Composer. Sem commit, publicação ou deploy externo.
