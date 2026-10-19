# Cadastro de produtos nos contratos

Atualização de 19/10/2026: **Ficha** é uma seleção editável que já recebe as fichas dos produtos do contrato ao carregar a tela. É possível selecionar uma existente ou digitar uma nova no mesmo campo; salvar um produto nessa ficha inclui o valor na lista. A seleção filtra **Produtos do contrato**. Não existe controle separado Nova ficha. Este comportamento substitui as instruções anteriores de limpeza da ficha ao clicar em Novo/Salvar. [Operação, contrato HTTP e testes](product-ficha-selection-2026-10-19.md).

O painel **6. Cad.Prod** segue a referência `Reference/Cad_Produto.jpg`: ficha/descrição no topo, item/código/marca/unidade/quantidade/preço/total, botões Salvar produto/Novo/Excluir e lista dos produtos lançados. A integração com IA foi retirada por solicitação do usuário. Não há upload de documentos, chamadas externas, chave de API ou cadastro do catálogo nesta tela.

## Operação

1. Salve o contrato com processo e fornecedor. Informe o número no campo **Item** e saia do campo (ou pressione Enter) para carregar Código, Marca, UND, descrição e VL. Unitário.
2. **Exibir produtos do processo** desmarcado exige processo e CPF/CNPJ do fornecedor correspondentes. Se o item existir nesse processo para outro fornecedor, não carrega os dados e informa que o item não pertence ao fornecedor. Marcado libera todos os itens do processo, independentemente do fornecedor de origem, para casos como credenciamento de merenda escolar. A lista e sua busca por item/código/descrição seguem a mesma opção. Autorizar o ator no contrato continua obrigatório nos dois modos.
3. Digite, opcionalmente, a **Ficha** em campo de texto livre (até 64 caracteres, largura visual para cerca de 10). Ao perder o foco, a tela busca **somente a Ficha** na `tb_Dotacao`, preservando zeros à esquerda. Se Fundo e Secretaria corresponderem aos da tela, inclusive pelas equivalências descritas abaixo, preenche diretamente **Descrição** e **Dotação**. Se diferirem, mostra os dados e pede **OK** para preencher ou **Cancelar** para manter os dois campos vazios. Não bloqueia a busca por diferença de órgão e não cria vínculo financeiro. Se existirem linhas repetidas para a ficha, usa a de menor `ID_Dotacao`; no catálogo local verificado não havia fichas repetidas.
4. Informe a quantidade, confira ou edite **VL. Unitário** e use **Salvar produto**. O preço começa com o valor do catálogo e é editável no formato `0,0000`; o servidor valida valor não negativo com até quatro casas decimais. Código, descrição, marca e unidade continuam provenientes do catálogo. O preço informado pertence ao lançamento do contrato e não altera o preço da `tb_Produtos`. Alterações recalculam o total da linha e do contrato e registram o preço anterior e o novo na auditoria da mesma transação. Clientes que omitem `unit_price` preservam o preço salvo na edição ou usam o catálogo na inclusão.
5. Para alterar quantidade ou ficha, use **Editar** na lista e **Salvar produto**. **Novo** limpa a seleção para outro lançamento; não cria produtos na tabela de origem.
6. Para excluir, edite o lançamento e use **Excluir / estornar**. O registro recebe soft delete; a quantidade retorna ao saldo e o valor é recalculado.

O valor passa a ser a soma dos produtos ativos após o primeiro lançamento, inclusive zero depois de todos estornados. O total aparece no painel, no campo fixo **Valor do contrato** e no financeiro. Aditivos, vigência, acumulados e empenhos não recebem efeitos automáticos. O quadro **Valores por ficha** é uma soma dos produtos, sem criar nem alterar lançamentos financeiros.

## Catálogo externo e persistência

O mecanismo externo deve preencher a `tb_Produtos` **do banco utilizado pelo Contracts**. Campos utilizados:

| Campo de origem | Uso |
| --- | --- |
| `Processo`, `CNPJ_Fornec` | Identificação exata do processo e do vencedor; documento com ou sem máscara |
| `Item` / `item`, `Cod_Prod` | Número do item e código do produto |
| `Discriminacao`, `Marca`, `Unidade` | Descrição, marca e unidade |
| `QTD` | Saldo atualmente disponível para distribuição |
| `VL_Unitario` | Preço unitário do catálogo |
| `ID_Produto` | Identificador opcional de origem, preservado; o vínculo interno usa `id` |

Quantidades/preços aceitam strings com ponto decimal ou notação brasileira (por exemplo `12,3456`). Valores inválidos aparecem como indisponíveis. O catálogo é paginado, 20 linhas por página. Itens zerados permanecem visíveis como indisponíveis, preservando a rastreabilidade; não são apagados automaticamente.

