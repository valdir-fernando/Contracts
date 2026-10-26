# Critérios dos relatórios — 26/10/2026

Por definição funcional do usuário, os relatórios de contratos, suas exportações PDF/XLSX/CSV, o Acervo, o calendário e os indicadores do Início utilizam `vigencia_inicio`, `vigencia_fim_atual` e `valor_acumulado`. Data final e valor originais não substituem campos atualizados ausentes. Valores ausentes não entram na soma e são contados como ausentes; zero continua sendo um valor válido. A coluna de início exibe o início da vigência, não a assinatura.

“Somente Vigentes” considera exclusivamente término atualizado posterior à data de referência. Término hoje ou anterior é vencido; ausência de término atualizado é indeterminada. Início futuro ou ausente e a marcação `vigencia_em_revisao` não excluem um contrato com término futuro. Intervalos de término, calendário e alertas incluem as duas datas limites e não consultam revisão nem exigem início preenchido.

`Contract::withReportValidity`, `reportValidityOn` e `reportEndingBetween` centralizam essas leituras. A classificação histórica `ContractValidity`, a conciliação e as regras de escrita continuam preservadas, assim como os campos originais e o marcador de revisão no banco. Nenhum contrato é recalculado ou preenchido automaticamente. A classificação histórica não é usada para selecionar ou rotular as linhas desses relatórios.

Parâmetros HTTP existentes são preservados: `review` e `validity=em_revisao` são aceitos, mas não restringem resultados. `validity=a_iniciar` permanece como filtro explícito de início futuro e término atualizado futuro. A interface oferece vigente, vencido e término não informado. O identificador legado `columns[]=initial_amount` passa a projetar o valor atualizado, com cabeçalho atualizado; `sort=valor_total`, `min_value` e `max_value` também operam sobre o valor atualizado. Isso preserva URLs e modelos salvos sem criar dois critérios monetários.

Agrupamentos por Fundo, Secretaria, fornecedor, fiscal, tipo e modalidade usam a mesma projeção e a mesma soma decimal exata. Listagem e PDF do painel usam os mesmos campos. Escopos e autorização permanecem aplicados no servidor. Os parâmetros `report_*` do painel continuam independentes dos filtros históricos de exportação.

O filtro Final do painel mantém sua regra anterior de limpar os demais filtros da lista e o exercício. O calendário consulta o mês independentemente do exercício; o Início respeita o exercício escolhido. Essas diferenças de recorte não são alterações do critério temporal.

Arquivos de exportação já gerados conservam o conteúdo capturado na emissão; uma nova geração aplica os critérios acima.

A API interna conserva o campo público `values.initial_amount` e seu valor inicial para compatibilidade HTTP; essa projeção é explícita no controller, independente do valor atualizado usado nos relatórios. O filtro avançado de período passa a usar início de vigência como padrão; a opção explícita de assinatura continua disponível.

## Validação local

- `docker compose exec -T app composer check:architecture`: 3 testes, 4 asserções, aprovados.
- `docker compose exec -T app composer test:unit`: 81 testes, 195 asserções, aprovados.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `docker compose exec -T app php artisan test --filter='ReportCriteriaTest|AdvancedContractSearchTest|ReportScaleTest|ReportSnapshotTest' --compact`: 13 testes, 205 asserções, aprovados, incluindo agrupamentos, valores ausentes/zero, precisão decimal e exportações nos três formatos.
- `docker compose exec -T app php artisan test --filter='BrazilianLocalizationTest|TableNavigationTest|ContractCompletionTest|ReportCriteriaTest' --compact`: 15 testes, 170 asserções, aprovados antes da adição das asserções de agrupamento.
- O primeiro `composer check` aprovou Pint, mas atingiu o timeout padrão de 300 segundos após 756/821 testes. A execução foi retomada com `COMPOSER_PROCESS_TIMEOUT=0`, sem alterar configuração versionada.
- `docker compose exec -T -e COMPOSER_PROCESS_TIMEOUT=0 app composer check`: execução concluída, Pint aprovado em 353 arquivos; 821 testes, 8.390 asserções, 12 falhas e 1 ignorado. As 12 falhas correspondem exatamente às reproduzidas no HEAD original; não houve novas falhas na suíte final. Não se declara aprovação integral de `composer check`.
- Comparação com HEAD em checkout temporário no container e autoload separado: 12 falhas preexistentes em 131 testes de navegação, detalhe, documentos e escrita; os testes dessas falhas foram preservados. Logs locais ficam em `build/report-*.log`, fora do versionamento.

Testes usam SQLite em memória e storage/cache temporários. Nenhum teste ou migration foi executado contra o MySQL instalado. Consultas agregadas somente de leitura confirmaram 47 contratos do Município com término atualizado futuro e 3 términos atualizados entre 26/10/2026 e os próximos 30 dias. `contracts:check-operations` confirmou banco, worker e scheduler, sem jobs com falha; `/up` respondeu HTTP 200. O worker recebeu `queue:restart` para carregar o código novo. Não houve conferência visual autenticada no navegador.

### Conclusão do fluxo de exportações

`docker compose exec -T app php artisan test --filter='ReportUpdatedExportsTest|ReportGenerationFlowTest|ReportExportSecurityTest' --compact`: 22 testes, 161 asserções, aprovados. `ReportUpdatedExportsTest` percorre solicitação HTTP 202, fila, geração, consulta de estado e download nos três formatos. Confere início, término atualizado, valor atualizado, zero e inclusão de registros em revisão, com datas/valores originais divergentes, exclusão de término atualizado ausente e de registros fora do escopo. Confere também SHA-256, tamanho, auditoria e preservação integral do contrato. O PDF é conferido na projeção de renderização e na assinatura do arquivo; Excel e CSV são lidos e comparados célula a célula. Pint do novo teste e `git diff --check` passaram. A conclusão acrescentou cobertura de integração; não exigiu novas mudanças no código de produção nem gravações no banco instalado.
