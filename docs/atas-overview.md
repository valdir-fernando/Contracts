# Painel global de acompanhamento de atas — 09/11/2026

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
