# Numeração anual única das atas

**Estado após importar a pasta de 2025:** `ANO=2025, NUMERO=109` e `ANO=2026, NUMERO=58`; próximo número de 2026: **059/26**. A 058/2026 foi encontrada nessa pasta e preservada. [Carga adicional e validação](atas-2025-import.md). Os resultados de instalação abaixo correspondem à primeira etapa.

`tb_N_ATA` controla uma sequência por ano, compartilhada por todos os Fundos. Segue o controle de contratos, com a diferença de que não possui `fund_id`, `FUNDO` ou origem de contador por órgão.

| Campo | Finalidade |
| --- | --- |
| `ID` | Chave autoincremental |
| `ANO` | Exercício com unicidade no banco |
| `NUMERO` | Último inteiro utilizado ou reservado naquele exercício |
| `created_at`, `updated_at` | Datas de criação e atualização do controle |

Um ano novo possui seu próprio contador, começando em zero antes da primeira emissão. A numeração será `001/26`, `002/26`, …, `999/26`, `1000/26`; em 2027 começa em `001/27`. Os contadores dos exercícios anteriores são preservados. O rollback da migration também preserva a tabela.

## Alocação e importação

`Infrastructure/Atas/AtaNumbering::next()` utiliza o ano atual do servidor, exige transação ativa e bloqueia o contador anual antes das atas. A unicidade de `ANO`, a inserção protegida e o lock serializam inclusive a primeira reserva simultânea de um ano. A consulta de histórico inclui todos os Fundos e os registros excluídos logicamente; o maior número já utilizado nunca é reutilizado. Contadores maiores que o histórico também são preservados. Valores fora da faixa inteira suportada são rejeitados sem transbordamento.

O cadastro de atas ainda não possui fluxo funcional de gravação na interface. Quando implementado, deverá bloquear/revalidar o ator e o órgão autorizado, chamar `next()` e gravar ata, contador e auditoria **na mesma transação**, sem aceitar ano/número do navegador como controle da geração. O método de reserva não autoriza o cadastro por si só e não possui endpoint para consumir números isoladamente.

`atas:import-reference --apply` atualiza os mínimos dos contadores dos anos importados antes da persistência documental e audita essas reservas junto com a carga. Os Fundos são resolvidos e bloqueados antes dos contadores; os anos são bloqueados em ordem crescente, antes das atas. Uma falha de conflito, persistência ou auditoria reverte também os contadores. Simulações não alteram números. Atas históricas conservam seus números e datas originais.

Para o histórico, o exercício vem do sufixo do número da ata, aceitando `057/2026`, `057/26` e espaços legados; sufixos de dois dígitos representam 2000–2099. `DATA_ATA` não define o exercício do contador, pois os documentos importados incluem datas divergentes da numeração. Textos não reconhecidos são contabilizados na conferência, sem atribuir um exercício arbitrário.

## Instalação e estado local em 09/11/2026

```powershell
docker compose exec -T app php artisan migrate --path=database/migrations/2026_11_09_000002_create_ata_number_sequences.php --force
docker compose exec -T app php artisan atas:sync-numbering
docker compose exec -T app php artisan atas:sync-numbering --apply
```

O comando de sincronização é manutenção CLI explícita com identidade de sistema. Sem `--apply`, apenas simula. Atualizações necessárias são auditadas por `ata_numbering_synchronized` dentro da mesma transação; repetições iguais não alteram nem auditam novamente.

A migration foi aplicada somente no MySQL local do Docker. A simulação inicial encontrou o máximo 57 em 2026, sem números não reconhecidos. A sincronização gravou `ANO=2026, NUMERO=57`, com um evento de auditoria. A execução repetida confirmou `changes=[]`. Naquela etapa, o próximo número era `058/26`, sem consumo durante a instalação. A carga adicional posteriormente encontrou a própria 058/2026 e avançou o contador a 58, como indicado acima.

## Validação

- `docker compose exec -T app php artisan test --filter='AtaNumberingTest|AtaReferenceImportTest' --compact`: **24 testes, 101 asserções aprovados**, com SQLite em memória e storage temporário. Inclui compartilhamento entre órgãos, virada anual, passagem de 999 para 1000, histórico/exclusão lógica, ano do número em vez da data, sincronização idempotente, trava de transação, unicidade anual, limites e rollback por falha de auditoria na reserva, na gravação e na importação.
- Pint dos seis PHP envolvidos: aprovado.
- `docker compose exec -T app composer check:architecture`: **3 testes, 4 asserções aprovados**.
- `docker compose exec -T app composer test:unit`: **81 testes, 195 asserções aprovados**.
- Migration isolada, simulação, aplicação e repetição de `atas:sync-numbering`: aprovadas no banco local, sem alocar número novo nem renumerar o histórico.

Não houve teste concorrente com dois processos em MySQL nesta etapa. A suíte geral e o build não foram repetidos nesta mudança de backend; a [validação da importação](atas-reference-import.md#validação) registra o build aprovado, as falhas de apresentação de contratos e o timeout do Composer na execução anterior. Nenhum teste foi executado contra o banco operacional. Sem commit ou deploy externo.