Na inclusão, `QTD` diminui; na alteração, aplica-se somente a diferença; no estorno, a quantidade volta. `VL_Total` da origem é atualizado para saldo × preço atual. `QTD_Acumulado` e `QTD_Autorizado` são preservados. O mecanismo externo não deve repor `QTD` com a quantidade original após existirem distribuições; precisa respeitar o saldo e transações/locks do banco.

O mesmo item pode ser distribuído entre contratos do FMS e Executivo: cada contrato exige autorização própria e o mesmo processo; no modo comum também exige o mesmo fornecedor. Com **Exibir produtos do processo** ativo, fornecedores diferentes compartilham a mesma linha e saldo da `tb_Produtos`, sem copiar quantidades por fornecedor. Não há cópia de saldo por Fundo nem vínculos de importação a configurar. Contratos de processos diferentes não conseguem consumir o item em nenhum modo.

`tb_Produtos_Contratos` mantém `contract_id`, `product_id`, snapshots de descrição, código, marca, unidade e preço, quantidade, total, ficha e soft delete. Também espelha os campos legados `Contrato`, `Processo`, `CNPJ`, `FUNDO`, `Cod_Prod`, `Descricao`, `Discriminacao`, `Marca`, `UND`, `QTD`, `VL_UNIT` e `TOTAL_UNIT`. Mudanças posteriores no catálogo não reescrevem o histórico nem o preço dos itens já contratados. O backend recusa alterações do processo/fornecedor e sobrescrita do total em contratos sob gestão de produtos.

## Instalação e compatibilidade

```powershell
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan view:cache
docker compose run --rm --no-deps node npm run build
```

A migration `2026_10_16_000002_adapt_contract_products_for_existing_catalog` é aditiva: conserva as colunas operacionais da primeira implementação e acrescenta os campos do catálogo legado. Torna o agrupamento antigo opcional, permitindo inserir produtos somente com os campos legados. `Item` e `item` representam a mesma coluna em MySQL/SQLite. `ID_Produto` externo pode ser fornecido; o `id` interno é gerado pelo banco. Produtos preexistentes da primeira implementação têm origem e saldo copiados para os novos campos, sem exclusão de registros.

A migration anterior já aplicada permanece como histórico de schema; suas tabelas de importação/agrupamento não são usadas pelo fluxo atual. Os serviços, job, rotas e configurações de IA foram removidos. Não é necessário preencher chave/modelo nem executar fila para cadastrar produtos. O histórico legado de `contract_history_records` permanece separado e não entra automaticamente no saldo nem no total.

Uma base bruta do sistema antigo, com somente as tabelas originais e sem o schema Laravel do Contracts, exige conciliação: não se deve executar o dump sobre o banco da aplicação nem substituir tabelas existentes. Para importar os lançamentos de `tb_Produtos_Contratos`, use `contracts:import-contract-products --dry-run` e depois o mesmo comando sem a opção. A migration `2026_10_19_000001_track_imported_contract_products` adiciona a proveniência necessária. O vínculo exige número, Fundo, fornecedor e processo; não há nova baixa de saldo nem recálculo automático do contrato. [Procedimento e resultados](model-data-import-2026-10-19.md).

## Segurança e validação

Todas as mutações usam `ContractAccess::lockForSave`, ator antes do contrato, versão atual, bloqueio do lançamento e da linha de origem, validação contextual, persistência e auditoria inseparáveis. O lock da linha de origem serializa a distribuição por contratos diferentes. Quantidades e dinheiro são strings decimais exatas; Brick Math aplica HalfUp a quatro casas por linha e soma os totais. O preview e os totais por ficha no navegador usam BigInt, sem ponto flutuante.

Não há ability escolhida pelo cliente, consultas arbitrárias por fornecedor/processo nem snapshots completos em respostas. Listagens usam projeções explícitas e `private, no-store`. Por solicitação explícita do usuário, o catálogo de Fichas é consultado globalmente pelo número após reautorizar acesso ao contrato. `GET /contracts/{contract}/products/budget-lookup?ficha=...` projeta status, descrição, dotação, nomes de Fundo/Secretaria e `scope_matches`. O contexto da tela serve somente ao aviso, sem conceder permissões nem alterar o contrato. As mutações e listagens de produtos continuam restritas ao contrato autorizado. O parâmetro antigo `budget_id` permanece compatível e exige uma dotação ativa do mesmo contrato. Soft delete preserva as exclusões e a auditoria garante que falhas revertam saldo, lançamento, total e versão.

## Busca por Item e catálogo compartilhado do processo

`GET /contracts/{contract}/products/item-lookup?item=...&process_catalog=0|1` usa somente o processo do contrato salvo. O cliente não escolhe processo ou fornecedor arbitrários. Sem o modo de processo, o item deve pertencer ao fornecedor atual; com ele, o fornecedor da origem não é filtro. O parâmetro é uma opção funcional validada, não uma ability/policy. Ator atual, sessão, policy de edição e escopos do contrato continuam verificados.

