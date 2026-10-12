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
