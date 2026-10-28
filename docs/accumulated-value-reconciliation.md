# Conciliação do valor acumulado dos contratos

Regra confirmada em 28/10/2026: **valor acumulado = valor original do contrato + soma dos valores dos aditivos vinculados**. O valor original entra uma única vez; valores negativos de aditivos são somados com seu sinal. Nenhum cálculo altera prazo, data, valor original, produtos, fichas ou os próprios aditivos.

No schema atual, a origem `TB_Contratos.VL_Total` está mapeada em `contracts.valor_total`, e `VL_Acumulado` em `contracts.valor_acumulado`. O valor `VL_ADT` da `tb_Aditivos` está normalizado em `amount`. A rotina usa essas colunas, sem alterar o dump, `legacy_records` ou o acumulado informado em cada payload de aditivo.

## Execução

```powershell
# Simular, sem gravar
docker compose exec -T app php artisan contracts:reconcile-accumulated-values

# Conferir diferenças e pendências em JSON
docker compose exec -T app php artisan contracts:reconcile-accumulated-values --json

# Corrigir somente divergências calculáveis
docker compose exec -T app php artisan contracts:reconcile-accumulated-values --apply
```

O comando é uma manutenção CLI explícita de sistema, sem usuário humano representado, e não possui endpoint HTTP. Atua sobre todos os Fundos e exercícios, nos contratos não excluídos logicamente com aditivos também não excluídos. Contratos e aditivos excluídos logicamente são preservados. Não passa a recalcular automaticamente contratos durante cadastro ou edição de aditivos; novas conciliações exigem executar o comando novamente.

Os cálculos usam strings decimais e `BigDecimal`, com quatro casas e sem floats ou soma SQL aproximada. Um acumulado nulo é preenchido quando todos os valores de entrada são conhecidos. Zero explícito é válido; ausência de valor não é considerada zero.

## Pendências preservadas

Um contrato permanece sem alteração quando:

- falta seu valor original;
- algum aditivo não possui valor informado;
- há aditivo em rascunho ou situação não confirmada;
- há histórico de aditivo vinculado ainda não importado, evitando soma parcial;
- o resultado excede o limite de DECIMAL(18,4).

O resultado identifica contratos pendentes por ID, número e motivo. O controle de origem considera aditivos excluídos para não confundir exclusão lógica com falta de importação. Não usa número do contrato isolado para relacionar registros, nem aplica sinal com base em texto do tipo de alteração.

## Transação e auditoria

`Infrastructure/Contracts/ReconcileContractAccumulatedValues` é o adaptador de manutenção. O comando usa o lock compartilhado `contracts-reference-import`. A transação bloqueia contratos em ordem de ID antes de seus aditivos; cada cálculo usa os valores atuais sob lock. A simulação utiliza a mesma leitura protegida, sem gravar.

Somente divergências alteram `valor_acumulado`, incrementam versão e limpam `updated_by` para identidade de sistema. A persistência usa `ContractMapper` e auditoria inseparável `accumulated_value_reconciled`. A auditoria registra valor anterior/posterior, versão, original, soma e IDs/versões/valores dos aditivos utilizados. Falha de auditoria reverte todos os contratos e auditorias da execução. O comando é idempotente: valores já corretos não recebem nova versão ou auditoria.

## Execução local de 28/10/2026

- Simulação: 577 contratos conferidos, 441 corretos, 81 divergentes, 55 pendentes.
- Aplicação autorizada: 81 contratos corrigidos.
- Nova simulação: 522 corretos, zero divergências calculáveis, 55 pendentes.
- Pendências: 54 contratos com algum aditivo sem valor (58 aditivos sem valor no total) e um contrato com dois aditivos ainda em conflito de importação. Nenhum desses 55 acumulados foi substituído por uma soma incompleta.

Relatórios locais: `build/accumulated-values-preview.json`, `build/accumulated-values-applied.json` e `build/accumulated-values-after.json`. A leitura final confirma idempotência. Os JSONs incluem valores anteriores e posteriores dos contratos corrigidos. A relação de contratos pendentes está em `build/accumulated-values-pending.csv`.

## Validação

- `docker compose exec -T app php artisan test --compact --filter=ReconcileContractAccumulatedValuesTest`: 7 testes, 50 asserções, aprovados. Cobrem adição exata, original contado uma vez, valor negativo, zero, acumulado nulo, dados ausentes, rascunhos, histórico pendente, limite decimal, contratos com mesmo número, exclusão lógica, preservação dos demais campos/JSON/aditivos, idempotência, concorrência de manutenção e rollback integral por falha de auditoria.
- `docker compose exec -T app vendor/bin/pint app/Infrastructure/Contracts/ReconcileContractAccumulatedValues.php app/Console/Commands/ReconcileContractAccumulatedValues.php tests/Feature/ReconcileContractAccumulatedValuesTest.php`: aprovado.
- `docker compose exec -T app composer check:architecture`: 3 testes, 4 asserções, aprovados.
- `docker compose exec -T app composer test:unit`: 81 testes, 195 asserções, aprovados.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `docker compose exec -T app php artisan contracts:reconcile-accumulated-values --json`, seguido de `--apply --json` e nova execução sem `--apply`: resultados da simulação, aplicação e conferência acima.
- `docker compose exec -T app php build/verify-accumulated-value-audits.php`: confirmou 81 auditorias e zero inconsistências com valores e versões persistidos.
- `docker compose exec -T -e COMPOSER_PROCESS_TIMEOUT=0 app composer check`: Pint aprovado nos 360 arquivos; suíte concluída em 5min29s, com 847 testes e 8.720 asserções: 834 aprovados, 12 falhas e 1 ignorado. O timeout foi desabilitado apenas neste processo, pois a execução anterior da sessão havia excedido o padrão de 300 segundos. As 12 falhas têm exatamente os mesmos nomes do log anterior `build/report-composer-check-final.log`, confirmado por comparação automática (zero diferenças); são falhas anteriores de apresentação dos detalhes/formulários. Nenhuma de suas asserções foi alterada. Log completo: `build/accumulated-values-composer-check.log`.
- `git diff --check`: aprovado.

Testes usam SQLite em memória e storage/cache temporários. Nenhum teste ou migration foi executado sobre o banco instalado. O procedimento instalado executa somente a manutenção solicitada, sob transação e auditoria. Sem conferência visual autenticada.
