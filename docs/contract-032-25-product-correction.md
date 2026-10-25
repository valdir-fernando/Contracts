# Correção local dos produtos de 032/25 — 24/10/2026

Solicitação: preencher os produtos do contrato 032/25, processo 2025004192, conforme `Reference/Dados.jpg`. Ambiente efetivamente utilizado: aplicação Docker local, conexão MySQL `db`, schema `contracts`, contrato interno 3760. Não houve operação remota.

A leitura anterior encontrou zero registros vinculados a esse contrato em ambas as tabelas, inclusive excluídos logicamente. As seis origens históricas já existiam em `legacy_records`; foram usadas para preservar as descrições completas, cortadas na imagem. Nenhum registro precisou ser apagado.

Correções sobre a origem: linha histórica 2605 recebeu preço `1522.7200` e total `13704.4800`; linha 3768 recebeu item `2`; autorização da linha 4543 foi normalizada para `55234`, sem espaço inicial. Os demais valores seguiram a referência histórica correspondente à imagem. Autorização, cotação, empenho, cargo e saldos, que não possuem colunas operacionais nessas tabelas, permanecem no payload de proveniência. Os IDs da imagem são históricos; os IDs locais foram atribuídos pelo banco.

| Tabela | IDs locais | IDs históricos | Total |
| --- | --- | --- | --- |
| `tb_Produtos_Contratos` | 4436, 4437 | 2604, 2605 | 27204.4800 |
| `tb_Prtodutos_Aditivos`, ADT 1 | 5, 6 | 3767, 3768 | 18136.3200 |
| `tb_Prtodutos_Aditivos`, ADT 2 | 7, 8 | 4543, 4544 | 37107.8400 |

Foi mantida a separação operacional existente entre originais e aditivos. Nenhum produto do catálogo foi consumido ou estornado. Versão do contrato passou de 4 para 5; `updated_by` foi limpo para manutenção de sistema, sem atribuição a usuário humano. Execução `import_runs.id=17`, auditoria `contract_audits.id=3105` e evento `contract_products_corrected`, na mesma transação dos produtos.

Snapshot privado anterior: `storage/app/private/maintenance/contract-032-25-products-before-20261024-161407-ce5034dd.json`, no volume do container. SHA-256: `c3c001d54fcbdfda00eac67a68f3ae4b289b73b5c6f3d31334985b46874dcb65`. A origem histórica anterior foi preservada. SHA-256 da imagem: `ee63c6b324983bfbb00a4bbe5adc4a7b8585453fee4e30b30c7dffe8a9ef8cd2`.

Comandos executados: consultas PHP de leitura pelo bootstrap Laravel; `docker compose exec -T app php build/repair-contract-032-25-products.php` (simulação com rollback); mesmo comando com `--apply` (commit); consulta independente após commit para conferir contagens 2/4, multiplicações decimais, auditoria/evento, seis origens históricas e preservação dos demais atributos do contrato. Todos concluíram com sucesso. O script pontual usa lock `contracts-reference-import`, transação, lock do contrato e das fontes e recusa versão/estado inesperados; nova execução após aplicação é recusada.

Não foram executados testes ou migrations no banco instalado. Não houve mudança de código de produção, build ou suíte de testes nesta correção de dados.

O valor inicial salvo permanece `27204.5700` e o acumulado `82448.7300`. Portanto, os produtos originais corrigidos somam R$ 0,09 a menos que o valor inicial cadastrado; a conferência documental existente poderá impedir emissão até a revisão desse valor. Nenhum total de contrato ou vigência foi recalculado sem solicitação específica.

## Correção posterior dos valores, autorizada pelo usuário

Ainda em 24/10/2026, o usuário solicitou expressamente valor inicial `27204.4800` e acumulado `82448.6400`. Ambos foram gravados no mesmo banco local, com ajuste dos respectivos extensos para quarenta e oito e sessenta e quatro centavos. A soma decimal dos dois produtos originais e dos quatro produtos de aditivos foi conferida antes da gravação e corresponde aos valores solicitados. A divergência acima foi resolvida.

Operação PHP pontual pelo bootstrap Laravel, com lock `contracts-reference-import`, transação, lock do contrato e dos produtos, versão esperada 5 e auditoria inseparável. Versão final 6, `import_runs.id=18`, `contract_audits.id=3106` e evento `contract_values_corrected`. Snapshot privado anterior em `storage/app/private/maintenance/contract-032-25-values-before-20261024-161737-7dfb1a87.json`. Produtos, vigência e demais atributos foram preservados, exceto metadados de versão/autoria/data de atualização. Consulta independente após commit confirmou os dois valores, versão e auditoria. Nenhum teste ou migration foi executado contra o banco instalado.

## Correção das fichas conforme Dados2.jpg

Em 24/10/2026, por solicitação do usuário, foram atualizados dois registros de `tb_ValorFicha` e incluído o terceiro. O processo do aditivo 2, apresentado como `XXXXXXX` na imagem, foi confirmado como `2026007523`, conforme os produtos já cadastrados.

| ID_Ficha | Ficha | Valor | Proc_ADT | N_ADT |
| --- | --- | --- | --- | --- |
| 432 | 20250440 | 27.204,4800 | vazio | 0 |
| 1011 | 20260447 | 18.136,3200 | 2025024415 | 1 |
| 1577 | 20260447 | 37.107,8400 | 2026007523 | 2 |

Soma decimal conferida: `82448.6400`, igual ao acumulado salvo. Fundo, contrato, documento e processo original preservados. Nenhuma exclusão, alteração de produtos, valor geral ou vigência. Versão passou de 6 para 7; execução de sistema `import_runs.id=19`, auditoria `contract_audits.id=3107`, evento `contract_ficha_values_corrected`, com ator nulo explícito.

Comando PHP pontual via `docker compose exec -T app php`, bootstrap Laravel, lock `contracts-reference-import`, transação, locks de contrato/fichas, validação de versão/identidade/valores prévios e disponibilidade do ID 1577. Snapshot privado anterior: `storage/app/private/maintenance/contract-032-25-fichas-before-20261024-164617-63899d42.json`. Gravação integral comparada ao esperado antes do commit; consulta independente posterior confirmou os três registros e a auditoria. Nenhum teste ou migration no banco instalado.

## Importação da dotação da ficha 20250440

Por solicitação do usuário, em 24/10/2026 foi importado somente o registro 804 de `tb_Dotacao` do SQL de modelo, com ficha `20250440` e dotação `04.0401.10.301.7019.2245.339034(107)20250440`. Todos os campos e zeros iniciais foram preservados, incluindo Fundo Municipal de Saúde e Secretaria Municipal de Saúde. Antes da gravação não havia registro com essa ficha ou ID, inclusive no histórico excluído.

Operação pontual por PHP via Docker, usando `SqlDumpReader` para extrair literais do registro e `BudgetImporter` existente sobre arquivo privado contendo somente esse registro. Locks `contracts-reference-import` e `contracts-budget-registration`, transação, verificação de duplicidade, comparação integral antes do commit e leitura independente posterior confirmaram exatamente uma dotação ativa. Execução `import_runs.id=20`, evento `activity_events.id=79`, ação `budget_catalog_imported`, com identidade de sistema e proveniência em `legacy_records`.

Snapshot e referência privada em `storage/app/private/maintenance/budget-20250440-import-20261024-165117-20cda50a/` (`before.json` e `source.sql`). Nenhum outro cadastro, contrato ou valor alterado. Nenhuma migration, build ou teste executado contra o banco instalado.
