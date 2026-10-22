# Coluna Contrato na tabela contracts

A migration `2026_10_22_000002_rename_contract_number_column.php` renomeia fisicamente `contracts.numero` para `contracts.Contrato`. A operação inversa também é uma renomeação; não elimina registros nem reconstrói valores. As demais tabelas, incluindo seus campos `Contrato`, não são alteradas.

## Compatibilidade

O sistema mantém `numero` como nome lógico para formulários, Domain/Application, validação, numeração automática e diferenças da auditoria. O model `Contract` traduz a leitura/escrita desse atributo para `Contrato`; `ContractMapper` faz a mesma tradução explícita ao mapear snapshots, preservando ordem, campos omitidos/null, JSON original, datas e decimais. SQL, projeções de leitura e importadores usam a coluna física `Contrato`. Filtros públicos `sort=numero` continuam aceitos e são traduzidos no adaptador.

Consultas SQL novas devem usar `Contrato`. As assinaturas HTTP e as chaves históricas `numero` em auditorias não foram renomeadas. Não há mudança no número atribuído, nas permissões, versões ou dados financeiros dos contratos.

## Aplicação local — 22/10/2026

Antes da aplicação foi gerado um snapshot privado completo dos 2.145 contratos, em `storage/app/private/maintenance/contracts-before-number-rename-20261022-183901.jsonl`, SHA-256 `bf527366207fbbd45ddfc44d54b23ab3674b98dc6aacaaf96d790006ee4072c9`.

```powershell
docker compose exec -T app php artisan migrate --path=database/migrations/2026_10_22_000002_rename_contract_number_column.php --force
```

Apenas essa migration foi aplicada. A conferência após a aplicação comparou todas as colunas dos 2.145 registros com o snapshot: cada valor permaneceu idêntico, com a única diferença de nome `numero` → `Contrato`. Confirmou ausência de `numero`, presença de `Contrato` e roundtrip do mapper sem alterações pendentes. O PDF `build/estrutura-tabela-contracts.pdf` foi atualizado com o novo nome (106 campos, uma página).

## Validação

Os testes usam SQLite em memória e storage/cache isolados. A reversão e reaplicação da migration foram testadas somente nesse ambiente descartável. O banco instalado recebeu apenas a renomeação autorizada.

Os testes adicionais verificam preservação integral das linhas, incluindo null/vazio, espaços, zeros, exclusão lógica e metadados; leitura/escrita pelo alias; tradução para Domain e persistência sem alterações indevidas.

Comandos efetivamente executados:

- `docker compose exec -T app composer check`: Pint em 340 arquivos e suíte completa com 729 testes, 7.344 asserções e 1 ignorado; passou.
- `docker compose exec -T app composer check:architecture`: 3 testes, 4 asserções; passou.
- `docker compose exec -T app composer test:unit`: 79 testes, 191 asserções; passou.
- `docker compose exec -T app composer check:static`: sem erros.
- Testes específicos de mapper, numeração, busca e edição: 69 testes, 890 asserções; passaram. Testes adicionais da coluna: 2 testes, 13 asserções; passaram, também incluídos na suíte completa.
- Pint dos PHP alterados, `git diff --check` e saúde HTTP `/up` (200): passaram.

Sem mudança de assets e sem novo build necessário. Sem conferência visual autenticada no navegador. Nenhum commit ou envio externo foi realizado.
