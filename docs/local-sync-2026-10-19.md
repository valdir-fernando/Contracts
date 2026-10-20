# Atualização desta instalação em 19/10/2026

A cópia local estava em `b656e0d`. `git fetch origin` identificou cinco commits novos; `git merge --ff-only origin/main` avançou para `04dbdcb`, sem criar commit. A atualização inclui PDF completo de Fornecimento, fichas editáveis, importador de produtos e ajustes de usuários/fornecedores.

As alterações locais de login em maiúsculas e relatórios por fiscal foram preservadas. O README foi preservado temporariamente por stash e reconciliado mantendo tanto os links novos do remoto quanto o link do relatório por fiscal.

Preparação executada pelo container `app`: `php artisan migrate --force` aplicou somente `2026_10_19_000001_track_imported_contract_products`, que acrescenta proveniência opcional aos produtos sem excluir registros. `view:clear`, `route:clear` e `queue:restart` foram executados. Não houve importação ou restauração de dados.

`node npm run build` concluiu; `node npm test` passou com 90 testes. `composer check:architecture` passou com 3 testes e 4 asserções; `composer test:unit` passou com 77 testes e 169 asserções. A rota `contracts.document` foi confirmada por `route:list`; `/login` respondeu HTTP 200. `git diff --check` passou.

O banco do trabalho não é transportado pelo Git. Dados de produtos, cadastros, fotos e configurações podem diferir entre as instalações. Não houve ensaio manual autenticado em navegador.

Validação final: `docker compose exec -T app composer check` passou no Pint (299 arquivos) e no PHPUnit (604 testes, 5.570 asserções, sem falhas; um teste ignorado). Os testes usam SQLite em memória e storage/cache temporários.

## Correção dos dados do 015/25 do Município

Nova conferência de `git fetch origin`: nenhum commit pendente. Consulta somente leitura encontrou o contrato interno 3690, valor inicial `2530.9200`, versão 1, sem produtos; `tb_Dotacao` estava vazia. A validação de emissão retornava “Cadastre os produtos do contrato antes de emitir o documento”. O dump local contém os quatro produtos e a dotação da ficha `20250031`, conforme a documentação da instalação da Prefeitura.

`php artisan contracts:import-contract-products --dry-run` simulou 4.553 registros: 4.427 elegíveis, 125 sem vínculo e 1 inválido. A simulação foi revertida; não foi executada importação global.

Foi extraído um arquivo SQL privado contendo somente os quatro produtos com correspondência exata de número/Fundo/documento do fornecedor/processo e a única dotação da ficha. A releitura pelo `SqlDumpReader` confirmou igualdade integral dos valores brutos com a origem. Arquivo no storage privado do container: `import-015-25-municipio.sql`, SHA-256 `2a3ca3e72c02da2d109022f2a58158b0d7c585c34e5b83fd1fbe22a791395063`. O estado anterior do contrato foi registrado em `before-import-015-25.json`, também privado.

Execução PHP via stdin no container `app` usou o lock operacional `contracts-reference-import` e uma transação externa envolvendo `BudgetImporter::import` e `ContractProductImporter::import`. Resultado: quatro produtos e uma dotação importados, sem conflitos; a validação de produtos/dotações foi feita antes do commit. Os importadores registraram proveniência e auditoria, e o contrato passou à versão 2. Valor inicial preservado; soma dos produtos e da dotação `2530.9200`. Outros contratos não receberam produtos.

`php artisan test --filter='ContractProductImportTest|SupplyContractFiguresTest|SupplyContractPdfTest'`: 52 testes, 248 asserções, aprovados em SQLite temporário. Também foi renderizada uma prévia de diagnóstico usando os produtos/dotação reais e textos cadastrais fictícios: PDF válido, 267.480 bytes, 11 páginas, armazenado privadamente em `diagnostic-015-25.pdf`. Essa prévia não representa emissão autenticada nem substitui a auditoria de emissão do endpoint; não houve impersonação de usuário. A configuração de administração documental existe. É necessário recarregar a ficha para usar a nova versão antes de emitir pelo botão Contrato.