Se houver mais de uma linha elegível para o mesmo número de item, o sistema não escolhe uma origem arbitrária: pede seleção na lista. Cada linha mantém seu saldo próprio; não se soma artificialmente a quantidade de registros distintos. Saldo zerado/inválido não carrega os campos. Quantidade, preço, casts, integridade do snapshot e limite de saldo continuam validados no servidor.

Inclusões/alterações recebem `process_catalog` booleano e revalidam o processo, fornecedor quando aplicável e saldo sob lock, sem confiar na consulta anterior. A migration aditiva `2026_10_16_000004_record_product_catalog_mode` registra o modo em cada lançamento, com padrão falso para os existentes. O modo aparece na auditoria e é restaurado ao editar; estorno usa o modo gravado, devolvendo a quantidade à mesma origem mesmo para fornecedores diferentes. O contrato continua guardando seu fornecedor como destinatário do produto.

Editar o número do Item ou mudar o modo limpa a seleção e invalida consultas antigas. Novo prepara outro lançamento sem ativar automaticamente o modo de processo. A Ficha digitada permanece ao consultar outro item. Durante edição de um lançamento, o Item é somente leitura para preservar a origem; trocar de produto exige novo lançamento/estorno.

Validação desta alteração: `docker compose exec -T app php artisan test --filter=ContractProductsTest` passou com 20 testes e 200 asserções, incluindo fornecedor divergente, liberação pelo modo de processo, saldo único compartilhado por fornecedores distintos, edição/estorno pelo modo gravado, processo divergente, ator sem acesso, booleano inválido e item ambíguo/zerado. `composer check:architecture` passou com 3 testes; `npm test` passou com 42 testes; build e compilação Blade passaram. O teste de navegador com HTTP simulado passou para digitação de Item, campos vazios ao recusar fornecedor, carregamento ao marcar a opção e envio do modo na gravação. Testes PHP usaram SQLite em memória.

A migration aditiva foi aplicada no MySQL local do Compose, sem migrations pendentes; `/up` respondeu HTTP 200. Nenhum produto ou contrato real foi criado ou alterado nos testes. O mecanismo externo de alimentação e a concorrência real em MySQL não foram testados nesta revisão.

Testes usam SQLite em memória e armazenamento temporário. Cobrem leitura de campos legados, saldo compartilhado entre Fundos, precisão, máscaras, versões antigas, fornecedor/processo incorretos, escopos, fichas de outro contrato, snapshots, paginação e rollback da auditoria. O teste de navegador usa o Blade real com HTTP simulado; não escreve na base instalada. Concorrência real MySQL e o mecanismo externo de alimentação não são exercitados por esses testes.

Comandos efetivamente executados nesta revisão:

- `docker compose exec -T app composer check:architecture`: 3 testes aprovados.
- `docker compose exec -T app composer test:unit`: 57 testes aprovados.
- `docker compose exec -T app php artisan test --filter=ContractProductsTest`: 9 testes, 93 asserções, aprovados. A preparação das fichas foi corrigida para fornecer os campos obrigatórios do schema financeiro; nenhuma asserção de segurança foi removida.
- `docker compose exec -T app composer check`: Pint aprovado; 375 testes, 374 aprovados e 1 ignorado (concorrência MySQL exige schema descartável explícito), 3.441 asserções.
- `docker compose run --rm --no-deps node npm test`: 42 testes aprovados.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `docker compose exec -T app php artisan view:cache`: aprovado.
- `node scripts/browser-contract-products.cjs`: seleção do catálogo, preview exato, ficha, inclusão/edição/estorno, totais/versão e renderização segura aprovados em Edge headless. O HTML foi gerado por `scripts/render-contract-products-fixture.php`, com Playwright instalado em diretório temporário. As requisições foram simuladas.
- `docker compose exec -T app php artisan migrate --force`: migration aditiva aplicada no banco local `db` do Compose; sem migrations pendentes.
- `docker compose exec -T app php artisan contracts:check-operations`: banco/fila acessíveis, worker/scheduler ativos e zero jobs com falha.
- `git diff --check`: aprovado; `/up` respondeu HTTP 200.

A consulta somente de contagem confirmou zero registros nas duas tabelas de produtos do ambiente local. Nenhum produto ou contrato real foi cadastrado para testar. Para usar a tela com dados locais, o mecanismo externo deve primeiro alimentar `tb_Produtos`. Não houve commit, publicação, chamada a IA ou teste destrutivo no banco instalado.

