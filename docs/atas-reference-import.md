# Importação das Atas de Registro de Preços — 09/11/2026

**PDF da ata:** `tb_ATA.Doc_ATA`, após `ATA`, armazena o conteúdo binário do próprio PDF no banco: **`LONGBLOB` nullable no MySQL**, BLOB no SQLite, sem Base64 ou conversão em texto. A migration inicial `2026_11_09_000005_add_ata_pdf_reference.php` criou o campo textual; a `2026_11_09_000009_store_ata_pdf_binary.php` aplicou a conversão conforme esclarecimento do usuário. Ela recusa referências textuais existentes e não trunca/reconverte binários no rollback. A alteração foi aplicada ao MySQL local, com os campos ainda vazios. Não foram anexados PDFs nem criados upload/download. A reimportação preserva os bytes já preenchidos.

Validação do campo binário: migration isolada aplicada no MySQL local e `php artisan db:table tb_ATA` confirmou `Doc_ATA` como `longblob` nullable. `php artisan test --filter='AtaReferenceImportTest|AtaAmendmentDocumentTest' --compact` passou com **23 testes e 85 asserções** (SQLite em memória), incluindo preservação exata de mais de 128 KiB de bytes nulos/não UTF-8 na reimportação e recusa de caminho textual pela migration. `composer check:architecture` (3 testes/4 asserções), `composer test:unit` (86 testes/210 asserções), Pint dos dois PHP e `git diff --check` aprovados. Build e suíte geral não repetidos nesta alteração de schema.

**Carga adicional concluída:** a [pasta de 2025](atas-2025-import.md) acrescentou 57 atas/889 itens, elevando a base a 82 atas/1.111 itens. O importador também aceita a planilha no pai imediato da pasta indicada e confere versões PDF adicionais. Os resultados abaixo documentam a primeira carga.

A carga local utiliza `Reference/ATAS/BD_ATAS.xlsx` e os documentos das quatro pastas `PE_*`. A migration `2026_11_09_000001_create_price_registration_atas.php` cria as três tabelas com todos os campos da planilha. A importação é manutenção CLI com identidade de sistema, executada por operador autorizado; não é um endpoint nem aceita perfil, ator ou permissões HTTP.

## Estrutura

| Tabela | Conteúdo e vínculos |
| --- | --- |
| `tb_ATA` | Os 25 campos da planilha, incluindo o nome literal `OBJETO DA ATA`. Acrescenta `ID`, `CNPJ_FORNECEDOR`, `fund_id`, versão, timestamps, exclusão lógica e proveniência/pendências. Identidade única por CNPJ do gerenciador, processo e número da ata. |
| `tb_FUNDOS_ATA` | `CNPJ_FUNDO` e `FUNDO` por ata, com `ID_ATA`, papel `gerenciador`/`participante`, vínculo opcional ao cadastro local, timestamps e exclusão lógica. Cada órgão aparece uma vez por ata; não é um catálogo global sem vínculo documental. |
| `tb_PRODUTOS_ATA` | Os 11 campos da planilha, `ID_ATA`, linha original extraída, timestamps e exclusão lógica. Item único dentro da ata; o mesmo número pode aparecer em atas distintas. Quantidade, preço e total são `DECIMAL(18,4)`. |

As duas tabelas filhas possuem chave estrangeira que impede remover fisicamente uma ata com vínculos. O rollback da migration preserva as tabelas e os dados. Os campos `ATA`, `PROCESSO`, `LICITACAO` e `N_LICIT` dos itens conservam as referências textuais previstas na planilha, além da chave estável `ID_ATA`.

O controle adicional `tb_N_ATA` mantém a numeração anual global. A importação atualiza esse contador junto com a gravação e auditoria, sem renumerar documentos. [Estrutura, sincronização e validação](ata-numbering.md).

`fund_id` é resolvido exclusivamente por CNPJ normalizado único entre órgãos locais ativos. Associação ausente/ambígua permanece nula e gera pendência; um snapshot textual não concede acesso. Nesta carga todos os órgãos foram vinculados. A estrutura contém dados pessoais completos e deve ser tratada como interna, com autorização e projeções explícitas em leitores HTTP. Na evolução posterior, o usuário definiu a consulta de atas como global: o [painel `/atas`](atas-overview.md) revalida ator/sessão e mostra apenas dados de acompanhamento, independentemente desses vínculos de Fundo. Os campos internos completos permanecem privados.

## Execução

