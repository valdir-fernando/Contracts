# Calendário de vencimentos por Fundo selecionado

`GET /calendar` mostra somente contratos do Fundo selecionado no login, respeitando também os órgãos atualmente autorizados. O recorte aplica-se à lista paginada, contagens por dia, total mensal e contadores de contratos em revisão ou com vigência indeterminada. Vale inclusive para administradores ou usuários com acesso a vários Fundos.

O contexto é resolvido por `SelectedFund` a partir da sessão e reautorizado no servidor. Parâmetros de URL como `fund` ou `fund_id` não escolhem outro Fundo. Sem contexto selecionado, as consultas retornam vazias; um contexto que deixou de ser autorizado continua sujeito ao encerramento de sessão pelo middleware existente.

Navegar entre meses, consultar mês/ano e paginar mantém o contexto da sessão. O ano do calendário representa a data do vencimento, sem acrescentar filtro pelo exercício cadastral do contrato. Preferências de alerta continuam pessoais. A assinatura e o comportamento geral de `ExpirationCalendar::query` permanecem preservados para relatórios, indicadores e outros consumidores; o filtro é aplicado no adaptador HTTP do calendário.

Validação específica via Docker: `ExpirationCalendarTest`, `BrazilianLocalizationTest` e `TableNavigationTest` passaram com 14 testes e 144 asserções. Cobertura inclui usuário com vários Fundos autorizados, administrador, tentativas de troca pela URL, contexto ausente, alternância de contexto, contagens de revisão/indeterminado, dias e paginação. O teste de localização passou a fornecer um Fundo selecionado e um contrato desse Fundo, mantendo as asserções de idioma e datas. Pint e `php artisan view:cache` passaram. SQLite em memória e armazenamento temporário; nenhum teste ou alteração de dados no banco instalado. Não houve mudança de assets ou teste em navegador nesta revisão.

Verificação final: composer check passou (Pint em 228 arquivos; 410 testes, 409 aprovados e 1 ignorado por exigir MySQL isolado; 3.846 asserções). composer check:static passou sem erros. git diff --check passou.

### Coluna Órgão para o Município

Quando o cadastro atual do Fundo selecionado corresponde a MUNICIPIO DE NIQUELANDIA (comparação normalizada de caixa, acentos e espaços), a coluna Órgão exibe secretaria_nome_snapshot do contrato. Ausência aparece como Secretaria não informada. Para os demais Fundos, permanece fundo_nome_snapshot. A identificação depende do Fundo reautorizado da sessão, e não de parâmetros da URL ou do nome histórico do contrato.

Validação desta apresentação: Pint passou; ExpirationCalendarTest, BrazilianLocalizationTest e TableNavigationTest passaram (15 testes, 152 asserções); php artisan view:cache e git diff --check passaram. Teste novo confere as células da tabela para Fundo comum, nome municipal com variações de acento/caixa/espaços e secretaria ausente. SQLite em memória e storage temporário; suíte completa e navegador não foram repetidos nesta alteração.
