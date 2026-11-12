# Painel global de acompanhamento de atas — 09/11/2026

O botão **Nova ATA** abre `GET /atas/create` (`atas.create`), com cabeçalho no padrão de Novo contrato: **FUNDO GERENCIADOR**, **Nome / Razão Social** e campo **Nº ATA** com lupa. No card, **Fundo Gerenciador** lista todos os Fundos ativos, em ordem alfabética, do catálogo `contract_funds` importado de `tb_Fundo`, inclusive de outros escopos, conforme a consulta global de atas. Somente ID e nome são projetados nas opções. **Voltar** retorna ao painel de atas. Fundo e fornecedor do cabeçalho são textos iniciais; a lupa permanece sem ação. A página mantém autenticação, usuário ativo e validação do Fundo da sessão pelos middlewares existentes, além de revalidar o ator no controller.

Após o card de Fundo Gerenciador, as abas **1. Dados Processuais**, **2. Dados Gerenciador** e **3. Dados do Fornecedor** alternam painéis, com Dados Processuais aberta por padrão. O seletor de Fundo permanece visível nas três abas. Dados Processuais contém **Processo ATA**, **Licitação**, **Nº Licit** e o seletor **Fundos Participantes**. Este inclui o gerenciador selecionado e exclui somente os Fundos já adicionados; trocar o gerenciador preserva a seleção participante. Os painéis de gerenciador e fornecedor consultam os cadastros autorizados, conforme descrito abaixo.

## Cadastro de participantes antes de salvar a ata — 11/11/2026

**Adicionar** envia `POST /atas/participants` (`atas.participants.store`), com CSRF, autenticação e limitação de frequência. Exige Processo ATA, gerenciador e participante ativos, que podem ser o mesmo Fundo. O servidor obtém nome/CNPJ do catálogo atual e exige CNPJ válido; ignora autoria, snapshots, número da ata e tipo enviados pelo cliente. `Infrastructure/Atas/AtaParticipants` bloqueia/revalida o ator antes do rascunho e bloqueia Fundos por ID crescente, verifica o Fundo da sessão, a versão e o proprietário do rascunho. O módulo de atas permanece global para usuários ativos, mas um usuário não acessa rascunhos de outros usuários.

A migration `2026_11_11_000001_create_ata_participants.php` cria somente `ata_participant_drafts` e `tb_Participantes`. A primeira mantém UUID, autor, gerenciador, processo e versão. A segunda contém **ATA** (nullable), **Processo_ATA**, **CNPJ_Fundo**, **FUNDO**, **Tipo_Fundo**, além de ID, vínculos de rascunho/Fundo, timestamps e soft delete. Os índices únicos de rascunho/Fundo e rascunho/CNPJ impedem duplicação. A operação registra `ActivityEvent.action=ata_participant_added` na mesma transação; falha de auditoria reverte participantes, contexto e versão. Nenhuma tabela ou registro anterior foi substituído.

`Tipo_Fundo` é derivado no servidor na inclusão: recebe **Gerenciador** quando o Fundo adicionado é igual ao Fundo Gerenciador, ou **Participante** quando é diferente e `ATA` permanece `NULL`, mesmo que o campo de número esteja preenchido. A futura gravação da ata deverá preencher esse número usando o vínculo de rascunho, nunca somente Processo ATA. Até lá, o botão não cria `tb_ATA` nem consome numeração. Processo e gerenciador são salvos com cada inclusão; mudar o processo e adicionar outro participante atualiza os participantes anteriores do mesmo rascunho, com contexto anterior/atual na auditoria. Um Fundo já participante não pode passar a gerenciador por essa operação. Licitação e Nº Licit continuam locais até a implementação da gravação da ata.

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

## Nova ATA: apresentação e preenchimento — 11/11/2026

A lista de participantes tem grade completa: ATA 90 px, Processo ATA 120 px, CNPJ do Fundo 170 px, Tipo de Fundo 200 px e Fundo no espaço restante. CNPJ recebe máscara somente na apresentação. Cada linha tem Excluir em Tipo de Fundo; a tabela mantém rolagem horizontal em telas estreitas.

`DELETE /atas/participants/{participant}` exige autenticação, CSRF, token e versão do rascunho. Bloqueia/revalida ator antes do rascunho, verifica sessão, autoria e vínculo, aplica exclusão lógica e incrementa versão com `ata_participant_removed` na mesma transação. Conflitos não expõem dados atuais. Nova inclusão do mesmo Fundo reativa sua linha com snapshots atuais e auditoria; eventos anteriores permanecem preservados.

Processo ATA, Licitação, Nº Licit e Fundos Participantes/Adicionar compartilham a linha com espaçamento padrão. Fundo Gerenciador termina na borda direita de Nº Licit; Processo ATA acompanha a borda da primeira aba. Data_ATA fica ao lado do gerenciador, com máscara e calendário. Datas impossíveis são recusadas; sábados/domingos abrem aviso padrão com Sim, manter data ou Não, corrigir data. Escape equivale a recusar; a confirmação vale somente para a data escolhida. Não há calendário de feriados configurado. Data, licitação e dados de gerenciador/fornecedor permanecem locais até a futura gravação da ata.

