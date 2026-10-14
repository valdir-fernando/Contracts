# Numeração automática de contratos

Implementação de 13/10/2026. A numeração é independente por fundo e ano corrente do servidor. O número é atribuído somente ao salvar, dentro da mesma transação do contrato e da auditoria. Abrir o formulário não reserva números.

| Sequência | Número em 2026 | Cont_Inteiro |
| --- | --- | --- |
| 1 | 001/26 | 1 |
| 10 | 010/26 | 10 |
| 101 | 101/26 | 101 |
| 1000 | 1000/26 | 1000 |

São usados no mínimo três dígitos, sem truncar sequências superiores a 999. Em cada novo ano, o primeiro contrato de cada fundo recebe sequência 1 automaticamente; não é necessário zerar manualmente o contador.

## Persistência e concorrência

`tb_N_Contrato` contém `fund_id`, `ANO`, `FUNDO` e `NUMERO`, com unicidade por fundo e ano. A alocação bloqueia o fundo e o contador na transação existente, após autorização e validação. Considera o maior número entre o contador e os contratos existentes, inclusive excluídos logicamente. Uma falha na persistência ou auditoria desfaz também o incremento.

`ContractNumber` mantém a formatação no Domain. A porta `ContractNumbering` é consumida por `SaveContract`; `EloquentContractNumbering` implementa relógio, locks e persistência. Os contratos novos recebem `number_year` e `number_sequence`, protegidos por restrição única com o fundo. Os registros históricos permanecem sem alteração, inclusive duplicidades anteriores.

O número e a sequência aparecem como gerados ao salvar. Valores enviados pelo cliente não determinam a numeração de novos contratos. Número, sequência e fundo de um contrato com numeração automática não podem ser alterados posteriormente. Contratos históricos conservam o comportamento anterior de edição.

## Importação dos contadores existentes

O responsável confirmou que todos os contadores de origem correspondem a 2026. O arquivo legado não informa o ano, portanto a importação exige esse argumento explicitamente:

```sh
php artisan contracts:import-contract-counters 2026 --dry-run
php artisan contracts:import-contract-counters 2026
```

O arquivo padrão é `database-example/u910323952_bdgestao_niq.sql`. O importador lê os dados, sem executar o SQL legado, associa fundos sem ambiguidade e preserva a origem em `ImportRun` e `LegacyRecord`. O contador importado nunca diminui: prevalece o maior entre origem, contador atual e contratos usados. A operação registra auditoria do sistema.

Na instalação local foram aplicadas a migration `2026_10_13_000002_create_contract_number_sequences.php` e a importação de oito contadores de 2026. Uma segunda simulação confirmou estabilidade:

| ID do fundo | Última sequência |
| --- | --- |
| 9 | 24 |
| 10 | 1 |
| 11 | 58 |
| 12 | 19 |
| 13 | 11 |
| 14 | 0 |
| 15 | 3 |
| 16 | 19 |

Nenhum contrato existente foi renumerado. A importação posterior de contratos legados que colidam com uma numeração automática preserva o registro original e registra uma ocorrência, sem sobrescrever o contrato.

## Validação executada

- `composer check`: formatação aprovada; suíte com 311 testes, 2442 asserções e um teste de concorrência ignorado intencionalmente no SQLite.
- `composer check:architecture`: 3 testes, 4 asserções aprovados.
- `composer test:unit`: 56 testes, 126 asserções aprovados.
- `composer check:static`: PHPStan sem erros.
- `php vendor/bin/phpunit --filter=ContractNumberingTest --do-not-cache-result`: validação final no SQLite, 9 testes e 69 asserções aprovados.
- `php vendor/bin/phpunit -c phpunit.numbering.mysql.xml --do-not-cache-result`: 10 testes e 167 asserções aprovados no MySQL 8.4 descartável, incluindo gravações simultâneas em processos independentes, passagem de 999 para 1000 e rollback por falha de auditoria.
- `npm run build`: aprovado no contêiner Node.
- `node --check scripts/browser-smoke.cjs` e `git diff --check`: aprovados.

Os testes de concorrência exigem ambiente `testing`, `CONTRACTS_ISOLATED_MYSQL_TESTS=1` e schema descartável autorizado (`contracts_numbering_validation` ou `contracts_validation`). A configuração dedicada usa o host `contracts-numbering-test-db`; nunca direcionar essa configuração ao banco real. O contêiner de validação foi criado sem porta pública nem volume da instalação e removido após os testes.

O cenário de distinção entre séculos usa um contrato histórico de 1926 e relógio de 2026, respeitando o intervalo do tipo TIMESTAMP do MySQL. Não foi executado o fluxo completo em navegador nesta entrega.
