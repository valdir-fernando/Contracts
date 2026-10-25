# PDF do painel de contratos

Em **Relatórios de contratos**, **Gerar PDF** baixa a relação completa dos contratos encontrados pelos filtros atuais, independentemente da página da lista. O formato é **A4 horizontal**. O título compartilha o template `reports.panel-title` com a mensagem do painel, incluindo Fundo, exercício e filtros aplicados.

A rota autenticada `GET /reports/panel-pdf` compartilha a seleção e validação de `ReportController::index`. O PDF usa uma projeção explícita das colunas da lista, sem documentos pessoais ou snapshots completos. Ações da interface não são impressas. Mantém datas brasileiras, valores formatados e cabeçalho de tabela repetido nas páginas.

A renderização ocorre fora da transação de auditoria. Antes da resposta privada (`private, no-store`), o servidor bloqueia e revalida o ator, verifica acesso atual aos Fundos e compara `ReportExport::accessHash`. Mudanças de acesso ou versão dos contratos durante a geração recusam a entrega. A emissão registra `report_exported` com tipo `contract-panel`.

Os botões Contrato, Aditivo e Ata continuam sem troca de painel; esta emissão corresponde à lista de contratos apresentada.

Testes em `ContractBrowsingTest`: exportação além da primeira página, seleção de Fundo/exercício, título, datas, dimensões físicas do PDF, resposta privada, autenticação, filtros inválidos e invalidação durante a renderização. Testes usam SQLite em memória e armazenamento temporário.

Validação executada:

- `docker compose exec -T app vendor/bin/pint app/Http/Controllers/ReportController.php tests/Feature/ContractBrowsingTest.php`: passou.
- `docker compose exec -T app php artisan test --compact --filter=ContractBrowsingTest`: 28 testes, 436 asserções, passou.
- `docker compose exec -T app composer check:architecture`: 3 testes, 4 asserções, passou.
- `docker compose exec -T app php artisan view:cache`: passou.
- `git diff --check`: passou.

Sem conferência visual autenticada no navegador. Nenhuma migration ou teste foi executado no banco instalado.