Para repetir o teste visual, configure `CONTRACTS_PRODUCTS_FIXTURE` com o caminho do HTML gerado por `docker compose exec -T app php scripts/render-contract-products-fixture.php`, salvo em UTF-8. Disponibilize Playwright via `NODE_PATH` e execute `node scripts/browser-contract-products.cjs`. `CONTRACTS_BROWSER_EXECUTABLE` permite usar o Edge/Chromium instalado, e `CONTRACTS_PRODUCTS_SCREENSHOT` define um caminho opcional para captura.

Revisão do campo Ficha: `php artisan test --filter=ContractProductsTest` passou com 10 testes e 104 asserções, incluindo ficha sem cadastro, alteração, preservação quando omitida, limpeza e limite de tamanho. Pint, compilação Blade, build e teste de navegador também foram executados nesta revisão. A suíte completa acima corresponde à revisão anterior do painel.

## Catálogo de dotações

A migration `2026_10_16_000003_create_budget_catalog` cria `tb_Dotacao` somente se ela não existir, com os 15 campos do dicionário legado. Não substitui tabelas existentes. Para carregar o catálogo da fonte local:

```powershell
docker compose exec -T app php artisan contracts:import-budgets
```

O comando aceita também um caminho SQL explícito, lê apenas os literais de `tb_Dotacao` via `SqlDumpReader` e não executa SQL do dump. A importação usa transação, `ImportRun`, `LegacyRecord` e evento de sistema com `actor_id=null`. Registros iguais são ignorados; divergentes são registrados como conflito e preservados. Não altera contratos, fichas financeiras ou produtos.

Editar/limpar Ficha limpa imediatamente os dois campos de consulta; sair do campo dispara uma nova busca. Requests anteriores são cancelados e respostas antigas descartadas. Sem correspondência, duplicidade ou falha de rede, a tela apresenta aviso e não escolhe um registro arbitrário. Descrição e Dotação são somente leitura e não são enviadas como atributos confiáveis na gravação do produto.

Validação da consulta por Ficha: `php artisan test --filter=ContractProductsTest` passou com 13 testes e 128 asserções; `composer check:architecture` passou com 3 testes; `npm test` passou com 42 testes. Build e compilação Blade passaram. O teste de navegador confirmou campos separados, consulta apenas após blur, limpeza ao editar, ficha ausente e cancelamento de resposta atrasada. Testes PHP usam SQLite em memória; requisições do navegador são simuladas.

No ambiente local, `php artisan migrate --force` criou a tabela ausente e `php artisan contracts:import-budgets` carregou 1.733 registros de `database-example/u910323952_bdgestao_niq.sql`, sem conflitos, com rastreabilidade e evento na mesma transação. A fonte não foi executada como SQL. Nenhum contrato ou produto foi modificado. Não há migrations pendentes e `/up` respondeu HTTP 200.

`docker compose exec -T app composer check` final: Pint aprovado, 379 testes (378 aprovados, 1 ignorado por exigir MySQL isolado), 3.476 asserções. Não houve testes destrutivos no banco instalado, commit ou publicação.

## Histórico da correção da ficha 20260288 — Urbanismo (substituído pela regra atual)

A fonte usa `SEC MUNICIPAL DE URBANISMO`, enquanto o cadastro usa `Secretaria Municipal de Urbanismo`. A consulta agora normaliza exclusivamente o prefixo `SEC MUNICIPAL`/`SEC. MUNICIPAL` para `SECRETARIA MUNICIPAL`, além da normalização existente de acentos, caixa e espaços. Não faz busca aproximada nem remove palavras do nome. Se mais de uma Secretaria cadastrada coincidir após essa equivalência, recusa por ambiguidade.

Quando o seletor Secretaria está habilitado, o navegador envia `department_id` na consulta e invalida a resposta anterior ao trocar a seleção. O backend exige contrato autorizado, Fundo da sessão atual igual ao do contrato e Município; revalida o escopo do ator para a Secretaria solicitada. O ID enviado apenas escolhe o contexto de consulta autorizado, sem conceder permissão nem alterar o contrato salvo. Sem parâmetro, permanece a Secretaria persistida. Uma seleção ainda não salva não recebe dados de outra Secretaria por inferência ou por snapshot.

Validação específica: `php artisan test --filter=ContractProductsTest` passou com 15 testes e 141 asserções; `composer check:architecture` passou. O teste de navegador passou incluindo envio do seletor e troca de Secretaria com limpeza/reconsulta. A consulta somente leitura ao catálogo local para Ficha `20260288`, Fundo ID 9 e Secretaria ID 18 retornou `found`, com Descrição e Dotação preenchidas. Não foi alterado nenhum cadastro, contrato ou registro da `tb_Dotacao` para corrigir o nome. Build aprovado; comandos executados via `docker compose`.

## Regra atual de confirmação

### Importação do catálogo de exemplo

