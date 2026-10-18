# Localização para português do Brasil

O idioma principal e o idioma de reserva são `pt_BR`, com fuso `America/Sao_Paulo`. A marca Contracts e os identificadores internos continuam preservados.

## Interface

- Validação, autenticação e paginação têm mensagens em português e nomes compreensíveis para os campos.
- Campos de data mostram `dd/mm/aaaa`, aceitam digitação e mantêm o botão de calendário. A conversão para ISO ocorre na montagem dos dados do formulário; o servidor continua validando as datas.
- O calendário possui seleção de mês em português e ano, independente do idioma do navegador, com limites de 1900 a 2199. Links anteriores no formato `month=AAAA-MM` continuam funcionando.
- Valores financeiros armazenados com quatro casas decimais aparecem com separadores brasileiros nos formulários.
- Auditoria, conciliação e importações exibem ações e estados traduzidos. A auditoria oferece seleção das ações por descrição, mantendo os códigos originais na filtragem e no banco.
- As páginas de erro 401, 403, 404, 405, 419, 429, 500 e 503 possuem textos em português e acesso ao início, sem detalhes internos de exceções.

## Relatórios e exportações

Datas são apresentadas como `dd/mm/aaaa` na consulta, CSV, XLSX e PDF. Valores financeiros usam vírgula decimal com as quatro casas originais: `1908,6300`. Na consulta e nos PDFs, há também separador de milhar: `1.908,6300`.

CSV continua separado por ponto e vírgula e com BOM UTF-8. XLSX mantém células de texto para preservar zeros iniciais, precisão e proteção contra fórmulas. Os agrupamentos seguem o mesmo padrão de decimal brasileiro.

Filtros e ações da auditoria são descritos em português. As emissões indicam horário de Brasília. Cálculos, armazenamento e respostas da API interna continuam com decimais exatos e datas ISO.

## Verificação

A suíte PHP cobre localização das validações, seleção e limites do mês/ano, páginas de erro, datas, ordem das colunas, valores grandes e negativos, precisão e exportações.

`scripts/browser-localization.cjs` usa navegador em inglês para verificar meses em português, datas brasileiras, rejeição de datas impossíveis, submissão ISO, calendário nativo e quatro páginas em larguras de 1440, 768, 390 e 320 pixels. Usa as variáveis `CONTRACTS_URL` e `CONTRACTS_ACCESS_FILE`, como o teste de navegador existente.

O fluxo completo de navegador também foi validado em uma instância isolada com dados sintéticos, incluindo cadastros, permissões, documentos, aditivos, registros financeiros, relatórios e exportações.

Resultado final: 98 testes PHP aprovados, com 1.268 asserções; fluxo completo e teste específico de localização aprovados no navegador; build dos assets e compilação Blade concluídos; formatação PHP e diferenças sem erros.

## Datas em relatórios — verificação de 18/10/2026

O padrão vale também para os relatórios e exportações: PDF, Excel e CSV exibem datas `DD/MM/AAAA`; períodos, referência e emissão seguem a mesma convenção, com horas quando aplicável. Auditoria usa `DD/MM/AAAA HH:mm:ss`. Armazenamento, filtros recebidos e APIs permanecem ISO. O fluxo já fazia essa conversão em `BrazilianLocale::reportRow`, `BrazilianLocale::filters` e `AuditReportRenderer`; não foi necessário modificar os renderizadores.

Acrescentados testes de integração dos três formatos com colunas reordenadas, datas de assinatura/início/término, referência e emissão. Para PDF, os dados efetivamente entregues à view são verificados e o arquivo gerado precisa ter assinatura PDF. CSV é inspecionado como texto e XLSX é reaberto com PhpSpreadsheet. Os valores originais do banco são comparados antes/depois da exportação. Também foram acrescentadas asserções do timestamp brasileiro nos arquivos CSV/XLSX de auditoria.

Executado `docker compose exec -T app php vendor/bin/phpunit --filter 'ReportSnapshotTest|BrazilianLocalizationTest|AuditReportTest' --do-not-cache-result`: 13 testes, 114 asserções, sucesso. Pint dos dois testes e `git diff --check` aprovados. A primeira execução dos novos testes esperava uma string DATE pura no SQLite; os casts existentes do Eloquent armazenam ali também meia-noite. Após conferir os casts e a migration de tipo DATE, a asserção passou a comparar o valor bruto anterior/posterior e a data civil, verificando que a exportação não altera o armazenamento. Testes usam SQLite/storage/cache isolados; não houve acesso ao banco remoto, alteração de dados reais ou necessidade de build.