O PHP precisa de ZIP/DOM, BCMath, PhpSpreadsheet e `pdftotext` (pacote `poppler-utils`, acrescentado à imagem Docker). No container local existente, o pacote também foi instalado antes da execução. Recriações devem utilizar a imagem atualizada.

```powershell
docker compose exec -T app php artisan migrate --path=database/migrations/2026_11_09_000001_create_price_registration_atas.php --force
docker compose exec -T app php artisan migrate --path=database/migrations/2026_11_09_000002_create_ata_number_sequences.php --force
docker compose exec -T app php artisan migrate --path=database/migrations/2026_11_09_000003_track_ata_amendments_and_validity.php --force
docker compose exec -T app php artisan atas:import-reference
docker compose exec -T app php artisan atas:import-reference --apply
```

Sem `--apply`, o comando apenas confere os documentos e o estado existente. O argumento opcional substitui a pasta de referência. O arquivo XLSX deve conservar os três blocos de campos suportados; divergências interrompem a carga.

Documentos são agrupados pelo número da ata na pasta do pregão. Números da ata e pregão são conferidos com os nomes dos arquivos. Havendo Word e PDF, o Word fornece as linhas estruturadas; campos do cabeçalho/cadastro e participantes são comparados com o PDF, desconsiderando apenas espaçamento de quebra de linha em RG/telefone e pontilhados da lista de órgãos. Quantidades, preços e totais têm suas ocorrências conferidas no conjunto das colunas numéricas do PDF por coordenadas, inclusive valores em linhas distintas. Essa comparação numérica não prova a correspondência individual de cada descrição/marca entre os dois formatos. A linha original do Word e os hashes dos dois arquivos permitem revisão documental posterior.

O PDF isolado de `PE_005-26` é interpretado no layout de seis colunas fornecido. Layouts desconhecidos, linhas inválidas, itens repetidos, documentos sem texto e divergências entre formatos interrompem a execução. O RAR não é uma segunda origem: os documentos já extraídos da pasta são utilizados uma única vez. Não é feita consulta externa ao PNCP.

A leitura/renderização dos documentos termina antes da transação. A escrita do lote e o evento `atas_reference_imported` em `activity_events` são inseparáveis; falhas revertem toda a carga. O comando possui trava de execução e as tabelas possuem restrições de unicidade. A origem é registrada por caminho relativo, tamanho e SHA-256, conferido antes/depois da leitura. Não se publicam cópias de documentos em disco público.

Repetições iguais conferem o hash, os campos e os registros filhos atuais, inclusive exclusões lógicas, sem inserir ou auditar novamente. Mudanças de origem, vínculo de órgão ou dados existentes são conflitos: o importador não sobrescreve, ressuscita ou remove registros.

Depois da [manutenção autorizada da vigência](ata-validity-update.md), a importação aceita início/término derivados quando a regra, os insumos e a versão aplicada correspondem ao registro atual. Preserva essas datas e a remoção da pendência de PNCP; os demais campos, arquivos e itens continuam sujeitos às verificações de conflito.

## Resultado local

Conexão confirmada: MySQL do Docker, host `db`, schema `contracts`. Somente a nova migration foi aplicada, sem reset, restauração ou migrations destrutivas.

| Pregão | Atas | Itens | Soma dos totais documentais |
| --- | ---: | ---: | ---: |
| 002/2026 | 12 | 90 | R$ 177.482,28 |
| 003/2026 | 11 | 127 | R$ 407.784,25 |
| 005/2026 | 1 | 4 | R$ 2.059.950,00 |
| 024/2025 | 1 | 1 | R$ 443.300,00 |
| **Total** | **25** | **222** | **R$ 3.088.516,53** |

Foram gravados 139 vínculos documentais para sete órgãos distintos e um evento de auditoria do lote. Os 24 pares DOCX/PDF e o PDF isolado da ata 057/2026 foram lidos. Nenhuma divergência aritmética de quantidade × preço foi encontrada nos totais arredondados a centavos. O total documental é preservado, mesmo que uma futura origem apresente divergência, que será registrada para revisão. Nenhum saldo, consumo, rateio por órgão, efeito contratual ou prorrogação foi deduzido desses números.

A execução posterior de `atas:import-reference --apply` confirmou **25 existentes e zero inseridos**, mantendo as contagens. A conferência trata a ordenação das chaves dos objetos JSON do MySQL como detalhe de representação e compara os valores e tipos; há teste de regressão para esse comportamento.