Dados Gerenciador apresenta CNPJ e Endereço na primeira linha; CPF do Gestor, RG do Gestor e Cargo na segunda; Nº Decreto e Matrícula Funcional abaixo. CPF, RG, decreto e matrícula têm a largura do CNPJ. Não há campo de nome do gestor nem campos de fiscais. `GET /atas/funds/{fund}/contractor-defaults` usa `Infrastructure/Atas/AtaContractor`, `SelectedFund`, `ManagerSignatory` e `LockActor`: exige acesso atual ao Fundo, projeta campos explícitos e restringe CPF/RG a administradores. As opções globais de atas não ampliam esse acesso. `ManagerSignatory::forFund` acrescenta Decreto/Matricula à projeção interna, preservando a seleção única e a regra do Executivo. A interface limpa valores anteriores e descarta respostas atrasadas.

Dados do Fornecedor reutiliza a busca por CPF/CNPJ e o catálogo de Novo contrato. CPF/CNPJ, RG, documento profissional e PIS/PASEP ficam juntos; Endereço e e-mail na linha seguinte; Sócio/representante, CPF e RG do sócio na mesma linha; Celular e WhatsApp abaixo. Campos cadastrais são somente para leitura; nome aparece no cabeçalho. Título e espaçamento seguem Dados Gerenciador. CPF/CNPJ e CPF do sócio usam máscara. Celular/WhatsApp usam `(62) 9 9996-0370`, sem modificar os dados armazenados; números ambíguos permanecem como vieram do cadastro.

Contatos são consultados por `POST /suppliers/lookup?ata_contacts=1`, usando autorização e escopos já existentes. A opção apenas solicita a projeção; não concede permissões. Somente usuários com acesso ao catálogo de fornecedores recebem `supplier_email`, `supplier_mobile` e `supplier_whatsapp`, provenientes de `suppliers.raw_payload` nas chaves `Email_Fornecedor`, `Fone_Celular` e `WhatsApp_Fornecedor`. Dados de sócios/contatos adicionais não são expostos. O parâmetro opcional de `SupplierCatalog::defaults` é falso por padrão; as respostas anteriores permanecem preservadas. Troca de fornecedor limpa contatos, aborta consultas e ignora respostas antigas.

Em toda a suíte, campos sem informação não mostram mais Preenchido automaticamente nem Preenchido pelo órgão selecionado. Avisos de erro, carregamento e restrição de acesso são preservados. Em Nova ATA, a confirmação textual de preenchimento do fornecedor foi suprimida; erros de consulta continuam visíveis.

Validação direcionada: PHPUnit `SupplierAutofillTest|AtaParticipantsTest|AtasOverviewTest` passou (33 testes/403 asserções, SQLite em memória). `ExecutiveSignatoryTest|ManagerRegistrationTest` passou (28 testes/221 asserções). Architecture (3 testes), Unit (86 testes), testes JavaScript direcionados, Pint e build Docker passaram. Prévia de grade em Chrome com dados sintéticos, sem gravação no banco; sem ensaio visual autenticado. Artefatos de build e imagem local de referência não integram os commits.
## Verificação antes dos commits e push — 11/11/2026

- `docker compose exec -T app composer check`: Pint aprovado (440 arquivos). PHPUnit interrompido pelo timeout padrão de 300 segundos do Composer, após a marca de 854/1.037 testes, com falhas/erros já sinalizados; sem resultado final da suíte PHP completa. Log local: `build/pre-push-check-20261111.log`.
- `npm.cmd test`: 158 testes, 157 aprovados e uma falha em `dfd-product-filter.test.mjs:66`, `window is not defined`. Reproduzida na cópia limpa de `79af701` extraída por `git archive`; logs locais `build/pre-push-js-20261111.log` e `build/pre-push-baseline-js.log`. A chamada inicial `npm test` no PowerShell foi bloqueada pela política de scripts; execução feita por `npm.cmd`.
- A falha de `SelectedFundTest::test_editing_a_contract_preserves_its_existing_manager` sobre ausência do rótulo Gestor do Fundo também foi reproduzida na cópia limpa de `79af701`, com PHPUnit/SQLite isolados. Log local: `build/pre-push-baseline-php.log`. As demais falhas da suíte completa não foram individualmente comparadas nesta etapa; não se afirma aprovação geral.
- `composer check:architecture`: 3 testes/4 asserções; `composer test:unit`: 86 testes/210 asserções. Aprovados. Build Docker final, verificações direcionadas descritas acima e `git diff --check` aprovados.
- Nenhum teste foi executado no banco instalado. A imagem `Reference/Tela Produtos Atual.jpg`, artefatos de `build/` e dados locais ficam fora dos commits. Sem deploy externo ou alterações nas asserções para ocultar as falhas gerais.