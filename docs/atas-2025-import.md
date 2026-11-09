# Complementação das atas de 2025 — 09/11/2026

O caminho informado pelo usuário, `Reference/ATAS/2025`, foi importado no MySQL local do Docker (`db`, schema `contracts`): **57 atas, 889 itens e 68 vínculos documentais de órgãos**. São 56 atas numeradas em 2025 e a **058/2026**, cujo ano/data foram preservados apesar da localização na pasta de 2025.

## Carga adicional

| Pregão | Atas | Itens | Total documental |
| --- | ---: | ---: | ---: |
| 001/2025 | 1 | 12 | R$ 1.988.303,00 |
| 002/2025 | 1 | 4 | R$ 1.391.085,50 |
| 004/2025 | 24 | 469 | R$ 6.019.523,56 |
| 005/2025 | 24 | 384 | R$ 4.663.175,65 |
| 015/2025 | 4 | 13 | R$ 12.809.360,00 |
| 017/2025 | 2 | 6 | R$ 3.559.024,00 |
| 020/2025 | 1 | 1 | R$ 49.693,50 |
| **Total** | **57** | **889** | **R$ 30.480.165,21** |

O importador aceita `BD_ATAS.xlsx` no diretório informado ou no pai imediato. A descoberta continua restrita aos documentos diretamente nas pastas `PE_*`; anexos de convocação e cópias em subpastas não constituem novas atas. O PDF assinado adicional da 058/2026 também foi conferido e registrado, sem duplicação. A 038/2025 possui somente DOCX. A proveniência inclui o prefixo `2025/`.

A leitura reconhece cabeçalho de item `ID`, variantes `VL UNIT`, `VALOR UNT` e `VALOR TOTAL`, `O(A)` no preâmbulo, CNPJ com ponto antes da filial e quebras de `e-mail`. A comparação aceita quebra após hífen em endereço. A conferência numérica por coordenadas distingue quantidade de marca/descrição numéricas, fornecedor antes dos itens e término em “A listagem”. As verificações de divergência, integridade e rollback permanecem.

Quatro documentos do PE 015/2025 usam “terça-feira de 21 de outubro de 2025” ou “quinta-feira de 16 de abril de 2026”. Após ampliar o reconhecimento, uma complementação de sistema conferiu novamente Word/PDF, hashes, campos e filhos anteriores sob transação e preencheu exclusivamente a data, com atualização de hash/pendências/versão e evento `ata_source_dates_completed`. Datas: **103/2025, 104/2025 e 105/2025 em 21/10/2025; 058/2026 em 16/04/2026**. A manutenção normal aplicou em seguida as vigências e respectivas versões/bases auditadas.

Os valores documentais foram preservados. A **020/2025 do PE 004/2025** contém divergências de quantidade × preço nos itens **39, 207 e 261**, sinalizadas em `quality_issues`, sem correção financeira presumida. Campos pessoais não reconhecidos permanecem nulos e sinalizados internamente.

## Aditivos e pendências

Dos 48 aditivos registrados, **26 foram vinculados**. As 57 novas atas receberam início na própria data e término pelo aditivo confirmado (26) ou período original (31). As 25 atas anteriores permaneceram inalteradas; nenhuma ata foi ignorada no resultado final.

**22 aditivos permanecem com `ata_ausente`**: os originais abaixo não foram encontrados na pasta fornecida. Todos são de 2025.

| Pregão | Atas originais ausentes |
| --- | --- |
| 003/2025 | 007, 008, 010 |
| 007/2025 | 063, 064, 065, 066, 068, 069, 070 |
| 010/2025 | 072, 074, 075 |
| 011/2025 | 082, 084, 086, 088, 089, 090, 093 |
| 012/2025 | 079 |
| 013/2025 | 080 |

Esses aditivos conservam origem/datas e poderão ser vinculados após disponibilização das atas. Não foram associados a outro ano nem criados cadastros incompletos.

## Base consolidada e painel

| Recorte | Atas | Itens | Vigentes | Vencidas | Total documental |
| --- | ---: | ---: | ---: | ---: | ---: |
| 2025 | 56 | 888 | 27 | 29 | R$ 29.226.277,21 |
| 2026 | 26 | 223 | 3 | 23 | R$ 4.342.404,53 |
| Todos os anos | **82** | **1.111** | **30** | **52** | **R$ 33.568.681,74** |

Referência: 09/11/2026. Zero atas com vigência indefinida ou a iniciar; 207 vínculos documentais, sem multiplicar atas/valores no painel global. Consulta somente leitura de `AtasOverviewQuery` confirmou os três recortes. Selecionar **2025** ou **Todos os anos** para consultar o histórico; o padrão continua no ano atual.

Contadores globais: **2025=109, 2026=58**; próximo número de 2026: **059/26**. Sem renumeração ou consumo antecipado do próximo número.

## Execução e validação

```powershell
docker compose exec -T app php artisan atas:import-reference Reference/ATAS/2025
docker compose exec -T app php artisan atas:import-reference Reference/ATAS/2025 --apply
docker compose exec -T app php artisan atas:update-validity
docker compose exec -T app php artisan atas:update-validity --apply
```

- Repetição das duas cargas: **57 e 25 existentes, zero inserções**; repetição da vigência: zero novos aditivos/atualizações e **82 atas inalteradas**, com as mesmas 22 pendências.
- Testes dirigidos de importação: **19 testes, 74 asserções**; demais testes de atas/vigências/painel/numeração: **39 testes, 226 asserções**. SQLite em memória e storage temporário.
- `composer check:architecture`: **3 testes, 4 asserções**; `composer test:unit`: **86 testes, 210 asserções**. Aprovados.
- Pint dos arquivos alterados e `git diff --check`: aprovados.
- Relatórios privados ficam em `build/ata-amendment-preview/`, ignorado pelo Git. Os arquivos originais foram preservados.

Build e suíte geral não foram repetidos nesta alteração de backend; as limitações anteriores permanecem na [importação inicial](atas-reference-import.md#validação). Sem testes destrutivos no banco real, commit, publicação ou deploy externo.
