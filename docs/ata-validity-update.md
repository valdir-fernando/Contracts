# Atualização da vigência das atas pelos aditivos — 09/11/2026

**Aditivos não assinados:** a árvore `ADITIVOS_ATAS/Nova pasta` foi excluída das prorrogações e dos produtos por determinação do usuário. Os 27 processos foram conferidos pelas cópias das atas originais, com 21 vigências confirmadas e seis atas ausentes cadastradas. [Datas finais e validação da base com 88 atas](ata-process-validity-review.md).

**Complementação posterior:** a [importação da pasta de 2025](atas-2025-import.md) elevou a base a 82 atas, vinculou 26 aditivos e deixou 22 pendentes por ausência da origem. Todas as 82 atas têm vigência definida. Os resultados abaixo documentam a primeira etapa, anterior à disponibilização dessa pasta.

Foi aplicada a regra administrativa solicitada pelo usuário: `INICIO_ATA = DATA_ATA`; `FINAL_ATA` recebe o término expresso no aditivo correspondente ou, na ausência de aditivo, a data da ata acrescida do período original. Essa instrução substitui, para esta manutenção, a pendência anterior de início condicionado à publicação no PNCP. Não há avaliação jurídica automática nem consulta externa.

## Regra e histórico

`Domain/Atas/AtaTerm` calcula o aniversário civil do período original, sem subtrair um dia. Exemplos: 11/03/2025 + 12 meses = **11/03/2026**; 31/01/2025 + 1 mês = **28/02/2025**; 29/02/2024 + 12 meses = **28/02/2025**. Períodos em dias usam a quantidade expressa. O cálculo lê a duração inicial do texto, não soma condições futuras de prorrogação. Período não reconhecido gera pendência e não recebe uma duração arbitrária.

Para aditivos, o término é copiado literalmente da cláusula de vigência, aceitando datas numéricas, por extenso e documentos que indicam apenas o termo final. O início do período prorrogado não substitui a data original da ata. Havendo vários aditivos, vale o maior ordinal confirmado; termos do mesmo ordinal com finais divergentes bloqueiam a atualização daquela ata. A fonte já registrada permanece considerada mesmo que o PDF seja retirado da pasta posteriormente.

`DATA_ATA`, número da ata, período textual original, itens, preços, saldos, órgãos e documentos originais são preservados. A alteração afeta somente início/término, versão, timestamp, a remoção da pendência antiga de PNCP e os metadados internos de derivação. A divergência entre ano do número e ano escrito no documento continua registrada.

## Persistência e segurança

A migration aditiva `2026_11_09_000003_track_ata_amendments_and_validity.php` acrescenta:

- `tb_ADITIVOS_ATA`: número/ano da ata, ordinal do aditivo, processos de origem e do aditivo quando identificados, pregão, CNPJ do fornecedor quando identificado, datas da prorrogação, origem relativa, SHA-256, tamanho, método de extração, pendências e vínculo opcional `ID_ATA`. Origem única e chave estrangeira com exclusão física restrita.
- `tb_ATA.validity_basis`: JSON privado com regra aplicada, data/período utilizados, datas resultantes, versão aplicada e referências/hashes dos aditivos.

O vínculo exige número **e ano** da ata, processo de origem e pregão compatíveis, além de conferir o CNPJ do fornecedor quando extraído. Não associa por número isolado, nome da empresa, Fundo ou nome da pasta. Identificação ambígua, leitura pendente ou divergência de processo bloqueiam o cálculo por período para os candidatos envolvidos: um aditivo conhecido não é simplesmente ignorado.

Quando a ata original está ausente, o documento é registrado como `ata_ausente`, sem criar uma ata incompleta ou alterar atas de outro ano. A rotina pode ser repetida após o cadastro da origem para concluir o vínculo. Atas excluídas logicamente permanecem preservadas.

`Infrastructure/Atas/UpdateAtaValidity` processa documentos antes da transação e bloqueia atas/aditivos em ordem de ID. `TransactionGuard` protege a escrita. Registro dos documentos, datas, versões e evento `ata_validity_updated` são gravados na mesma transação; falha de auditoria reverte o lote. Trata-se de manutenção CLI explícita com identidade de sistema, sem endpoint ou ator recebido por HTTP. A trava é compartilhada com a importação das atas.

Repetições iguais não incrementam versão, não duplicam documentos e não geram outro evento. Alteração do PDF já registrado ou edição manual posterior das datas exige conferência e não é sobrescrita silenciosamente. A importação das atas originais reconhece a derivação autorizada na versão correspondente, preservando as datas aplicadas e mantendo as demais verificações de origem/campos/itens.

## Leitura dos documentos

A leitura percorre recursivamente PDFs em `Reference/ATAS/ADITIVOS_ATAS`. Utiliza `pdftotext` e, para ausência/divergência do texto extraído, rasterização com Poppler e OCR local em português com Tesseract. As dependências `tesseract-ocr` e `tesseract-ocr-por` foram acrescentadas à imagem Docker e instaladas no container local existente. A chave da cache privada considera hash e nome do arquivo; os hashes são conferidos antes/depois da leitura. Cache de dados extraídos fica em `storage/app/private/ata-amendment-cache`; imagens temporárias geradas pelo leitor são removidas ao final de cada OCR.