`docker compose exec -T app php artisan contracts:import-products` lê exclusivamente os literais de `tb_Produtos`/`tb_produtos` em `database-example/u910323952_bdgestao_niq.sql` (ou caminho passado como argumento). Não executa SQL do arquivo nem importa contratos/distribuições. Preserva campos legados e IDs de origem, registra `ImportRun`, `LegacyRecord` e `ActivityEvent` na mesma transação. IDs existentes iguais são ignorados; diferenças são registradas como conflito sem sobrescrever, inclusive quando o saldo já foi consumido.

Execução local autorizada: 3.307 registros importados, zero conflitos, no MySQL Docker `db`. A conferência somente leitura confirmou 3.307 produtos e evento `product_catalog_imported`. O processo `2025000820` tem 63 produtos disponíveis, de dois fornecedores. Três registros de outros processos possuem quantidade/preço fora do formato aceito; os valores originais foram preservados, sem correção automática. O cadastro continua tratando esses itens como indisponíveis. Comandos de verificação: `php artisan test --filter=ContractProductsTest` passou com 25 testes e 266 asserções; `composer check:architecture` passou (3 testes); Pint passou após formatação; `git diff --check` passou. Testes usaram SQLite em memória, sem executar testes no banco importado. A suíte completa e build não foram repetidos nesta importação sem alterações de frontend.

### Seleção pela lupa

A lupa ao lado de Item abre uma segunda janela com os produtos da `tb_Produtos`. Reutiliza a consulta autorizada do contrato: processo e fornecedor no modo normal, somente processo quando **Exibir produtos do processo** está ativo. A lista mostra Item, Código, Produto, Marca, UND, Saldo e VL. Unitário; oferece paginação para percorrer todos os registros. Itens sem saldo ou com dados inválidos ficam indisponíveis. Clicar no número do item (também acessível por teclado) ou na linha disponível carrega o produto na janela anterior e fecha a seleção. Cancelar/Escape fecha sem alterar o lançamento. Consultas pendentes são canceladas ao fechar; respostas antigas ou de outra versão/modo não selecionam produtos. A digitação direta do Item mantém a consulta ao perder foco. Em lançamento manual ou edição de um item salvo, a lupa permanece desativada para preservar a origem.

Verificação desta revisão: navegador com HTTP simulado passou para seleção no modo fornecedor, seleção de outro fornecedor no modo processo, paginação e cancelamento preservando os campos. `php artisan test --filter=ContractProductsTest` passou com 23 testes e 252 asserções (SQLite em memória); build, compilação Blade e `git diff --check` passaram. A lista inline permanece removida; o catálogo fica disponível exclusivamente na janela da lupa.

### Lançamento manual

A opção discreta **Lançamento manual** libera Código, Marca, UND e descrição do objeto; VL. Unitário permanece editável com quatro casas. Não exige item do catálogo, usa Item `MANUAL` se omitido e começa com quantidade 1, que pode ser alterada. Descrição, unidade, quantidade positiva e preço não negativo são validados no servidor. Ficha é preservada ao ativar a opção. Salvar com sucesso e Novo limpam o lançamento, desativam a opção e restauram os campos de catálogo somente para leitura; erros de gravação preservam os dados para correção. Editar um lançamento manual libera seus campos novamente, preservando sua origem.

A migration `2026_10_16_000005_allow_manual_contract_products` permite `product_id` nulo em `tb_Produtos_Contratos`, preservando a chave estrangeira dos itens vinculados. Foi aplicada no ambiente local, sem alterar registros existentes. Manuais não criam produtos fictícios nem movimentam `tb_Produtos`; edição e estorno continuam recalculando o contrato, com autorização, versão e auditoria na mesma transação. A origem não pode ser convertida entre catálogo e manual durante edição; antes/depois incluem os dados editáveis completos. Não há exclusão física de registros.

Verificações: 23 testes de produtos passaram (252 asserções, SQLite em memória), arquitetura passou (3 testes), JavaScript passou (43 testes), build e compilação Blade aprovados. O navegador com HTTP simulado confirmou os campos editáveis, inclusão manual, edição e restauração dos controles após Salvar e Novo. Sem verificação de concorrência em MySQL nesta revisão.

`docker compose exec -T app composer check` aprovou Pint (218 arquivos) e executou 389 testes: 387 aprovados, 1 ignorado por exigir MySQL isolado e 1 falha na asserção antiga de quantidade de painéis. A causa era a mudança anterior para janela modal; o teste passou a verificar o diálogo fora da área de painéis, seu acionador e vínculo acessível. A reexecução específica desse teste passou (23 asserções), assim como Pint e `git diff --check`. A suíte completa não foi repetida após essa correção de expectativa; os 23 testes de produtos foram repetidos após o ajuste final da auditoria e passaram.

