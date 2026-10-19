# Seleção de ficha no Cadastro de produtos

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
