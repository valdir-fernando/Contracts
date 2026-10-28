# Explicações dos campos

As janelas dos ícones de informação de Escopo, IPTU e Tipo da Obra exibem imagens, conforme a preferência final do usuário. `public/images/field-help/work-types.jpg` é uma cópia da referência original `Reference/Empreitada.jpg`. Como as imagens originais de Escopo e IPTU não estão disponíveis na pasta de referência, `scope-help.png` e `iptu-help.png` reproduzem o conteúdo integral anterior, incluindo as tabelas, exemplos, prazos e referências. Os assets de emissão acompanham a aplicação, sem depender da pasta Reference.

Essas três janelas têm largura de até 940 px, limitada à tela com margens de 8 px. As imagens mantêm suas proporções, com rolagem vertical quando necessário. O posicionamento é recalculado após carregar a imagem. O texto integral também permanece disponível para leitores de tela, sem duplicar o conteúdo visual. As demais ajudas continuam em texto. Não houve alteração de regras de gravação.

Validação executada em 28/10/2026:

- `docker compose exec -T app php artisan test --filter='ContractWritingTest::test_scope_select|ContractWritingTest::test_iptu_has|ContractWritingTest::test_work_type' --compact`: 3 testes, 102 asserções; passou com a exibição das imagens, usando SQLite em memória.
- Inspeção visual dos dois PNGs gerados: tabelas e conteúdo integral visíveis. Imagem de Tipo da Obra copiada sem alterações.
- `docker compose run --rm --no-deps node npm run build`: passou.
- `git diff --check`: passou.

Sem conferência visual autenticada em navegador. A suíte geral não foi repetida para esta alteração de apresentação.