O botão **6. Cad.Prod** abre o cadastro em diálogo amplo (até 1.600 px, adaptado à janela), com fechamento pelo × ou Escape e retorno do foco ao botão. O diálogo mantém os campos no formulário atual para compartilhar versão, contexto e recálculo do valor do contrato, sem duplicar controles. Durante gravação, fechar fica bloqueado até a resposta. A listagem do catálogo e sua paginação foram retiradas da interface; permanecem consulta por Item/lupa e lista de produtos contratados. Validação desta revisão: 21 testes PHP de produtos (226 asserções), navegador com abertura/fechamento e operações simuladas, build e compilação Blade aprovados; `git diff --check` passou. Os testes PHP usam SQLite em memória.

A consulta por Item apresenta diálogo próprio com botão OK: `Este Item não corresponde a este Fornecedor` quando existe no processo mas pertence a outro fornecedor; `Item não encontrado neste processo.` quando ausente. O modo de processo continua permitindo outros fornecedores. O aviso não se repete ao sair do mesmo Item após fechar a caixa; editar o Item ou mudar o modo permite nova consulta. Teste de navegador com respostas simuladas passou para os dois avisos e fechamento pelo OK, além do fluxo de lançamento/edição/estorno. Build, compilação Blade e `git diff --check` passaram nesta revisão.

Verificação do preço editável: via Docker, `php artisan test --filter=ContractProductsTest` passou (21 testes, 226 asserções), `composer check:architecture` passou (3 testes), `npm test` passou (43 testes), `npm run build`, Pint dos dois arquivos PHP alterados e `php artisan view:cache` passaram. `git diff --check` passou. Os testes de persistência usaram SQLite em memória; esta revisão não repetiu a suíte completa nem a verificação em navegador.

O usuário substituiu os filtros por consulta apenas por Ficha e aviso somente em caso de divergência. A equivalência de nomes ignora caixa, acentos e espaços; para Secretaria, expande somente o prefixo `SEC MUNICIPAL`/`SEC. MUNICIPAL` para `SECRETARIA MUNICIPAL`. Essa comparação não filtra resultados. Usa o Fundo da sessão (ou o do contrato quando não há sessão de Fundo) e a Secretaria selecionada no formulário, com fallback à salva quando o campo está desabilitado. A confirmação é informativa: **Cancelar** não preenche Descrição/Dotação e não altera o banco.

Validação final desta revisão: `docker compose exec -T app php artisan test --filter=ContractProductsTest` passou com 16 testes, 151 asserções; Pint passou. `docker compose run --rm --no-deps node npm run build` passou. O navegador confirmou ausência de diálogo para correspondência, OK para divergência e Cancelar mantendo os campos vazios. Requisições do navegador foram simuladas; testes PHP usam SQLite em memória. A suíte completa da revisão imediatamente anterior passou com 381 testes (380 aprovados, 1 ignorado), 3.489 asserções, e 42 testes JavaScript. Não houve alterações de dados para mudar esta regra.

Validação final dos modos de seleção por Item: `docker compose exec -T app composer check` aprovou Pint e executou 386 testes, com 385 aprovados, 1 ignorado (concorrência exige MySQL isolado) e 3.548 asserções. Os 20 testes de produtos passaram com 200 asserções; arquitetura passou com 3 testes; JavaScript passou com 42 testes. Build, compilação Blade e teste de navegador passaram. O navegador usa respostas simuladas e os testes PHP usam SQLite em memória; concorrência em MySQL e ingestão externa não foram verificadas nesta revisão.

### Campo Disp no lançamento

O campo somente leitura Disp fica entre UND e QTD e recebe o saldo atual de `tb_Produtos.QTD` retornado pela consulta autorizada por Item ou pela seleção na lupa, com quatro casas decimais. Trocar o Item ou iniciar Novo limpa o campo. Lançamentos manuais e edição de snapshots já lançados deixam Disp vazio; a edição continua validando no servidor o saldo atual somado à quantidade anteriormente lançada.

Novos lançamentos do catálogo avisam e impedem o envio de QTD superior ao saldo consultado, com comparação decimal exata. A gravação mantém a revalidação transacional do saldo atual, inclusive quando outro contrato o consumir depois da consulta.

Validação desta alteração via Docker: `php artisan test --filter=ContractProductsTest` passou (25 testes, 266 asserções, SQLite em memória); `npm test` passou (44 testes); `npm run build` e `git diff --check` passaram. Suíte completa e navegador não foram executados nesta alteração.

### Revisão do cálculo de Disp

Substitui a definição anterior do campo: `Disp = max(soma(tb_Produtos_Contratos.QTD) - tb_Produtos.QTD, 0)`, com quatro casas decimais e aritmética exata. A soma considera Item, CNPJ do fornecedor do contrato e Processo; normaliza a máscara do documento, ignora lançamentos estornados e restringe os contratos aos escopos atuais do ator. Usa quantity apenas quando QTD estiver nulo. Valores inválidos deixam Disp vazio. A consulta por Item e a seleção pela lupa usam a mesma projeção disp. A propriedade remaining e a validação transacional continuam representando o saldo atual da origem; Disp é a diferença solicitada e não substitui esse limite.

