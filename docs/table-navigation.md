# Navegação pelas linhas das tabelas — 12/10/2026

As linhas de contratos (listagem, painel Livewire, calendário, relatórios e contratos associados), usuários ativos, aditivos e registros financeiros ativos abrem a página correspondente. Fornecedores, fundos e secretarias receberam páginas de consulta com dados cadastrais e contratos associados. Eventos/auditorias, execuções e pendências de importação abrem uma consulta administrativa do próprio registro.

O comportamento usa `data-row-href` com URLs geradas pelo servidor e delegação de eventos em `resources/js/table-navigation.js`. Funciona também depois de atualizações do Livewire. Mantém links nativos para navegação por teclado e uso sem JavaScript; a semântica de linhas/células continua nativa. Há destaque visual ao passar o mouse e ao focar um link dentro da linha.

Botões, links próprios, campos, formulários e conteúdo editável não acionam o destino da linha. Seleção de texto é preservada. Ctrl/Cmd/Shift e botão central abrem o destino em outra aba; URLs externas e esquemas não HTTP não são aceitos pelo comportamento de linha. Registros excluídos com formulários de restauração não recebem um destino de detalhe ativo que retornaria 404. Tabelas de totais, calendário em grade e documentos PDF não representam itens individuais navegáveis.

Novas rotas somente de leitura:

- `/suppliers/{supplier}`: mesma autorização do catálogo de fornecedores; usuário comum só consulta CNPJ vinculado a contratos acessíveis, e os contratos associados continuam filtrados por `visibleTo`.
- `/catalogs/{kind}/{catalog}`: fundos/secretarias somente para administradores, com campos explícitos e sem payload de origem.
- `/administration/records/{kind}/{record}`: tipos permitidos de auditoria/evento/importação/pendência, somente administradores. A resposta Blade recebe uma projeção explícita dos dados; metadados de eventos passam pelo formatador existente. Alterações de auditoria são consultadas apenas pelo perfil administrativo e não foram acrescentadas aos relatórios exportados.

Rotas, assinaturas e autorização das páginas anteriores foram preservadas. Nenhuma migration, mutação de registros ou nova dependência foi necessária.

Validação:

- `composer check`: Pint aprovado em 173 arquivos; suíte SQLite completa com 259 testes e 2.000 asserções. Inclui testes novos de destinos de linhas, escopos, proteção de CPF, campos de origem, consultas administrativas e estados excluídos.
- `npm run build`: aprovado, Vite 7.3.7; assets atualizados localmente.
- `view:cache`: templates compilados; `git diff --check` aprovado.
- Playwright 1.58.2, `scripts/browser-table-navigation.cjs`: clique na célula, Enter no link, preservação de controles e seleção, Ctrl+clique em nova aba e navegação de linha inserida dinamicamente, sem erros JavaScript. Ensaio com destinos interceptados; autorização/dados reais do fluxo são cobertos separadamente pelos testes PHP com fixtures.
- O ensaio de geração de relatórios foi repetido com o bundle novo: download automático uma vez, download manual e ausência de download automático de relatórios históricos continuam funcionando.

Os testes usam SQLite em memória/storage temporário; não acessam o banco instalado. Não houve commit/publicação externa nem nova suíte MySQL nesta alteração.
