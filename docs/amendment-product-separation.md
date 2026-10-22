# Separação dos produtos de aditivos

## Estrutura

`tb_Prtodutos_Aditivos` mantém o nome solicitado e todos os campos atuais de `tb_Produtos_Contratos`, acrescidos de `ADT` e `Proc_ADT`. Possui os mesmos tipos decimais, vínculos a contrato/produto/dotação/origem, timestamps e exclusão lógica. As referências históricas de aditivo são textos, preservando valores e zeros. Esses campos já não existem como colunas da tabela original; não é necessário remover colunas ou alterar os payloads históricos.

`Domain/Contracts/ProductOrigin` classifica como aditivo um registro com `ADT` ou `Proc_ADT` preenchido, inclusive zero. Campo ausente, null, vazio ou apenas espaços não indica aditivo. A classificação é compartilhada pela importação e pelos leitores de produtos originais.

## Separação dos dados existentes

Aplicar somente a migration aditiva:

```powershell
docker compose exec -T app php artisan migrate --path=database/migrations/2026_10_22_000001_create_amendment_products.php --force
docker compose exec -T app php artisan contracts:separate-amendment-products
docker compose exec -T app php artisan contracts:separate-amendment-products --apply
```

Sem `--apply`, o comando simula e reverte todas as gravações. O comando compartilha o lock de manutenção com os importadores e trabalha numa única transação. Bloqueia contratos por ID, depois os produtos, copia os itens classificados pela origem importada e arquiva as linhas anteriores por `deleted_at`. Não apaga fisicamente registros: isso preserva referências históricas e permite inspecionar a origem da transferência. A tabela de contratos passa a ter apenas produtos originais ativos; itens já excluídos são copiados como excluídos. IDs, timestamps da cópia, dinheiro, descrições, fichas e `legacy_record_id` são preservados. O destino mantém explicitamente `ADT` e `Proc_ADT`.

Conflitos de ID ou conteúdo no destino interrompem e revertem a operação. A reexecução de uma separação concluída não duplica itens, auditorias ou versões. Para cada contrato afetado, incrementa a versão, limpa `updated_by` e grava `ContractAudit` na mesma transação. A identidade é de manutenção de sistema (`actor_id=null`, `import_run_id` explícito), sem atribuir a operação a um usuário humano. Um `ActivityEvent` registra a execução. Nenhum saldo, valor de contrato, vigência ou origem em `legacy_records` é recalculado.

O rollback da migration não elimina a nova tabela nem seu histórico. Não executar `migrate:fresh`, exclusões físicas ou restaurações contra o banco instalado.

## Importações e consultas

`contracts:import-contract-products` direciona novos itens de aditivo para `ContractAmendmentProduct`; itens originais continuam em `ContractProduct`. A busca de proveniência considera ambas as tabelas, inclusive registros arquivados, preservando idempotência e conflitos de reimportação. A auditoria distingue `product_ids` de `amendment_product_ids`, pois importações futuras podem ter IDs locais iguais nas duas tabelas.

O painel e os documentos do contrato continuam consumindo apenas produtos originais. O filtro de fichas exclusivas de aditivos consulta também a nova tabela, preservando a exclusão dessas fichas após a separação. Não há novo endpoint público ou permissão ampliada; qualquer futura interface para aditivos deverá autorizar o contrato atual antes de consultar os itens.

`ProductDisplayAvailability` considera as duas tabelas ativas na conferência de quantidades já lançadas, com os mesmos filtros de item, processo, fornecedor e contratos visíveis ao ator. O arquivamento não modifica essa disponibilidade exibida nem amplia o acesso a outros contratos.

## Validação

Os testes usam SQLite e storage/cache isolados. Cobrem estrutura, cópia exata, origem inalterada, IDs, referências de aditivo, exclusão lógica anterior, valores, auditoria de sistema, versão, simulação, reexecução, colisão no destino, rollback em falha de auditoria e destino/idempotência de futuras importações. O teste unitário puro cobre campos ausentes/nulos/vazios, espaços, zeros e referências preenchidas.

Comandos efetivamente executados:

- `docker compose exec -T app composer check`: Pint em 337 arquivos e suíte completa com 723 testes, 7.290 asserções e 1 ignorado; passou. A execução antecedeu o teste unitário adicional e o ajuste final de disponibilidade.
- `docker compose exec -T app composer test:unit`: 79 testes, 191 asserções; passou, incluindo o novo teste puro de classificação.
- `docker compose exec -T app php vendor/bin/phpunit --filter='AmendmentProductSeparationTest|ContractProductsTest' --do-not-cache-result`: validação final dos adaptadores, 46 testes e 548 asserções; passou após o ajuste de disponibilidade (inclui os 7 testes novos da separação).
- `docker compose exec -T app composer check:architecture`: 3 testes, 4 asserções; passou, inclusive após a aplicação local.
- `node --test tests/JavaScript/*.test.mjs`: 127 testes; passou.
- `docker compose run --rm --no-deps node npm run build`: passou.
- Pint dos PHP novos/alterados e `git diff --check`: passaram.

Sem conferência visual autenticada no navegador. Os testes não acessaram o banco instalado. Os comandos de manutenção abaixo foram executados explicitamente contra o ambiente local autorizado.

## Aplicação local em 22/10/2026

Antes da aplicação, foi criado um snapshot privado de `tb_Produtos_Contratos` (4.428 linhas) e dos metadados financeiros/versões dos contratos (2.145 linhas), em `storage/app/private/maintenance/amendment-products-before-20261022-130417.jsonl`. SHA-256: `6fe72a7b18ecc6443cd4274bf355aab28de36c739089384f558d25e7ab6edc9d`. O arquivo permanece no volume privado do container, sem publicação.

Apenas a migration `2026_10_22_000001_create_amendment_products.php` foi aplicada. A simulação indicou 908 cópias/arquivamentos em 335 contratos. O comando com `--apply` confirmou esses mesmos números, na execução de manutenção `import_runs.id=26`, com 335 auditorias de contrato.

A verificação após o commit comparou todas as colunas das 908 cópias com o snapshot, confirmou a preservação das 4.428 linhas de origem e deixou 3.520 produtos originais ativos. Conferiu os valores/indicadores financeiros dos 2.145 contratos e incrementos de versão apenas nos 335 afetados. A simulação posterior retornou zero cópias, zero arquivamentos e 908 registros já separados.

No contrato 005/25, processo 2025000453, o item 8855/ficha 20250084 permanece no original. O item 7701/ficha 20260058 está na nova tabela, com `ADT=1`, `Proc_ADT=2025024745` e total 198000.0000. Nenhum dado histórico foi removido fisicamente. Contratos abertos precisam ser recarregados devido ao incremento de versão.