Validação via Docker: Pint dos três arquivos PHP passou; `php artisan test --filter=ContractProductsTest` passou com 26 testes e 275 asserções (SQLite em memória); `npm test` passou com 44 testes; `npm run build` passou. O teste novo cobre diferenças positiva, nula e negativa, precisão de quatro casas, máscara de CNPJ e exclusão de outros itens/fornecedores/processos e estornos. Não houve teste em navegador nem suíte completa nesta revisão.

### Disponível, preço protegido e descrição expansível

O rótulo Disp passa a Disponível, com fundo na mesma cor da fonte do fornecedor no cabeçalho (`--supplier-name-color`). O cálculo permanece igual. VL. Unitário fica somente leitura no catálogo e libera edição exclusivamente em Lançamento manual, inclusive ao editar registros manuais. O servidor recusa preço diferente da origem ao criar um item de catálogo e diferente do snapshot ao editar; valores idênticos continuam aceitos para compatibilidade HTTP.

A descrição passa a textarea ocupando a largura até o fim de VL. Total. A altura acompanha o texto e as mudanças de largura, limitada a cinco linhas; textos maiores ficam acessíveis por rolagem. Novo e troca de Item também ajustam a altura.

Verificações executadas via Docker: Pint dos dois arquivos PHP alterados, `php artisan test --filter=ContractProductsTest` (26 testes, 285 asserções em SQLite em memória), `composer check:architecture` (3 testes), `npm test` (44 testes), `npm run build` e `php artisan view:cache`, todos aprovados. `node scripts/browser-contract-products.cjs` passou em Edge headless com fixture Blade e HTTP simulado, incluindo bloqueio/liberação do preço, cor de Disponível, alinhamento e limite de cinco linhas com rolagem. O navegador identificou uma regra global de readonly que sobrescrevia a cor; a prioridade foi corrigida e o build/navegador repetidos com sucesso. Não foi executada a suíte PHP completa nem testes no banco instalado.

### Valores por ficha: consulta à origem legada

O painel passa a apresentar os registros de `tb_ValorFicha` para a Ficha digitada, em vez de somar produtos lançados. A consulta ocorre ao sair do campo Ficha e exige correspondência simultânea de Ficha, Fundo, Contrato e CPF_CNPJ com o contrato salvo e autorizado. Ficha e número de contrato preservam zeros iniciais; Fundo compara caixa/acentos/espaços normalizados e CPF/CNPJ compara dígitos. Não acrescenta filtro de processo ou secretaria. Só Ficha e Valor são retornados; cada registro correspondente aparece em sua própria linha, sem somas presumidas. Ausência de correspondência deixa a lista vazia, e valores inválidos aparecem sem valor numérico. Editar/limpar a Ficha limpa imediatamente o painel; consultas canceladas ou antigas não o repovoam. Salvar produto/Novo limpa a Ficha e o painel. Estes valores são documentais e não autorizam movimentações financeiras.

A migration aditiva `2026_10_16_000006_create_ficha_values_catalog` cria os nove campos legados apenas se a tabela estiver ausente e preserva a tabela no rollback. `php artisan contracts:import-ficha-values [arquivo.sql]` usa por padrão `database-example/u910323952_bdgestao_niq.sql`, lendo somente literais de tb_ValorFicha, sem executar SQL da fonte. A importação registra ImportRun, LegacyRecord e ActivityEvent em transação; reexecuções iguais são ignoradas e divergências preservadas como conflitos.

No ambiente local Docker, foram executados `php artisan migrate --path=database/migrations/2026_10_16_000006_create_ficha_values_catalog.php --force` e `php artisan contracts:import-ficha-values`: 1.462 registros importados, zero conflitos. Nenhum contrato ou produto foi alterado na carga.

Verificações via Docker: Pint passou; `php artisan test --filter=ContractProductsTest` passou com 28 testes e 300 asserções usando SQLite em memória; `composer check:architecture` passou com 3 testes; `npm test` passou com 44 testes; `npm run build` e `php artisan view:cache` passaram. `node scripts/browser-contract-products.cjs` passou em Edge headless com HTTP simulado, conferindo os valores de ficha da resposta e limpeza após salvar. Os testes novos cobrem os quatro identificadores, máscara do documento, zeros iniciais, ausência de correspondência, acesso negado, importação idempotente e preservação de conflitos.

`docker compose exec -T app composer check` também passou: Pint em 225 arquivos, 394 testes (393 aprovados e 1 ignorado por exigir MySQL isolado), 3.650 asserções. A suíte usou os padrões isolados de teste, sem executar testes no banco importado. `git diff --check` passou.

### Correção das larguras reais de Produtos do contrato

