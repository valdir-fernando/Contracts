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

A tabela foi criada vazia. Não houve cópia automática dos 48 documentos, substituição da tabela existente `tb_ADITIVOS_ATA`, alteração de produtos, vigências ou numeração. As rotinas de importação e atualização anteriores continuam utilizando suas tabelas existentes.

Validação executada:

- `php artisan test --filter='AtaAmendmentDocumentTest|UpdateAtaValidityTest' --compact`: **17 testes, 66 asserções aprovados**, usando SQLite em memória. Inclui preservação exata de bytes nulos/não UTF-8 em conteúdo acima de 128 KiB e recusa de caminho textual pela migration.
- `php artisan db:table tb_ADT_ATA`: estrutura confirmada no MySQL, incluindo `Período`, `Doc_ADT`, tipos, nulabilidade e índices.
- `composer check:architecture`: **3 testes, 4 asserções aprovados**.
- `composer test:unit`: **86 testes, 210 asserções aprovados**.
- Pint da migration binária e do teste de documentos, além das migrations anteriores, e `git diff --check`: aprovados.

Build e suíte geral não repetidos nesta alteração de schema. Sem reset de banco, exclusão de registros, commit ou deploy externo.
