# Seleção de ficha no Cadastro de produtos

## Produtos do contrato original — 20/10/2026

A lista **Produtos do contrato** e as opções de **Ficha** agora usam `Infrastructure/Contracts/OriginalContractProducts`. O adaptador restringe a consulta ao `contract_id` autorizado e ao Processo salvo, confere Fundo, número do contrato e Processo das colunas legadas quando presentes e verifica a proveniência em `legacy_records`. **ADT e Proc_ADT devem estar ambos vazios** (`null`, string vazia ou somente espaços). Zero, inclusive `0` numérico ou `"0"` textual, não é vazio: qualquer preenchimento em um dos dois campos exclui o item, mesmo quando Fundo, contrato e Processo coincidem. A regra é conferida nos atributos do produto quando presentes e na origem legada preservada em `legacy_records`. Fundo é normalizado pelo normalizador existente; número e Processo preservam zeros e ignoram somente espaços externos.

O filtro aplica-se no servidor e na pré-carga das fichas do HTML. Parâmetros HTTP de Fundo/contrato/Processo não substituem a identidade persistida; `process_catalog` não inclui os aditivos nessa lista. Itens ocultos também não podem ser editados ou estornados por uma chamada direta a esse cadastro: o adaptador de escrita verifica a classificação depois de bloquear e autorizar o contrato e o lançamento. Histórico, valores e registros dos aditivos permanecem preservados.

Lançamentos nativos anteriores às colunas legadas continuam vinculados pelo `contract_id` e `process_number`. Lançamentos manuais sem Processo continuam visíveis e editáveis quando o próprio contrato também não tem Processo; isso não permite buscar itens de outros contratos. A mudança não reimporta dados, não executa migrations e não recalcula saldos ou valores existentes ao consultar a lista.

Validação: testes `ContractProductsTest|ContractProductImportTest` passaram via Docker com SQLite em memória (37 testes, 413 asserções); os dois arquivos JavaScript de produtos/fichas passaram (9 testes). `composer check:architecture` passou (3 testes, 4 asserções), `composer test:unit` passou (77 testes, 169 asserções) e `npm.cmd run build` concluiu. A primeira execução detectou que lançamentos manuais sem Processo estavam sendo recusados; o filtro foi corrigido para manter esse comportamento existente, e a asserção da lista passou a exigir explicitamente um item. Nenhuma asserção foi removida ou enfraquecida.

Verificação final: `docker compose exec -T app composer check` passou, com Pint em 322 arquivos e PHPUnit com 677 testes, 6.754 asserções e 1 ignorado. `git diff --check` passou. Testes usaram SQLite em memória e storage/cache temporários; nenhum banco instalado foi alterado. Sem inspeção visual no navegador nesta alteração.

Correção posterior conforme esclarecimento do usuário: a classificação anterior aceitava zero; agora exige ambos os campos vazios. Validação desta correção: `php artisan test --filter='ContractProductsTest|ContractProductImportTest' --compact` via Docker passou (38 testes, 428 asserções, SQLite em memória), incluindo zero numérico/textual em cada campo, espaços e `null`. Pint dos dois PHP alterados, `composer check:architecture` (3 testes, 4 asserções), `composer test:unit` (77 testes, 169 asserções) e `git diff --check` passaram. A suíte completa e o build anteriores não foram repetidos nesta correção localizada de PHP; sem alteração de assets, schema ou registros existentes.

As descrições abaixo registram o comportamento anterior; referências a “todos os lançamentos” e “todas as fichas” agora se limitam ao contrato original conforme as regras acima.

O campo **Ficha** é uma seleção editável, implementada com input e datalist nativos. Ao carregar o contrato, o próprio HTML já contém a lista das fichas dos produtos ativos vinculados pelo `contract_id` na tabela existente `tb_Produtos_Contratos`. Não é necessário selecionar um produto antes para carregar as opções. Ao escolher uma ficha, a lista **Produtos do contrato** carrega somente seus lançamentos. Deixar o campo vazio mostra todos os lançamentos, inclusive os que não têm ficha. Identificadores como `001`, `1` e `0` são preservados e comparados exatamente, desconsiderando apenas espaços externos.

O usuário pode digitar uma ficha diferente diretamente nesse mesmo campo, sem botão ou campo separado **Nova ficha**. Digitar não cadastra uma opção: a ficha passa a integrar a lista quando um produto é salvo com esse valor. Atualizar as opções nunca apaga ou substitui o texto digitado. As regras existentes de gravação, validação e auditoria continuam aplicadas.

Após salvar, a seleção é mantida e a lista mostra os produtos dessa ficha. **Novo** limpa o editor do produto, mantendo a ficha escolhida para facilitar lançamentos consecutivos. Ao excluir/estornar o último produto de uma ficha, ela desaparece das opções. Se era a seleção atual, o painel limpa o campo e carrega todos os produtos restantes. Reabrir o painel ou retornar à aba atualiza as opções e a lista.