## Pendências documentais preservadas

- As 23 atas dos pregões 002/2026 e 003/2026 apresentam datas de 11/03/2025 ou 25/03/2025 nos próprios Word/PDF, apesar da numeração de 2026. `DATA_ATA` conserva o escrito e `quality_issues` registra `ano_data_diverge_ano_ata`.
- As atas 001/2026 e 057/2026 trazem, respectivamente, 14/01/2026 e 02/04/2026.
- Todas condicionam a vigência ao primeiro dia útil após divulgação no PNCP. Na carga inicial, as datas ficaram nulas. Posteriormente, o usuário determinou expressamente início na data da ata e término no aditivo ou após o período original: a [manutenção de vigência](ata-validity-update.md) preencheu as 25 atas com essa regra e preservou o texto de `PERIODO_ATA`.
- As referências a registro de preços do preâmbulo são registradas quando diferentes do número do pregão no cabeçalho. `N_LICIT` utiliza o pregão expresso no cabeçalho.
- O telefone da ata 015/2026 não está informado. Na ata 057/2026 faltam telefone e CPF/RG do gestor no preâmbulo cadastral. Permanecem nulos; dados de outros documentos/cadastros não completam essas ausências automaticamente.
- As marcas não especificadas na ata de lavanderia e no PDF de materiais asfálticos permanecem nulas. A quantidade `28.600` da lavanderia foi interpretada como **28.600 kg**, com total documental de R$ 443.300,00, e a quantidade `26,10` de emulsão foi preservada com quatro casas decimais.

## Validação

Os testes usam SQLite em memória e arquivos fictícios no storage temporário isolado do processo. Cobrem simulação, importação e repetição, valores decimais/quantidade brasileira, órgãos, proveniência, exclusão lógica, conflito de origem/edição, falha de auditoria, ausência de carga parcial, planilha incompatível, linha desconhecida, PDF isolado, comparação com PDF e valores quebrados em linhas distintas.

Comandos executados:

- `docker compose exec -T app php artisan test --filter=AtaReferenceImportTest --compact`: **14 testes, 54 asserções aprovados** na versão final.
- `docker compose exec -T app composer check:architecture`: **3 testes, 4 asserções aprovados**.
- `docker compose exec -T app composer test:unit`: **81 testes, 195 asserções aprovados**.
- `docker compose exec -T app vendor/bin/pint app/Infrastructure/Atas app/Console/Commands/ImportAtas.php database/migrations/2026_11_09_000001_create_price_registration_atas.php tests/Feature/AtaReferenceImportTest.php`: aprovado; Pint dos dois PHP posteriormente ajustados também aprovado.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `git diff --check`: aprovado.
- `docker compose exec -T app composer check`: Pint geral aprovado (394 arquivos). PHPUnit iniciou 927 testes e ultrapassou 80% da execução, com falhas fora dos testes de atas, mas o subprocesso foi encerrado pelo limite de **300 segundos do Composer** antes do resumo final. Portanto, a suíte completa não está aprovada. Essa execução foi iniciada antes da última regressão de ordenação JSON; os 14 testes específicos passaram depois do ajuste final.
- `docker compose exec -T app php artisan test --filter='ContractAmendmentsTest|ContractBrowsingTest|SelectedFundTest' --compact`: **48 aprovados, 4 falhas, 679 asserções**. A conferência reproduziu falhas de apresentação em `test_contract_summary_embeds_paginated_amendments_with_actions_and_contract_scope`, `test_contract_information_panel_shows_saved_updated_values`, `test_detail_renders_all_fields_masks_personal_documents_and_escapes_source` e `test_editing_a_contract_preserves_its_existing_manager`. São expectativas de HTML dos detalhes/aditivos/formulário de contratos; esses testes e telas não foram modificados nesta entrega. Falhas nessas mesmas áreas já constavam do histórico de validação de `architecture.md` em 27/10/2026. A execução dirigida não substitui a conclusão da suíte geral.

Também foram executados `migrate:status`, a migration isolada, a simulação documental e a carga/reimportação explícitas no banco local. As primeiras conferências interromperam a execução enquanto os leitores eram ajustados a células mescladas, pontilhados, quebras de CPF, ordem dos objetos JSON no MySQL e posicionamento das palavras no PDF. Esses ajustes não corrigem nem reescrevem documentos originais. Testes e migrations destrutivas não foram executados no banco local. Não houve validação visual autenticada, deploy ou commit.
