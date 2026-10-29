# Numeração anual da DFD

A numeração é atribuída pelo servidor ao salvar uma DFD nova, no formato `001/26`, `002/26`, continuando com `1000/26` depois de 999. Usa o ano atual no fuso configurado da aplicação, independentemente da data informada no documento. A sequência é única para todos os Fundos e reinicia em cada ano, preservando os contadores anteriores.

- `tb_N_DFD`: controle da sequência, com `ANO` único e `N_DFD` contendo o último inteiro utilizado naquele ano.
- `tb_DFD`: dados da DFD e número completo em `N_DFD`.
- `tb_Produtos_DFD`: produtos vinculados por `ID_DFD`, com cópia do número completo em `N_DFD`. Salvar produtos não consome números.

`Infrastructure/Dfd/DfdNumbering` exige transação ativa e bloqueia o contador anual compartilhado entre Fundos. A unicidade de `ANO` protege também a criação concorrente do primeiro contador do ano. O cadastro continua bloqueando e revalidando primeiro o ator e depois o Fundo autorizado. Incremento, gravação da DFD e auditoria pertencem à mesma transação; uma falha reverte os três. Número e ano enviados pelo cliente não controlam a geração. Editar ou excluir logicamente uma DFD não altera nem libera seu número.

Antes de incrementar, a geração confere os números de todas as DFDs, inclusive históricas e excluídas, aceitando sufixos com dois ou quatro dígitos e espaços legados. Usa o maior valor entre os números utilizados no ano e o contador. Registros históricos não são renumerados nem têm duplicidades anteriores corrigidas automaticamente.

A importação também recusa números textualmente iguais aos já existentes em qualquer Fundo, registrando a origem como conflito. Não sobrescreve a DFD existente.

A migration `2026_10_29_000002_create_dfd_number_sequences.php` cria `tb_N_DFD` quando ausente ou acrescenta `ANO` anulável e único à estrutura legada. Contadores anteriores sem ano permanecem intactos, com `ANO=null`: não existe informação suficiente para atribuí-los a um exercício automaticamente. Esses valores não são usados como contador de um ano arbitrário; os números documentais existentes em `tb_DFD` continuam sendo considerados. Vincular um saldo legado sem documentos a um exercício exige identificação explícita desse exercício antes da implantação.

A migration precisa ser aplicada no ambiente de destino antes de gravar novas DFDs. No ambiente local, foi aplicada na importação descrita abaixo. O [painel de DFD](dfd-panel-2026-10-29.md) já permite consultar e cadastrar documentos e produtos.

## Importação local executada

Foi autorizada a importação de `tb_DFD`, `tb_Produtos_DFD` e `tb_N_DFD` do arquivo local `database-example/u910323952_bdgestao_niq.sql`.

- `docker compose exec -T app php artisan contracts:import-dfd`: simulação com 10.659 produtos de catálogo, 1 contador legado, 5 DFDs e 22 produtos de DFD.
- `docker compose exec -T app php artisan migrate --path=database/migrations/2026_10_29_000002_create_dfd_number_sequences.php --force`: migration aditiva aprovada, criando a tabela de contadores anuais que estava ausente. Não foi executado o conjunto completo de migrations.
- `docker compose exec -T app php artisan contracts:import-dfd --apply`: 10.687 registros de origem; 28 importados, 10.659 de catálogo já existentes preservados e zero rejeições. Persistência, origem e auditoria foram gravadas na mesma transação.
- Conferência somente leitura: 5 DFDs e 22 produtos instalados; zero produtos órfãos ou referências a catálogo ausente. Comparação de todos os campos de DFDs/produtos contra os literais do dump: nenhuma diferença, consideradas a normalização de datas e a tradução de `ID_DFD` para a chave local. Valores monetários e quantidades históricos foram preservados como texto.

O contador da origem tem `N_DFD=5` e não contém ano. Ele foi preservado com `ANO=null` e origem em `legacy_records`, sem tomar o ID de um contador anual existente. A importação sincroniza separadamente os máximos anuais das DFDs históricas, sem consumir um número: `2025 → 4` e `2026 → 5`. A próxima criação em 2026 recebe `006/26`; a primeira de 2027 recebe `001/27`, se ainda não houver documentos ou reservas desse ano. Não foi criada uma DFD de teste no banco instalado.

