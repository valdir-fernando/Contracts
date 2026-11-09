# Cadastro de aditivos em tb_ADT_ATA — 09/11/2026

A tabela `tb_ADT_ATA` foi criada com os nomes solicitados, incluindo o campo adicional `Doc_ADT` para o PDF do aditivo.

| Campo | Tipo | Conteúdo |
| --- | --- | --- |
| `ID` | BIGINT UNSIGNED, autoincremental | Chave primária técnica. |
| `ATA` | VARCHAR(32), obrigatório | Número/ano da ata. |
| `Proc_ATA` | VARCHAR(64), nullable | Processo original da ata. |
| `ADT` | INT UNSIGNED, obrigatório | Número ordinal do aditivo. |
| `Data_ADT` | DATE, nullable | Data do instrumento. |
| `Proc_ADT` | VARCHAR(64), nullable | Processo do aditivo. |
| `Tipo_ADT` | VARCHAR(100), nullable | Tipo do aditivo. |
| `Doc_ADT` | LONGBLOB no MySQL; BLOB no SQLite, nullable | Conteúdo binário do próprio PDF, armazenado dentro do banco. |
| `INICIO` | DATE, nullable | Início da vigência do aditivo. |
| `FINAL` | DATE, nullable | Término da vigência do aditivo. |
| `Período` | TEXT, nullable | Descrição da duração; nome preservado com acento. |

Há índice composto em `ATA`/`ADT`, sem impor uma lista fechada de tipos ou unidade ao período. Após esclarecimento do usuário, `Doc_ADT` recebe diretamente os bytes do PDF, sem conversão em texto ou Base64. O conteúdo documental permanece privado no banco; nenhum endpoint, upload ou download foi criado nesta etapa.

As migrations `2026_11_09_000006_create_ata_amendment_register.php` e `2026_11_09_000007_add_ata_amendment_pdf_reference.php` criaram a estrutura e acrescentaram `Doc_ADT` após `Tipo_ADT`. A migration `2026_11_09_000008_store_ata_amendment_pdf_binary.php` converteu o campo inicialmente textual para `LONGBLOB` no MySQL local, conforme a intenção esclarecida pelo usuário. Ela recusa referências textuais existentes para não confundi-las com PDF e seus rollbacks não reconvertem/truncam binários. A tabela local estava vazia e a conversão concluiu normalmente.

A tabela foi inicialmente criada vazia. Após a solicitação de cadastro e anexação, recebeu os **48 aditivos** das demais pastas, com **47 PDFs** em `Doc_ADT`, totalizando **27.455.301 bytes**. O aditivo da **ATA 104/2025**, processo **2026015174**, está sem assinaturas e foi cadastrado sem anexo. A pasta **`ADITIVOS_ATAS/Nova pasta` foi totalmente excluída**, inclusive sua versão assinada da ATA 083/2025, conforme reforçado pelo usuário.

Os campos vêm dos instrumentos: número da ata/aditivo, processos de origem e do aditivo, data do instrumento, prorrogação de vigência, início, término e período. A ATA 080/2025 prevê seis meses; as demais, doze. O início da prorrogação da ATA 079/2025 permanece nulo porque o instrumento informa somente o término; não se inventou uma data documental. Metadados já conferidos na carga de produtos foram reaproveitados com conferência de identidade nas versões assinadas alternativas.

A pesquisa recursiva separou instrumentos, extratos, minutas e cópias. As versões assinadas de 106/2025 e 107/2025 substituíram as minutas apenas na seleção do anexo deste cadastro. O arquivo de 106/2025 contém o instrumento completo de três páginas, apesar do nome iniciado por `Extrato_`. Não houve substituição de arquivos originais.

A conferência encontrou 23 documentos com duas assinaturas digitais incorporadas, 23 com assinaturas visíveis conferidas na página de assinaturas e um misto (088/2025). `pdfsig -nocert -no-ocsp` verificou a integridade criptográfica das assinaturas incorporadas; não verificou a cadeia de confiança nem revogação dos certificados. Assinaturas digitalizadas ou aparências de assinatura foram examinadas visualmente, sem atribuir a elas validação criptográfica. O nome do arquivo não foi utilizado como prova de assinatura.

## Carga auditada

`atas:register-amendments` consome um JSON privado de conferência documental. Cada entrada contém `fields` (campos do cadastro, sem `ID`/`Doc_ADT`), `source_path` relativo à pasta de origem, `source_sha256` e `signatures` (`contracting`, `contracted`, `method` e página em `page`). Os nomes em `signatures` são o resultado de revisão documental por operador; o comando não identifica ou autentica automaticamente signatários. Método: `digital`, `visual`, `mixed` ou `absent`. Para anexar, as duas partes devem estar identificadas e serem distintas. Sem identificação das duas, grava somente os campos.

O manifesto local conferido está em `storage/app/private/ata-amendment-register-review.json`, no volume privado Docker. Não é versionado. Os eventos em `activity_events`, ação `ata_amendments_registered`, preservam identidade do cadastro, campos, origem, hash, método/página e signatários. PDFs não aparecem no evento ou na saída CLI.