Foram lidos **48 PDFs**, com **11 extrações por OCR**, todos referentes a atas de **2025**. Houve conferência visual da ata 104/2025 (MEDRADA): o texto oculto do PDF misturava um modelo da ata 107/2025, mas a página visível e o OCR identificam 104/2025, processo 2025005312 e término em 20/10/2027. O conteúdo documental também confirmou 053/2025, apesar de a pasta conter `053-26`. Nenhuma pasta/documento foi renomeado.

Os 48 términos foram identificados. Um CNPJ de fornecedor não foi extraído de forma estruturada e permanece nulo; também há processos de aditivo não explicitados no padrão reconhecido. Isso não substitui a exigência de correspondência única de ata/ano, processo base e pregão para vinculação. Documentos e metadados completos permanecem internos; o painel continua recebendo somente sua projeção explícita de acompanhamento.

## Execução local e resultado

```powershell
docker compose exec -T app php artisan migrate --path=database/migrations/2026_11_09_000003_track_ata_amendments_and_validity.php --force
docker compose exec -T app php artisan atas:update-validity
docker compose exec -T app php artisan atas:update-validity --apply
```

Sem `--apply`, somente simula a persistência; a cache privada de extração pode ser preenchida. O argumento opcional substitui a pasta. A simulação e a aplicação encontraram:

| Resultado | Quantidade |
| --- | ---: |
| PDFs de aditivos registrados | 48 |
| Aditivos vinculados a atas existentes | 0 |
| Aditivos pendentes por ausência da ata de 2025 | 48 |
| Atas existentes atualizadas pelo período original | 25 |
| Atas sem datas após aplicação | 0 |
| Eventos de auditoria deste lote | 1 |

A tabela possuía somente as 25 atas numeradas em 2026 da carga anterior. **Nenhum dos 48 aditivos se refere a essas atas.** Foi solicitado ao usuário o caminho das atas originais de 2025, necessário para completar a aplicação dos aditivos às respectivas atas. Os registros pendentes já conservam as referências para essa etapa.

Datas aplicadas às atas atualmente cadastradas:

| Atas | Quantidade | Início | Término |
| --- | ---: | --- | --- |
| 006/2026 a 017/2026 | 12 | 11/03/2025 | 11/03/2026 |
| 038/2026 a 048/2026 | 11 | 25/03/2025 | 25/03/2026 |
| 001/2026 | 1 | 14/01/2026 | 14/01/2027 |
| 057/2026 | 1 | 02/04/2026 | 02/04/2027 |

O painel global, na referência de 09/11/2026, passou a mostrar **2 vigentes, 23 vencidas e zero sem vigência definida**. As datas de 2025 das primeiras 23 atas foram mantidas conforme os documentos, sem corrigir o ano para 2026. Os 222 itens, os 139 vínculos com órgãos, o contador anual 57 e o valor de **R$ 3.088.516,53** foram preservados.

A repetição de `atas:update-validity --apply` confirmou **zero novos aditivos, zero atualizações e 25 atas inalteradas**. `atas:import-reference --apply` também confirmou 25 existentes e zero inseridos, preservando as novas datas. Relatórios locais da simulação, aplicação e repetição ficam em `build/ata-amendment-preview/`, ignorado pelo Git; não são publicados pela aplicação.

## Validação executada

- `docker compose exec -T app php artisan test --filter='AtaTermTest|AtaValidityTest|UpdateAtaValidityTest|AtaNumberingTest|AtaReferenceImportTest|AtasOverviewTest|AtasOverviewDataTest' --compact`: **54 testes, 285 asserções aprovados**. SQLite em memória e storage temporário, incluindo PDF fictício digitalizado/OCR, datas por extenso/termo final, vínculos entre anos/processos, pendência seguida de vinculação, período original, mês curto/ano bissexto, conflitos de mesmo ordinal, exclusão lógica, falha de auditoria e reimportação após a manutenção.
- `docker compose exec -T app composer check:architecture`: **3 testes, 4 asserções aprovados**.
- `docker compose exec -T app composer test:unit`: **86 testes, 210 asserções aprovados**.
- Pint dos nove PHP envolvidos: aprovado.
- Migration isolada, simulação, aplicação e repetição no banco local do Docker: aprovadas.
- Conferência somente leitura da base/painel: totais, datas, 48 pendências e um evento de auditoria confirmados.
- `git diff --check`: aprovado.

A suíte geral e o build não foram repetidos nesta mudança de backend. As falhas de apresentação e o timeout anteriores permanecem registrados na [validação da importação](atas-reference-import.md#validação). Não houve testes contra o banco operacional, migrations destrutivas, alteração financeira, commit ou deploy externo.
