# Importação do banco de modelo — 19/10/2026

Fonte local privada: `database-example/u910323952_bdgestao_niq.sql`. Destino autorizado: MySQL Docker `db`, schema `contracts`. O SQL do arquivo não é executado: `SqlDumpReader` extrai somente valores literais. Não se restaura o dump por cima do schema Laravel. Usuários, senhas e fotos atuais são preservados.

Backup antes das escritas: `build/supply-preview/before-model-import-20261019.sql`, 19.549.202 bytes, SHA-256 `F1812EFC1D7A4BB82C2DDFECEF63751BCA3E011EAFE0C34CCAA4DBC6E526DAD8`, diretório ignorado pelo Git. A migration aditiva `2026_10_19_000001_track_imported_contract_products` acrescenta referência opcional única à origem; mantém todos os campos e registros existentes.

O comando novo `php artisan contracts:import-contract-products {file?} {--dry-run}` importa os lançamentos da `tb_Produtos_Contratos` para o painel de produtos. Exige correspondência única de número, nome normalizado do Fundo, documento válido do fornecedor e processo. Contratos excluídos logicamente não são elegíveis. Linhas sem vínculo confirmado, ambíguas ou com decimais/campos inválidos ficam preservadas em `legacy_records`, com ocorrência em `import_issues`; não são vinculadas por aproximação.

Os valores brasileiros são convertidos em strings decimais exatas, mantendo o conteúdo bruto na origem e nos campos compatíveis. O total original de cada lançamento é preservado; divergências de multiplicação continuam sujeitas à validação de emissão do PDF. A importação não redefine o total do contrato, `items_managed`, saldos do catálogo, efeitos de aditivos ou vigência. Como o saldo do modelo já considera distribuições anteriores, `product_id=null` impede nova baixa e devolução indevida. A origem importada é identificada por `legacy_record_id` e não é recriada mesmo após exclusão lógica; alterações posteriores no arquivo são conflitos, não sobrescritas.

Cada importação usa lock operacional de cache, transação, `ImportRun`, `LegacyRecord`, evento de sistema com ator nulo e auditoria por contrato alterado. A versão do contrato é incrementada para invalidar editores abertos. A simulação reverte produtos, origem, auditoria, versão e relatório, inclusive dentro de transação de teste.

## Conferências executadas

- `contracts:import --dry-run`: 2.145 contratos já existentes, nenhum novo e nenhum rejeitado; catálogos atuais preservados. O importador genérico sinalizou diferenças em 1.221 cadastros existentes; não foi executado em modo de gravação para sobrescrevê-los.
- `contracts:import-products`: 3.307 existentes iguais; nenhum novo/conflito.
- `contracts:import-budgets`: 1.733 existentes iguais; nenhum novo/conflito.
- `contracts:import-ficha-values`: 1.462 existentes iguais; nenhum novo/conflito.
- Migration específica aplicada com `migrate --path=database/migrations/2026_10_19_000001_track_imported_contract_products.php --force`.
- `contracts:import-contract-products --dry-run`: 4.553 lidos, 4.427 elegíveis, 125 sem vínculo confirmado, 1 inválido, nenhum conflito ou vínculo ambíguo.
- `contracts:import-contract-products`: 4.427 produtos importados em 839 contratos, 839 auditorias, todos com proveniência. Os 125 sem vínculo e 1 inválido foram preservados para revisão; nenhum vínculo foi forçado.
- Conferência somente leitura do contrato `015/25`, Município de Niquelândia, tipo Fornecimento: 4 produtos (itens 5, 12, 11 e 13), ficha `20250031`, soma exata `2530.9200`, igual ao total original do contrato. A lista inicial de fichas retorna `20250031`.
- `contracts:import-history ... --dry-run`: 5.538 registros históricos simulados, 130 sem vínculo; simulação revertida. Não foi executado em modo de gravação: o histórico separado de aditivos/itens não é necessário para disponibilizar os produtos no painel.

## Validação do código

- `php artisan test --filter=ContractProductImportTest`: 5 testes, 36 asserções; passou. Cobre identidade, isolamento, ambiguidade, decimais inválidos, simulação, auditoria, saldos/totais preservados, idempotência, mudança da fonte e exclusão lógica.
- `php artisan test --filter=ContractProductsTest`: 30 testes, 346 asserções; passou.
- `composer check:architecture`: 3 testes, 4 asserções; passou.
- `composer test:unit`: 75 testes, 165 asserções; passou.
- `composer check`: Pint passou em 299 arquivos; PHPUnit executou 596 testes e 5.525 asserções, com 3 falhas preexistentes de expectativas de nomes em maiúsculas (`AuditCorrectionsTest`, `CatalogDelegationTest`, `SupplierAssistanceTest`) e 1 teste ignorado. Os testes da importação e dos produtos passaram também na suíte completa. Expectativas e normalizações anteriores foram preservadas.
- `git diff --check`: passou.

Testes executados no SQLite temporário/em memória da suíte. Nenhum teste foi executado no MySQL real. Não houve alteração de frontend nesta importação; build não repetido.
