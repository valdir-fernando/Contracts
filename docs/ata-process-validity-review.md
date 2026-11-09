# Vigências originais nos processos de aditivos não assinados — 09/11/2026

Por instrução expressa do usuário, os aditivos em `Reference/ATAS/ADITIVOS_ATAS/Nova pasta` foram desconsiderados. Foram conferidos os **27 PDFs de processos** existentes nas respectivas pastas, localizando a cópia da ata original e utilizando sua data de fecho e duração. Pedidos, termos de concordância, pareceres, minutas, nomes de arquivo e carimbos de assinatura/exportação do processo não determinaram prorrogação.

Aplica-se a regra já solicitada pelo usuário: início na data da própria ata; sem aditivo assinado aplicável, término na data correspondente ao período original, sem subtrair um dia. Todos os documentos conferidos possuem duração original de um ano.

## Datas finais confirmadas

Todas as atas abaixo são de **2025**.

| Atas | Quantidade | Data da ata/início | Data final original |
| --- | ---: | --- | --- |
| 003, 004, 006, 011 | 4 | 13/06/2025 | **13/06/2026** |
| 015, 017, 021, 022, 023, 025, 027, 033, 034, 035 | 10 | 26/06/2025 | **26/06/2026** |
| 040, 045, 046, 047, 048, 049, 050, 054, 055, 057, 058 | 11 | 09/07/2025 | **09/07/2026** |
| 067 | 1 | 20/08/2025 | **20/08/2026** |
| 083 | 1 | 22/09/2025 | **22/09/2026** |

As **21 atas já cadastradas estavam com essas datas corretas** e foram confirmadas, sem incremento de versão ou sobrescrita dos demais campos. As **003/2025, 004/2025, 006/2025, 011/2025, 067/2025 e 083/2025** ainda não existiam na base e foram cadastradas a partir das cópias originais dos processos, com identificação, fornecedor, origem, datas e base de cálculo auditada. Não foram criadas a partir das minutas de aditivo.

O cadastro dessas seis atas foi limitado à identificação e vigência necessárias à solicitação: não houve importação dos produtos ou de todos os participantes/contatos das cópias, nem valores presumidos. Essa limitação está sinalizada em `quality_issues`. Os CNPJs municipais transcritos nas cópias das 067/2025 e 083/2025 não correspondem ao CNPJ único do cadastro local; foram preservados documentalmente, com `fund_id` nulo e pendência explícita. Não se autorizam operações por esses snapshots; o painel de atas permanece global.

## Exclusão das minutas e manutenção

`config/atas.php` registra o diretório excluído. `Infrastructure/Atas/AtaAmendmentSourcePolicy` verifica o caminho resolvido com limite de diretório; vale inclusive quando a pasta excluída é passada diretamente ao leitor. `AtaAmendmentReader` ignora seus PDFs na leitura de aditivos, e `AtaAmendmentProductReader` recusa a importação de seus produtos. Um arquivo com `Assinado` no nome também permanece excluído nessa árvore, conforme a orientação do usuário.

`UpdateAtaValidity` também desconsidera registros já armazenados cuja origem esteja no diretório excluído, preservando o histórico com status `nao_assinado` e auditoria. No banco local, **nenhum desses aditivos havia sido registrado**, portanto não houve remoção, exclusão lógica ou reversão de produtos. Os **48 aditivos anteriores, 26 vínculos e 675 produtos** permanecem preservados; suas 22 pendências de atas originais são de outros números e continuam pendentes.

`AtaProcessEvidenceReader` descobre somente PDFs cujo nome começa por Processo. Usa texto nativo e OCR local privado em páginas digitalizadas; seleciona a seção que começa com o cabeçalho da ata original e termina no seu fecho datado. Quando a tabela de itens é longa, amplia a leitura para localizar a cláusula de duração. Confere número/ano, processo original, pregão e gerenciador; o CNPJ do fornecedor é comparado quando reconhecido.

Foram necessárias quatro conferências visuais de OCR: duração de um ano na 027/2025 (página 14 do processo), data de 09/07/2025 na 048/2025 (página 12) e preâmbulo/CNPJ do gerenciador nas 054/2025 e 057/2025. Revisões privadas estão vinculadas ao hash do PDF, página e hash do OCR original. Alteração da extração invalida a revisão. Cache e revisões ficam em `storage/app/private/ata-process-evidence`; os PDFs fornecidos não foram alterados.

`ReconcileAtaProcessValidity` prepara os documentos fora da transação e grava cadastros/datas, vínculos de gerenciador, mínimos dos contadores e evento `ata_original_processes_reconciled` na mesma transação. A ordem de locks é gerenciadores → contadores anuais → atas → aditivos. Confere hashes e identidade novamente antes da escrita, protege edições manuais posteriores e preserva eventual prorrogação assinada de outra origem. Excluídos não são restaurados. Falha da auditoria reverte o lote. A trava é compartilhada com as demais manutenções de atas.

```powershell
docker compose exec -T app php artisan atas:reconcile-process-validity
docker compose exec -T app php artisan atas:reconcile-process-validity --apply
```

Sem `--apply`, simula; cache privada pode ser preenchida. O argumento opcional substitui a pasta. Leitura pendente impede aplicação do lote. A repetição aplicada confirmou **27 existentes, zero inserções e zero atualizações**, sem outro evento de auditoria.

## Resultado consolidado e validação

- **88 atas**: 30 vigentes e 58 vencidas em 09/11/2026; nenhuma sem vigência definida.
- **1.111 itens originais e 675 itens de aditivos preservados**. O valor apresentado pelo painel permanece R$ 33.568.681,74; não foram presumidos valores para os seis novos cadastros de vigência.
- Contadores preservados: **2025=109; 2026=58**.
- **Um evento de auditoria** desta conferência, contendo as fontes dos 27 processos e os seis IDs cadastrados.
- A simulação posterior de `atas:update-validity` identificou somente os **48 aditivos anteriores**, zero novos aditivos, zero alterações e **88 atas inalteradas**.
- Testes dirigidos de atas, vigência, numeração, painel, produtos e processos: **77 testes e 378 asserções aprovados**, com SQLite em memória e storage temporário. Incluem exclusão recursiva/direta da pasta não assinada, invalidação de extensão previamente registrada sem apagar histórico, recusa de produtos não assinados, fecho original em vez da data do pedido, conservação de aditivo assinado, auditoria/rollback, idempotência e integridade da origem.
- `composer check:architecture`: **3 testes, 4 asserções aprovados**; `composer test:unit`: **86 testes, 210 asserções aprovados**.
- Pint dos 11 PHP envolvidos e `git diff --check`: aprovados.

Relatórios privados de simulação, aplicação, repetição e conferência ficam em `build/ata-amendment-preview/process-*.json`. Não foram necessárias migrations. Build e suíte geral não foram repetidos nesta mudança de backend; limitações anteriores permanecem na [importação inicial](atas-reference-import.md#validação). Sem testes destrutivos no banco operacional, commit, publicação ou deploy externo.