O valor total exibido continua sendo o valor do contrato inteiro. A consulta de descrição/dotação acompanha a seleção da ficha; a origem e as regras do quadro de valores por ficha permanecem as mesmas.

## Contrato HTTP

`GET /contracts/{contract}/products` aceita o parâmetro opcional `ficha`, string de até 64 caracteres. Sem valor, `items` mantém o comportamento anterior e retorna todos os produtos ativos do contrato. Com valor, `items` retorna somente a ficha exata. O novo campo `fichas` sempre retorna todas as fichas não vazias dos produtos ativos desse contrato, sem duplicatas e ordenadas naturalmente, mesmo quando `items` está filtrado.

O controller revalida o ator e a policy do contrato antes da consulta. A lista de opções e o filtro usam exclusivamente o `contract_id` autorizado; não consultam fichas de outros contratos ou somente do catálogo do processo. Registros excluídos logicamente não entram. `process_catalog` continua controlando apenas a consulta de produtos disponíveis no processo, sem ampliar a autorização do contrato.

No JavaScript, digitação atualiza o filtro com debounce de 200 ms e a confirmação de uma seleção dispara a consulta imediatamente. Respostas antigas são descartadas quando ficha, versão, modo de catálogo ou sequência da consulta mudam. Mudanças de versão exigem recarregar a página. As opções são criadas como texto, preservando zeros iniciais sem interpretar HTML. Durante a gravação, o campo fica bloqueado; consultas em andamento permitem continuar digitando.

## Validação

Testes de integração usam SQLite em memória e verificam a lista já no HTML inicial da edição do contrato, atualização após lançamento, transferência de ficha e estorno, distinção entre `001`, `1` e `0`, remoção da última ficha, limite de tamanho e isolamento entre contratos/usuários. Testes JavaScript exercitam o painel com um DOM simulado, incluindo uma resposta atrasada, digitação direta de ficha nova sem incluí-la antecipadamente nas opções, manutenção do texto digitado e estorno do último produto.

Comandos executados:

- `docker compose exec -T app php artisan test --filter=ContractProductsTest`: validação da seleção editável e pré-carga no HTML inicial, 30 testes, 346 asserções; passou.
- `node --test tests/JavaScript/contract-products.test.mjs tests/JavaScript/contract-product-fichas.test.mjs`: 9 testes; passou.
- `npm.cmd test`: 90 testes; passou.
- `npm.cmd run build`: build Vite concluído.
- `docker compose exec -T app composer check:architecture`: 3 testes, 4 asserções; passou.
- `docker compose exec -T app composer test:unit`: 75 testes, 165 asserções; passou.
- `docker compose exec -T app composer check`: Pint passou em 295 arquivos. PHPUnit executou 591 testes, 5.489 asserções, com 3 falhas e 1 ignorado. Permanecem as três falhas anteriores de expectativas de nomes em maiúsculas em `AuditCorrectionsTest::test_old_user_form_cannot_restore_replaced_scopes`, `CatalogDelegationTest::test_supplier_permission_allows_creation_and_own_catalog_but_not_unrelated_records` e `SupplierAssistanceTest::test_supplier_modal_keeps_validation_and_success_on_the_supplier_list`. Esses arquivos e as alterações prévias de normalização foram preservados; os testes de produtos passaram na suíte completa.
- `git diff --check`: sem erros.

Nenhum banco instalado foi alterado nos testes. Não há migration para essa mudança.

## Entrada de ficha e seleção pelo painel — 21/10/2026

O campo Ficha agora é uma entrada de texto sem datalist ou opções de seleção. O usuário pode digitar a ficha ou clicar no número exibido no painel de fichas. O clique preenche o campo, consulta descrição/dotação e filtra Produtos do contrato, usando o mesmo fluxo da digitação. Os números são botões acessíveis por teclado; operações ocupadas ou com versão desatualizada recusam a seleção. Esta alteração substitui as descrições anteriores de seleção de fichas no próprio campo.

Validação: 14 testes JavaScript de produtos/fichas passaram, incluindo seleção pelo painel, consulta, filtro, respostas atrasadas, digitação, inclusão e estorno. ContractProductsTest passou com 38 testes e 440 asserções em SQLite em memória. Sem validação visual autenticada no navegador.

## Consulta ao sair do campo — 21/10/2026

A digitação em Ficha limpa os dados anteriores e invalida consultas pendentes, sem disparar requisições. A consulta de descrição/dotação e o filtro dos produtos ocorrem no evento blur, quando o campo perde o foco. O evento change não consulta. Seleção explícita pelo painel de fichas e atualização após salvar uma dotação preservam os fluxos existentes.

Validação: os 16 testes JavaScript de produtos/fichas/painel passaram, incluindo um teste que aguarda além do antigo debounce e confirma ausência de requisições antes do blur. Build Docker e git diff --check passaram. Sem conferência visual autenticada no navegador.
