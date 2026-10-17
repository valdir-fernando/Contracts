# Administração nos cabeçalhos dos documentos

A administração vigente inicia em **2025/2028**. Um administrador pode alterá-la em **Órgãos e permissões → Administração nos documentos**, informando ano inicial, ano final e justificativa. A configuração é compartilhada entre os Fundos e não depende do exercício dos contratos nem muda automaticamente na virada do ano.

`Infrastructure/Documents/DocumentSettings::headerData()` fornece `['administration' => '2025/2028']` para os modelos de documentos. Os próximos emissores devem capturar esse valor com os dados autorizados do contrato no momento da emissão e usá-lo no cabeçalho. Alterar a configuração afeta novas emissões; não reescreve arquivos já emitidos. A geração do Extrato continua aguardando a implementação do modelo.

## Persistência e segurança

A migration aditiva `2026_10_17_000002_create_document_settings.php` cria somente `document_settings` e o registro inicial (2025, 2028, versão 1). Não altera tabelas ou dados de contratos existentes. Seu rollback preserva a configuração.

`PUT /document-settings` exige autenticação e CSRF existentes. `DocumentSettings::update` bloqueia e revalida o ator, exige administrador atual e bloqueia depois a configuração. Valida anos, ordem do intervalo, justificativa e versão. Formulários antigos são recusados sem sobrescrever a configuração mais recente; a revisão antiga é mantida na resposta até recarregar. A gravação e o evento `document_settings_updated` com período anterior, novo período e justificativa ocorrem na mesma transação. Falha de auditoria reverte período e versão. Não há cache com estado mutável de ator ou configuração.

## Instalação e validação

Foi executado no ambiente local:

```powershell
docker compose exec -T app php artisan migrate --path=database/migrations/2026_10_17_000002_create_document_settings.php --force
docker compose exec -T app php artisan view:cache
```

Ambos passaram. `DocumentSettingsTest` passou com 6 testes e 57 asserções: padrão persistido, projeção do cabeçalho, edição autorizada/auditada, campos forjados ignorados, validação, versão desatualizada, usuário comum, identidade antiga e rollback de auditoria. Testes em SQLite em memória e storage temporário, sem testes no banco instalado. Arquitetura passou (3 testes, 4 asserções), análise estática passou e `git diff --check` passou. Não houve mudança de assets ou teste em navegador nesta revisão.

Verificação final: composer check passou (Pint em 236 arquivos; 424 testes, 423 aprovados e 1 ignorado por exigir MySQL isolado; 3.981 asserções). Consulta somente de leitura no ambiente instalado confirmou administration=2025/2028. git diff --check passou.

Apresentação compacta: reduzidos os espaços do cabeçalho, painéis e tabelas de Órgãos e permissões. Em desktop, anos, justificativa e botão ficam na mesma linha; em telas menores o formulário se reorganiza em colunas. npm run build, php artisan view:cache e git diff --check passaram. Edge headless com fixture Blade sem banco confirmou painel de administração com cerca de 143 px em largura de 1366 px e ausência de transbordamento horizontal em 390 px. Suíte PHP não repetida por ser alteração visual.