```powershell
docker compose exec -T app php artisan atas:register-amendments storage/app/private/ata-amendment-register-review.json
docker compose exec -T app php artisan atas:register-amendments storage/app/private/ata-amendment-register-review.json --apply
```

A leitura/validação dos arquivos termina antes dos locks/escritas. O lote compartilha a trava `atas-reference-import`; cadastro, PDF binário e auditoria ficam na mesma transação. O caminho real deve permanecer dentro da pasta de origem, o conteúdo deve começar por `%PDF-` e corresponder ao SHA-256 revisado. A política recusa pastas excluídas mesmo quando o manifesto declara duas assinaturas. Identidade: `ATA` + `ADT` + `Proc_ATA`; duplicidades e divergências interrompem e revertem o lote. Um anexo ausente pode ser acrescentado após nova conferência, mas um PDF existente nunca é substituído. Repetição idêntica resultou em **48 existentes, zero inseridos e zero anexos novos**, sem novo evento.

Os 47 hashes dos binários gravados foram comparados aos PDFs originais. As tabelas `tb_ATA` (88), `tb_ADITIVOS_ATA` (48), `tb_PRODUTOS_ATA` (1.786) e `tb_N_ATA` (2 exercícios) conservaram integralmente seus registros, confirmados por comparação antes/depois. As rotinas anteriores continuam utilizando suas tabelas existentes, sem novos efeitos de vigência, produtos ou numeração. Os dados e PDFs foram gravados no **MySQL local Docker**; commits/push levam o código e a documentação, não o banco ou os documentos privados.

## Validação do cadastro e dos anexos

- `docker compose exec -T app php artisan test --filter='AtaAmendment|AtaNumbering|AtaReferenceImport|AtasOverview|ReconcileAtaProcessValidity|UpdateAtaValidity|RegisterAtaAmendments' --compact`: **85 testes, 402 asserções aprovados**, SQLite/storage temporários isolados. Os dez testes novos cobrem carga/repetição, bytes binários, apenas uma/nenhuma assinatura, anexo posterior, exclusão de pasta, arquivo alterado/externo, duplicidade, conflito e rollback por falha da auditoria.
- `docker compose exec -T app composer check:architecture`: **3 testes, 4 asserções aprovados**.
- `docker compose exec -T app composer test:unit`: **86 testes, 210 asserções aprovados**.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- Pint dos adaptadores de atas, comando e teste novos: aprovado. Conferência binária no MySQL, simulação e duas execuções com `--apply`: aprovadas.
- `docker compose exec -e COMPOSER_PROCESS_TIMEOUT=1200 -T app composer check`: Pint geral aprovado (**430 arquivos**); PHPUnit concluiu em **6min39s**, com **999 testes, 9.833 asserções, 12 falhas e 1 ignorado**. A suíte geral não está aprovada. O teste de concorrência ignorado exige MySQL descartável explicitamente isolado.

As 12 falhas são asserções de HTML de contratos, reproduzidas nos mesmos testes e linhas de asserção no código anterior à entrega (`e5dba3f`), extraído por `git archive` para diretório temporário separado, com cópia das dependências e SQLite/storage de teste isolados. A execução dirigida da base anterior terminou com **12 testes, 119 asserções e as mesmas 12 falhas**. Nenhum teste foi removido ou enfraquecido. Classes e locais: `ContractAmendmentsTest:96`, `ContractAttachmentsTest:59`, `ContractBrowsingTest:76/686`, `ContractIssuancePermissionTest:82`, `ContractWritingTest:112/426/958/1025`, `HistoricalAmendmentImportTest:146`, `HistoryAndOperationsTest:99` e `SelectedFundTest:147`. Os logs de comparação permanecem locais em `build/ata-amendment-preview`, fora do versionamento. `git diff --check` e `git diff --cached --check` passaram. Não houve deploy ou transferência do banco/documentos ao Git.

## Validação anterior da estrutura

Validação executada:

- `php artisan test --filter='AtaAmendmentDocumentTest|UpdateAtaValidityTest' --compact`: **17 testes, 66 asserções aprovados**, usando SQLite em memória. Inclui preservação exata de bytes nulos/não UTF-8 em conteúdo acima de 128 KiB e recusa de caminho textual pela migration.
- `php artisan db:table tb_ADT_ATA`: estrutura confirmada no MySQL, incluindo `Período`, `Doc_ADT`, tipos, nulabilidade e índices.
- `composer check:architecture`: **3 testes, 4 asserções aprovados**.
- `composer test:unit`: **86 testes, 210 asserções aprovados**.
- Pint da migration binária e do teste de documentos, além das migrations anteriores, e `git diff --check`: aprovados.

Na etapa inicial de schema, build e suíte geral não foram repetidos. Não houve reset de banco, exclusão de registros ou deploy externo.
