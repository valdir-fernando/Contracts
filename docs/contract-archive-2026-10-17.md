# Exercício padrão no Acervo Contratual

Ao abrir `/contracts` sem o parâmetro `year`, a listagem usa o ano atual do relógio da aplicação. O campo Exercício mostra esse mesmo ano, e o filtro é aplicado no servidor antes da paginação. O ano atual permanece entre as opções mesmo quando ainda não há contratos nele; os demais exercícios continuam limitados aos contratos visíveis ao usuário.

Um ano explícito, como `?year=2025`, é respeitado. A opção **Todos** envia `year=` e remove o filtro de exercício, incluindo contratos sem exercício. Limpar filtros volta ao padrão do ano atual. Paginação e abertura dos mesmos filtros nos relatórios preservam o ano efetivo. A tela independente de relatórios mantém seus padrões anteriores. Escopos, permissões e regras de contratos excluídos continuam aplicados.

O ajuste fica em `ContractController::index`; `ContractListingQuery` e `ContractSearch` preservam suas assinaturas e o comportamento para outros consumidores. Não há migration, alteração de registros ou dependências de relógio acrescentadas em Domain/Application.

Validação específica via Docker: Pint passou nos dois arquivos PHP alterados e `php vendor/bin/phpunit --filter=ContractBrowsingTest --do-not-cache-result` passou com 11 testes e 193 asserções. Testes cobrem abertura padrão, seleção do campo, exercício anterior, todos os anos, contratos sem exercício, virada do ano sem registros, validação, escopos, paginação e relatórios. SQLite em memória e storage temporário; nenhum teste no banco instalado.

A primeira execução de composer check encontrou duas expectativas antigas de listagem de contratos sem exercício (restauração e navegação de tabelas). A causa foi o novo filtro padrão, que corretamente exclui registros sem exercício. Essas consultas de teste passaram a enviar year= explicitamente, mantendo as asserções de segurança, restauração e navegação. O teste de busca com números duplicados entre exercícios também usa Todos explicitamente para continuar verificando ambos os registros. Não foram removidas asserções.

Verificação final: composer check passou (Pint em 228 arquivos; 405 testes, 404 aprovados e 1 ignorado por exigir MySQL isolado; 3.813 asserções). composer check:static passou sem erros. git diff --check passou. Não houve mudança de assets nem teste em navegador nesta revisão.
