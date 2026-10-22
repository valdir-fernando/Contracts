# Fechamento do cadastro de produtos

O aviso de divergência de Fundo/Secretaria devolve o foco ao campo Ficha. Ao fechar o cadastro de produtos, a perda de foco podia iniciar outra consulta e abrir um aviso modal após o fechamento do painel, bloqueando os controles do contrato.

O fechamento agora cancela consultas pendentes e fecha os diálogos internos. Sair do campo Ficha para o botão de fechar ou para o próprio aviso não repete a consulta. Consultas de ficha e seus avisos não abrem com o painel fechado, e o fechamento do aviso só devolve o foco à ficha enquanto o painel está aberto. Não há alteração de endpoints, persistência ou permissões.

Após o OK, sair novamente do campo Ficha para os demais campos também não repete a consulta nem o aviso enquanto Ficha e Secretaria permanecerem iguais. Alterar a ficha, reabrir o painel ou atualizar o cadastro de dotação permite nova consulta. Falhas de consulta permitem tentar novamente ao sair do campo.

O botão Novo limpa os campos do produto e preserva a ficha consultada, sua descrição e dotação. Não refaz a consulta se Ficha e Secretaria não mudaram, evitando repetir o aviso já confirmado.

Validação executada:

- `node --test tests/JavaScript/*.test.mjs`: 124 testes passaram, incluindo regressões para fechamento após OK, resposta atrasada após fechar, saída para outros campos, cliques repetidos em Novo e limpeza por Nova Ficha com resposta de lista pendente.
- `docker compose run --rm --no-deps node npm run build`: passou; assets locais atualizados.
- `git diff --check`: passou.

Sem validação visual autenticada no navegador. Nenhum teste foi executado contra o banco instalado.

## Nova Ficha

À direita de Excluir / estornar, uma barra separadora antecede o botão Nova Ficha. Ele limpa os mesmos campos de produto que Novo e também Ficha, Descrição e Dotação da ficha, esvazia a lista de produtos e coloca o foco em Ficha. Invalida consultas anteriores para impedir que respostas atrasadas restaurem a seleção ou a lista. O painel de fichas permanece disponível para nova seleção. A ação só limpa a interface; não exclui registros e fica indisponível durante gravações.

## Detalhes da divergência de Fundo/Secretaria

O endpoint privado de consulta da ficha acrescenta `fund_matches` e `department_matches`, preservando `scope_matches`, `fund` e `department`. `BudgetCatalog::compareScope` usa a mesma normalização e equivalência de abreviações existentes; a comparação continua informativa, após autorização do contrato.

No Cadastro de produtos, Fundo divergente tem prioridade: o aviso mostra somente o nome do Fundo ao qual a ficha pertence. Com Fundo correto e Secretaria divergente, mostra somente a Secretaria cadastrada na ficha. Secretaria vazia recebe texto explícito de ausência. Os nomes são inseridos por `textContent`. OK preserva o preenchimento e não repete o aviso ao navegar sem alterar a ficha ou Secretaria.

Validação deste ajuste: `node --test tests/JavaScript/*.test.mjs` (126 testes); `docker compose exec -T app php vendor/bin/phpunit --filter=ContractProductsTest --do-not-cache-result` (38 testes, 450 asserções, SQLite isolado); Pint `--test` dos três PHP alterados; `docker compose exec -T app composer check:architecture` (3 testes, 4 asserções); `docker compose run --rm --no-deps node npm run build`; `git diff --check`. Todos passaram. Sem conferência visual autenticada no navegador.
