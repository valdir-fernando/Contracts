# Valor acumulado no formulário de contrato

Ao abrir um contrato salvo, a barra de ações apresenta **Valor Acumulado do contrato** ao lado de **Valor do contrato** quando existe pelo menos um aditivo ativo registrado com `amount` diferente de zero, ou um histórico importado de aditivo vinculado ao contrato com `match_status=matched` e valor diferente de zero. Valores negativos e frações de quatro casas decimais também contam. Rascunhos, exclusões lógicas, valores nulos/zero e históricos de outros contratos não habilitam o campo.

O campo é somente de leitura, sem atributo `name`, e apresenta `contracts.valor_acumulado` persistido com a formatação monetária existente. Não soma aditivos nem altera valores financeiros; a conferência do acumulado permanece no fluxo existente. A consulta acontece somente após autorização de acesso ao contrato. Na criação o campo não é renderizado.

Validação: `ContractWritingTest` cobre criação, contrato sem aditivo, rascunho, aditivo registrado com valor nulo, zero, positivo mínimo e negativo, exclusão lógica e histórico importado com vínculo/tipo/situação divergentes. Também confirma que o valor acumulado persistido não muda com a consulta.

Comandos executados no Docker: `php artisan test --compact tests/Feature/ContractWritingTest.php` (45 testes, 751 asserções); `composer check:architecture` (3 testes, 4 asserções); `composer test:unit` (81 testes, 195 asserções); `composer check` (Pint aprovado; 743 testes, 7401 asserções, 1 ignorado); `composer check:static` (sem erros); `npm run build` (aprovado). `git diff --check` também passou. Testes usam SQLite isolado; não houve alteração de dados reais nem validação visual manual no navegador.