Os cinco documentos mantiveram o Fundo de origem Município de Niquelândia (`fund_id=9`), inclusive `001/25`. Três áreas requisitantes corresponderam de forma única à Secretaria Municipal de Compras e Abastecimento (`department_id=16`). Duas referências históricas sem correspondência inequívoca mantiveram o texto original e `department_id=null`; não foram criadas Secretarias nem ampliadas permissões para forçar a associação. A consulta continua limitada ao Fundo da sessão e aos escopos atuais.

O importador agora inclui `tb_N_DFD` na leitura completa; `--catalog-only` continua limitado ao catálogo. Contadores com ano explícito reservam o maior valor entre origem e destino, sem reduzir um contador. Contadores sem ano são preservados sem atribuição presumida de exercício. Números documentais com sufixo de dois dígitos são considerados de 2000 a 2099 na sincronização histórica; sufixos de quatro dígitos preservam o ano completo. Reimportações da mesma origem não duplicam documentos, produtos ou contadores; versões divergentes da origem são conflitos, sem sobrescrever o cadastro existente. O payload integral da origem permanece em `legacy_records`.

Validação desta importação: os testes específicos de importação/numeração passaram com 20 testes e 106 asserções; Architecture passou com 3 testes e 4 asserções; Unit passou com 81 testes e 195 asserções. Pint dos quatro PHP alterados aprovado. Testes de importação cobrem preservação de vínculos e decimais históricos, associação inequívoca de Secretaria, contador sem ano, reserva anual explícita, reimportação sem duplicatas ou redução de contador, conflito de origem, Fundo ausente e rollback integral por falha de auditoria. PHPUnit usa somente SQLite em memória e storage/cache temporários. Não foi repetida a suíte completa, cuja falha/timeout anterior está registrada abaixo; as únicas alterações no banco instalado foram a migration aditiva e a importação autorizadas nesta seção.

Verificação final: `docker compose exec -T app php vendor/bin/phpunit --filter 'DfdLegacyImportTest|DfdNumberingTest|DfdNumberSequenceMigrationTest|DfdPanelTest|DfdCompraNavigationTest' --do-not-cache-result` aprovado com 33 testes e 270 asserções. `docker compose exec -T app php build/verify-dfd-import.php` aprovou a comparação integral dos 5 documentos e 22 produtos, sem diferenças. `git diff --check` e Pint final aprovados.

## Validação anterior da numeração

Comandos executados em Docker:

- `docker compose exec -T app php vendor/bin/phpunit --filter 'DfdNumberingTest|DfdNumberSequenceMigrationTest' --do-not-cache-result`: 13 testes e 53 asserções aprovados. Cobrem sequência entre Fundos, virada de ano, número HTTP ignorado, preservação na edição, histórico/exclusão, rollback por falha de auditoria, produtos, validação/autorização, conflito de versão, limite do contador, importação conflitante, compatibilidade da migration e unicidade anual no banco.
- `docker compose exec -T app composer check:architecture`: 3 testes e 4 asserções aprovados.
- `docker compose exec -T app composer test:unit`: 81 testes e 195 asserções aprovados.
- `docker compose exec -T app php vendor/bin/pint --test app/Infrastructure/Dfd app/Http/Controllers/DfdController.php database/migrations/2026_10_29_000002_create_dfd_number_sequences.php tests/Feature/DfdNumberingTest.php tests/Feature/DfdNumberSequenceMigrationTest.php routes/web.php`: 9 arquivos aprovados. A ordenação dos imports de `routes/web.php`, pendente da execução anterior, foi corrigida.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `git diff --check`: aprovado.
- `docker compose exec -T app composer check`: Pint global aprovado em 373 arquivos; PHPUnit apresentou falhas e foi interrompido pelo timeout de 300 segundos do Composer, sem resumo final. O conjunto iniciado tinha 860 testes; os dois testes de migration e o teste adicional de importação foram acrescentados depois do início dessa execução e passaram na execução específica final. Log local: `build/dfd-numbering-check.log`. Não se declara a suíte completa aprovada. A revisão anterior já identificara `DfdCompraNavigationTest` retornando 403 por não preparar Fundo autorizado na sessão; esse teste de navegação não foi alterado para ocultar a falha.

Os testes usaram SQLite em memória e storage/cache temporários. Não houve migrations, importações ou gravações no banco instalado, nem ensaio concorrente com processos independentes em MySQL. A exclusão mútua anual usa índice único e locks transacionais; SQLite valida os resultados sequenciais e a restrição de unicidade, mas não reproduz os locks do MySQL. Sem validação visual autenticada, pois esta alteração trata do backend de numeração.
