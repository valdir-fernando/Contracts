# Filtro Tipo na consulta de contratos

O campo Tipo aparece nos filtros principais da lista, inclusive em Contratos vigentes, ao lado dos demais critérios. A opção Todos remove apenas a restrição de tipo; os filtros de fundo, exercício e vigência continuam combinados. O seletor foi movido da pesquisa avançada, mantendo um único parâmetro `type` no formulário.

A consulta existente continua validando o parâmetro e limitando resultados e opções aos contratos autorizados. Não há alteração de banco nem de contratos HTTP.

Validação executada: `php vendor/bin/phpunit --filter=ContractBrowsingTest --do-not-cache-result` aprovado (8 testes, 172 asserções), com cenário combinando tipo/vigência/fundo/ano e restrições de acesso; `php vendor/bin/pint --test tests/Feature/ContractBrowsingTest.php` aprovado; `npm run build` aprovado; `git diff --check` aprovado. Os comandos PHP/Node foram executados nos contêineres locais; testes usam SQLite em memória. Não foi executada validação em navegador nesta alteração.