A proporção anterior por CSS não garantia a medida final porque a tabela redistribuía sua largura durante o layout. Agora a largura da tabela acompanha a área disponível (mínimo de 1.000 px com rolagem), e um ResizeObserver mede o layout original antes de aplicar Produto com 70% dessa medida e adicionar 15% a VL. Unitário e 15% a VL. Total. O título Código permanece em uma linha. As medidas são refeitas ao abrir e redimensionar a janela, sem reduzir novamente uma largura já ajustada.

Verificações: `node scripts/browser-contract-products.cjs` passou em Edge headless com HTTP simulado; asserções comparam as medidas originais e finais nas larguras de janela 1.024, 1.280 e 1.600 px, com tolerância inferior a um pixel. O teste primeiro reproduziu a divergência e passou após fixar a largura da tabela antes da distribuição. `docker compose run --rm --no-deps node npm test` (44 testes), `docker compose run --rm --no-deps node npm run build` e `git diff --check` passaram. Não houve mudança de regras ou banco nem reexecução da suíte PHP nesta correção visual.

### Larguras fixas solicitadas para a lista

Substituída a distribuição proporcional por larguras fixas em pixels: Item 50, Código 54, Produto 518, UND 55, QTD 74, Marca 120, VL. Unitário 80, VL. Total 80, Ficha 80 e Ação 80. A tabela mantém rolagem horizontal quando necessário e não amplia as colunas para ocupar a janela. Removido o ResizeObserver que redistribuía as larguras.

`docker compose run --rm --no-deps node npm run build`, `node scripts/browser-contract-products.cjs` e `git diff --check` passaram. O navegador verifica as dez larguras com tolerância inferior a um pixel em janelas de 1.024, 1.280 e 1.600 px, usando HTTP simulado. Não houve mudança de banco ou regra de negócio; a suíte PHP não foi repetida.

### Lista alinhada à largura disponível

Mantidas as larguras de Item 50, Código 54, UND 55, Marca 120, Ficha 80 e Ação 80 px. QTD passa a 94 px e os valores unitário/total a 100 px cada (+20 px). Produto, única coluna não fixada nesta solicitação, ocupa o espaço restante. A tabela usa 100% da largura, alinhada ao painel Valores por ficha; abaixo de 1.000 px internos mantém rolagem para preservar legibilidade. Build, `git diff --check` e navegador com HTTP simulado passaram; o navegador verifica as larguras e o alinhamento em janelas de 1.024, 1.280 e 1.600 px. Suíte PHP não repetida nesta alteração visual.

### Validação para commit e push — 16/10/2026

Removido apenas o título visível Valores por ficha, preservando a tabela e sua identificação acessível.

Comandos executados via Docker: `composer check` aprovou Pint (225 arquivos) e 393 testes, com 3.650 asserções e 1 teste ignorado por exigir MySQL isolado; `composer check:architecture` passou (3 testes, 4 asserções); `composer test:unit` passou (57 testes, 129 asserções); `npm test` passou (44 testes); `npm run build` passou. `git diff --check` e `git diff --cached --check` passaram. Testes PHP usaram SQLite em memória e armazenamento temporário. Navegador e concorrência MySQL não foram repetidos nesta preparação.
### Consulta por contrato, fundo e processo — 17/10/2026

O painel lista todas as fichas correspondentes a Contrato, Fundo e Proc_Cont do contrato autorizado. Não depende da ficha digitada nem do CPF/CNPJ. Reconsulta ao abrir o cadastro e após salvar/excluir produtos; limpar o formulário ou consultar dotações não limpa a lista. Esta regra substitui a consulta anterior por quatro identificadores. A atualização financeira permanece pendente da regra para produtos sem ficha.

Validação: PHPUnit ContractProductsTest passou (28 testes, 303 asserções), composer check:architecture passou (3 testes, 4 asserções), npm test passou (44 testes), npm run build e git diff --check passaram. Testes PHP em SQLite em memória e storage temporário. Não houve alteração de dados financeiros, teste em navegador ou execução da suíte completa.

### Espaçamento da janela sem produtos — 17/10/2026

Reduzidos os espaçamentos do cabeçalho e dos campos. A lista vazia deixa de reservar 180 px, mantendo o cabeçalho das colunas; listas com itens continuam com rolagem. A lista lateral de fichas tem altura limitada e rolagem própria para não alongar todo o formulário.

Validação: npm run build e git diff --check passaram. Conferência em Edge headless com fixture Blade sem acesso ao banco, nas resoluções 1280x720, 1366x768 e 1600x900, verificou ausência de rolagem vertical na janela sem produtos e visibilidade do total, inclusive com 25 fichas. Com 20 produtos, a rolagem da lista foi preservada. Playwright instalado apenas em diretório temporário para essa conferência. Não foram executados testes PHP ou navegação autenticada nesta alteração de CSS.
