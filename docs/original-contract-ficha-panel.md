# Painel de fichas do contrato original

## Atualização em 21/10/2026

O painel aceita `N_ADT` nulo ou zero quando `Proc_ADT` está vazio, mantendo os filtros de Fundo, Contrato e Processo e a autorização existentes. A inspeção somente de leitura do banco local encontrou 1.460 registros sem processo de aditivo, todos com `N_ADT=0`; o filtro anterior ocultava essas fichas. Nenhum registro foi alterado. Esta regra substitui a interpretação estrita de zero descrita no histórico abaixo.

O quadro exibe somente Ficha e Valor, sem total das fichas ou valor original no rodapé. A soma continua exata no servidor e o contrato HTTP de `ficha_summary` permanece igual. O aviso aparece quando `difference` é positivo, orientando conferir o cadastro das fichas ou o valor do contrato. Soma inferior ou igual não gera aviso; valores inválidos continuam impedindo a conferência e apresentam uma mensagem explicativa.

Validação: `docker compose exec -T app php artisan test --filter=ContractProductsTest --compact` passou (35 testes, 413 asserções, SQLite isolado); `node --test tests/JavaScript/contract-products.test.mjs tests/JavaScript/contract-product-fichas.test.mjs` passou (10 testes); `docker compose run --rm --no-deps node npm run build` passou. Sem validação visual autenticada no navegador.

## Histórico da implementação em 20/10/2026

O quadro Ficha/Valor no Cadastro de produtos consulta `tb_ValorFicha` pelo Fundo do contrato autorizado (normalizado), `Contrato` e `Proc_Cont` salvos. Exige `Proc_ADT` e `N_ADT` vazios simultaneamente. `null`, texto vazio e espaços são vazios; zero é preenchimento e fica excluído, conforme a regra estrita de itens originais. Fichas e valores de aditivos permanecem no banco.

O filtro vale tanto para a lista completa quanto para a consulta por ficha em `budget-lookup`. CPF/CNPJ da origem não é filtro nem é retornado. Parâmetros HTTP não substituem Fundo/contrato/Processo persistidos e as policies/escopos existentes continuam obrigatórios.

`FichaValuesCatalog::summarize` soma exatamente os valores filtrados com Brick Math, preservando quatro casas decimais. Compara a soma com `contracts.valor_total` (valor inicial/original), nunca com `valor_acumulado`. Não calcula o total a partir dos produtos e não altera valores para forçar igualdade. Uma divergência aparece no painel com sua diferença; valor inválido impede a conferência e não é descartado silenciosamente da soma.

O endpoint de produtos preserva `ficha_values` e acrescenta `ficha_summary` com `total`, `contract_total`, `difference` (soma menos valor original) e `matches`. Ausência ou valor inválido impede comparação e retorna `matches: null`; divergência válida retorna `false`. O resumo considera todas as fichas originais mesmo quando a lista de produtos está filtrada por uma ficha. O rodapé do painel apresenta total das fichas e valor original; a mensagem de conferência usa o resumo calculado no servidor.

## Schema e importação

O schema Laravel anterior definia `N_ADT` como inteiro obrigatório com padrão zero, impossibilitando representar ausência como `null`. A migration `2026_10_20_000005_allow_empty_ficha_amendment_number.php` permite `null` e troca somente o padrão para `null`. Não converte zeros existentes nem altera montantes ou registros. Foi aplicada isoladamente no ambiente configurado com:

`docker compose exec -T app php artisan migrate --path=database/migrations/2026_10_20_000005_allow_empty_ficha_amendment_number.php --force`

O comando concluiu com sucesso, após inspeção de schema confirmar que a coluna existente era `int`, obrigatória, com padrão `0`. Outras migrations pendentes não foram executadas. Em outras instalações, aplique essa migration antes de importar fichas com `N_ADT` vazio.

`FichaValuesImporter` mantém `N_ADT` vazio como `null`, preserva valores preenchidos (inclusive zero) e mantém a origem integral em `legacy_records`. Reimportação idêntica com ausência continua idempotente; conflitos existentes não são sobrescritos.

## Validação em 20/10/2026

- `docker compose exec -T app php artisan test --filter=ContractProductsTest --compact`: 35 testes, 411 asserções; passou com SQLite em memória e storage/cache temporários. Cobre filtros, aditivo informado em cada campo, zeros, identidade divergente, somas exatas, diferença de 0,0001, valor inválido e importação vazia/idempotente.
- `node --test tests/JavaScript/contract-products.test.mjs tests/JavaScript/contract-product-fichas.test.mjs`: 10 testes; passou, incluindo rodapé e mensagens de igualdade/divergência/valor inválido.
- Pint dos cinco PHP envolvidos passou. `composer check:architecture` passou (3 testes, 4 asserções); `composer test:unit` passou (77 testes, 169 asserções); `npm.cmd run build` e `git diff --check` passaram.
- Verificação final: `docker compose exec -T app composer check` passou (Pint em 323 arquivos; PHPUnit com 680 testes, 6.788 asserções e 1 ignorado). `npm.cmd test` passou com 108 testes; o build foi repetido após o ajuste de quebra dos rótulos no rodapé do painel.

Os testes não acessaram o banco instalado. Nenhum valor financeiro existente foi alterado. Sem conferência visual em navegador nesta alteração.

## Limpeza pontual autorizada do contrato 037/24

Em 20/10/2026, o usuário confirmou expressamente a exclusão dos registros 781 (ficha 20250085, R$ 16.002,00) e 960 (ficha 20260085, R$ 48.006,00), preservando o registro 15 (ficha 20240085, R$ 21.336,00), do Fundo MUNICIPIO DE NIQUELÂNDIA e Processo 2024003810. A consulta constatou `N_ADT=0` nos três registros; o aceite autorizou considerar esse zero como ausência de aditivo **para essa exclusão pontual**. A regra geral do filtro e o `N_ADT` do registro mantido não foram alterados.

A manutenção foi executada por PHP via entrada padrão do container, como `www-data`, usando o lock compartilhado `contracts-reference-import`. Na mesma transação, foram bloqueados e revalidados contrato 3684 (versão 2, valor original 21336.0000), identidade completa e os três IDs/fichas/valores; foi verificada uma cópia integral privada dos três registros antes de excluir apenas 781/960. A operação incrementou a versão para 3 e gravou a auditoria de sistema 2881 (`ficha_values_cleaned`, ator nulo), com IDs e referência/hash da cópia privada. Nenhum usuário humano foi atribuído à operação. O valor original do contrato permaneceu intacto.

A cópia está no storage privado do container, em `app/private/maintenance/contract-037-24-fichas-20261020-195544-66642853dc55.json`, SHA-256 `1f99598e32308aee36200d651dfcd003da78bbe871aedead5ce325bc08281799`, com permissão 0600. A consulta posterior ao commit confirmou somente o registro 15 nesse Fundo/contrato/Processo, ausência dos dois IDs excluídos, auditoria persistida e hash da cópia íntegro. Não foram executados testes ou migrations no banco instalado; foram realizadas apenas a manutenção explicitamente autorizada e as consultas de conferência.
