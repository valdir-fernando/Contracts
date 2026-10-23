# Ajustes locais — 23/10/2026

## Alterações

- Menu lateral preserva a posição de rolagem e o estado de Cadastros durante a navegação na mesma aba. Órgãos e permissões tem marcação própria e não abre Cadastros automaticamente.
- Cabeçalhos das listas usam cinza `#4b5563` e texto branco. Botão de fechar do fornecedor alinhado à barra de ações; mensagens de documentos aparecem acima da navegação do contrato.
- Lista de produtos usa 11 px, com larguras ajustadas para UND, QTD e Ação. Fichas clicáveis não recebem o fundo dourado dos botões gerais.
- Credenciamento com Cargo Credenciamento - Saúde preenchido mostra CATSER no lugar de Código. A edição é validada no servidor e auditada no lançamento, sem alterar o catálogo de origem. Detalhes em [Cadastro de produtos](contract-products-2026-10-16.md).

## Validação executada

- `docker compose exec -T app composer check`: Pint aprovado em 345 arquivos; PHPUnit com 770 testes, 7.855 asserções, nenhuma falha e 1 teste ignorado.
- `docker compose exec -T app composer test:unit`: 81 testes, 195 asserções, aprovado.
- `docker compose exec -T app composer check:architecture`: 3 testes, 4 asserções, aprovado.
- `node --test tests/JavaScript/*.test.mjs`: 136 testes aprovados.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `git diff --check`: aprovado.

Os testes PHP usam SQLite em memória e storage/cache temporários. Sem validação visual autenticada no navegador. Imagens e modelo Word locais de Reference ficaram fora dos commits; as importações pontuais autorizadas de produto e dotação são operações do banco local, não arquivos de código para o Git.
