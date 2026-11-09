# Produtos dos aditivos de atas — 09/11/2026

`tb_PRODUTOS_ATA` recebeu, imediatamente após `N_LICIT`, os campos solicitados:

| Campo | Tipo e significado |
| --- | --- |
| `ADITIVO` | Inteiro não negativo; zero identifica os itens originais, 1 identifica o primeiro aditivo. |
| `DATA_ADT` | Data do próprio instrumento, conforme o fecho documental; não é o início da prorrogação. Nula para itens originais. |
| `PROC_ADT` | Processo administrativo do aditivo; preserva `PROCESSO` como processo original da ata. Nulo para itens originais. |

Acrescentado também `ID_ADITIVO`, chave estrangeira restritiva para `tb_ADITIVOS_ATA`. `ID_ATA` admite nulo quando o original está ausente; o produto conserva ata/ano, processo, pregão e vínculo documental obrigatório no importador. A unicidade distingue item original de item do aditivo e impede repetir o item no mesmo aditivo. Registros existentes, exclusão lógica e proveniência foram preservados.

A migration `2026_11_09_000004_add_amendment_products_to_atas.php` instala o índice substituto antes de retirar o antigo, pois o MySQL também o utiliza para a FK de `ID_ATA`. A primeira execução local encontrou essa dependência de índice; a migration foi corrigida para retomar DDL parcial com segurança e concluiu sem remover dados. O rollback preserva estrutura e histórico.

## Leitura e manutenção

`Infrastructure/Atas/AtaAmendmentProductReader` confere caminhos/hashes dos documentos já registrados e executa `scripts/atas/extract_amendment_tables.py` antes da transação. A extração utiliza pdfplumber para PDFs digitais e Poppler/Tesseract/OpenCV para digitalizados: corrige inclinação, reconhece a grade, lê células e mantém números como strings decimais. Não utiliza LLM nem serviço externo para enviar os documentos. As dependências Python estão fixadas em `docker/php/ata-extract-requirements.txt`, em ambiente isolado `/opt/ata-extract`; foram instaladas no container local e adicionadas ao Dockerfile. A imagem completa não foi reconstruída nesta etapa.

`AtaAmendmentProductParser` reconhece cabeçalhos, continuações e totais, normaliza quantidades/preços sem ponto flutuante e sinaliza divergências aritméticas sem corrigir o documento. Arquivos sem itens discriminados não geram produtos presumidos. Referências a tabelas sem leitura válida e identificação financeira inválida ficam pendentes para conferência.

Houve conferência visual de extrações com erro de OCR. As correções ficam privadas em `storage/app/private/ata-product-reviews`, vinculadas ao SHA-256 do documento e hash da tabela extraída. O leitor rejeita a revisão se a extração mudar. `source_row` guarda página, células, método, hash e revisão/original OCR quando aplicável. Na 013/2025, os 160 itens foram confrontados com as cinco páginas e a ata original, cuja renovação integral sem alterações é expressa no aditivo; o total de R$ 628.008,28 também foi conferido. As outras revisões corrigiram reconhecimento nas atas 041/2025, 069/2025 e 079/2025.

Cache e intermediários OCR permanecem em `storage/app/private/ata-amendment-products` e `storage/app/private/ata-product-ocr`. Não são publicados. A importação é manutenção CLI de sistema com trava compartilhada das atas, revalidação de fontes/vínculos, locks em atas → aditivos → produtos e auditoria inseparável da gravação. Falha da auditoria reverte produtos e manifestos. Repetições iguais não duplicam registros; conflitos ou exclusões não são sobrescritos. Quando a ata original for cadastrada e o aditivo vinculado pela rotina de vigência, uma repetição associa os produtos pendentes ao pai, com auditoria e sem reinserção.

```powershell
docker compose exec -T app php artisan migrate --path=database/migrations/2026_11_09_000004_add_amendment_products_to_atas.php --force
docker compose exec -T app php artisan atas:import-amendment-products
docker compose exec -T app php artisan atas:import-amendment-products --apply
```

Sem `--apply`, simula. Erros individuais de leitura são listados em `pending`; o comando retorna falha quando houver pendências, inclusive se um lote dos demais documentos válidos tiver sido aplicado. Conflitos de persistência revertem toda a transação do lote preparado. `products_import` em `tb_ADITIVOS_ATA` registra insumos, contagem, total e hash do conteúdo importado.

## Resultado local

| Verificação | Resultado |
| --- | ---: |
| Aditivos conferidos e manifestos registrados | 48 |
| Aditivos com produtos discriminados | 47 |
| Produtos de aditivos inseridos | **675** |
| Produtos vinculados a atas existentes | 542 |
| Produtos aguardando a ata original, vinculados ao aditivo | 133 |
| Itens originais preservados | 1.111 |
| Total de registros em `tb_PRODUTOS_ATA` | **1.786** |
| Produtos de aditivo sem número/data/processo | 0 |
| Pendências de leitura após conferência | 0 |
| Eventos `ata_amendment_products_imported` | 1 |

O aditivo da **080/2025** renova 50% e informa valor estimado, mas não enumera produtos, unidades, quantidades e preços individuais. Seu manifesto registra `sem_produtos_discriminados`, sem criação de item fictício. Os 133 produtos sem pai correspondem a 21 dos 22 aditivos cujas atas originais ainda estão ausentes; o outro é justamente a 080/2025.

Total documental dos produtos dos aditivos: **R$ 18.450.629,03**. Foram preservadas e sinalizadas as divergências já documentais dos itens 39, 207 e 261 da 020/2025. Nenhum efeito financeiro foi deduzido desses totais.

Os itens dos aditivos são histórico separado. A consulta do painel continua considerando os itens originais, sem somar novamente as renovações: **82 atas, 1.111 itens, R$ 33.568.681,74, 30 vigentes e 52 vencidas** em 09/11/2026. A importação das atas originais também confere seus filhos separadamente dos aditivos. Vigências e contadores não foram alterados nesta carga.

Repetição aplicada: **675 existentes, zero inserções, zero novos vínculos e zero mudanças/auditorias adicionais**. Consulta somente leitura confirmou ordem física das colunas, metadados completos, manifestos, totais e painel. Relatórios privados ficam em `build/ata-amendment-preview/products-*.json`.

## Validação executada

- Testes dirigidos de atas, vigências, painel e produtos: **68 testes, 342 asserções aprovados**, usando SQLite em memória e storage temporário. Incluem PDF digital e digitalizado com leitura real, origem alterada, coexistência de item original/aditivo, pai ausente seguido de vínculo, idempotência, auditoria com rollback, conflitos/exclusão, proteção dos valores do painel e reimportação dos originais após produtos de aditivo.
- `composer check:architecture`: **3 testes, 4 asserções aprovados**.
- `composer test:unit`: **86 testes, 210 asserções aprovados**.
- Pint dos nove PHP envolvidos e formatação do extrator Python: aprovados.
- Migration isolada, simulação, aplicação, repetição e conferência somente leitura no MySQL local: concluídas.
- `git diff --check`: aprovado.

Build e suíte geral não foram repetidos nesta mudança de backend; limitações anteriores permanecem na [importação inicial](atas-reference-import.md#validação). Sem testes destrutivos no banco operacional, alteração dos PDFs originais, commit ou deploy.
