# Relatórios por fiscal

> A interface atual usa a [lista de contratos e filtros nos cabeçalhos](contract-reports-screen.md). O seletor Fiscal e os controles de exportação descritos abaixo pertencem à interface anterior; os filtros e contratos de exportação continuam disponíveis nas rotas existentes.

Em **Relatórios de contratos**, o campo **Fiscal** seleciona o nome registrado no contrato. Combine-o com Fundo, Secretaria, exercício e os demais filtros e clique em **Aplicar filtros**. A opção **Por fiscal** no seletor Relatório apresenta quantidade e valor inicial por nome de fiscal. A coluna **Fiscal** pode ser escolhida em Colunas do relatório.

PDF, Excel, CSV e personalizações salvas preservam o filtro. As opções do seletor vêm exclusivamente dos contratos visíveis ao usuário; a consulta e as exportações reaplicam os escopos no servidor.

O relatório usa `contracts.nome_fiscal`, preservando o histórico do contrato mesmo que o cadastro atual de fiscais mude. Nomes iguais são agrupados; não há identificação individual por CPF. Contratos sem nome aparecem como Não informado no agrupamento geral por fiscal.

Implementação: `ContractReport` valida o filtro `inspector`, aplica igualdade por nome e inclui apenas o nome na projeção do snapshot, sem CPF/RG do fiscal. `ReportController` fornece os nomes visíveis ao seletor.

Validação: `AdvancedContractSearchTest` cobre seleção, totais exatos, snapshot, exportação CSV e isolamento de escopos. `ReportSnapshotTest` verifica renderização PDF/XLSX/CSV e captura consistente de linhas e totais.

Comandos executados via Docker Compose em 19/10/2026:

- `php artisan test --filter='AdvancedContractSearchTest|ReportSnapshotTest'`: 10 testes, 129 asserções, sucesso.
- `composer check:architecture`: 3 testes, 4 asserções, sucesso.
- `composer test:unit`: 57 testes, 129 asserções, sucesso.
- `composer check`: Pint aprovado; 546 testes, 5.117 asserções, um teste ignorado pela suíte, sem falhas. A primeira execução encontrou um ajuste de estilo no teste novo, corrigido com Pint antes da execução final.
- `npm run build`: sucesso.
- `vendor/bin/pint --test lang/pt_BR/validation.php` e `git diff --check`: sucesso.

Testes executados com SQLite em memória e storage/cache temporários. Não houve validação manual em navegador nem alteração do banco instalado.
