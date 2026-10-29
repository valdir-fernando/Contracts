# Consulta de contratos centralizada em Relatórios

A listagem ativa do Acervo Contratual, também apresentada como “Contratos vigentes”, foi retirada por duplicar o painel de relatórios. A consulta permanece em **Relatórios → Relatório Contrato**, com seus filtros e PDF existentes.

Foram removidos o item Contratos do menu, o botão Consultar contratos do Início e os links dos indicadores Contratos cadastrados, Vigentes e Vencidos. Os indicadores mantêm contagens, cores e alinhamento. Fechar o resumo, voltar de um novo cadastro e concluir uma exclusão levam a Relatórios. Cadastro, resumo, edição, aditivos, documentos, API interna e dados históricos continuam disponíveis.

`GET /contracts` mantém a rota `contracts.index` por compatibilidade com URLs antigas, mas redireciona para `/reports`; os filtros da tela retirada não são transferidos. A view antiga e seus estilos exclusivos foram removidos. Não há mais links da aplicação para essa listagem.

Administradores acessam **Administração → Contratos excluídos** em `/contracts/deleted`. A consulta pesquisa somente registros excluídos, inclui todos os exercícios e mantém paginação, confirmação, justificativa, versão e auditoria da restauração. `GET /contracts?deleted=1` continua atendendo essa consulta por compatibilidade; usuários comuns não podem acessá-la. `ContractListingQuery::for` preserva a assinatura e passa a servir apenas essa leitura administrativa. Nenhuma tabela, campo ou registro foi removido.

Os testes específicos da apresentação retirada foram substituídos por cobertura de redirecionamento, ausência de links e preservação da restauração. As regras de busca, escopo, valores, vigência e paginação são verificadas no relatório. Testes existentes de detalhes e segurança permanecem, inclusive as falhas já registradas anteriormente.

As execuções de teste usam SQLite em memória e armazenamento temporário isolado. Sem validação visual autenticada no navegador.

Validação executada:

- `docker compose exec -T app composer check:architecture`: 3 testes aprovados.
- `docker compose exec -T app composer test:unit`: 81 testes aprovados.
- `docker compose run --rm --no-deps node npm run build`, `node --check scripts/browser-smoke.cjs` e `git diff --check`: aprovados.
- `docker compose exec -T app php vendor/bin/phpunit --do-not-cache-result --filter='RetiredContractListingTest|test_previous_year_changes|test_dashboard_cards_match|test_pagination_preserves_filters'`: 5 testes e 74 asserções aprovados, incluindo restauração com auditoria e bloqueio do acesso de usuários comuns.
- `docker compose exec -T -e COMPOSER_PROCESS_TIMEOUT=0 app composer check`: Pint aprovado em 362 arquivos; 847 testes, 14 falhas e 1 ignorado. Doze métodos com falha já constavam em `build/report-baseline-verified.log`; duas expectativas adicionais ainda descreviam o menu/painel retirado.
- Após atualizar essas expectativas e o teste de permissão de consulta para usar Relatórios, `docker compose exec -T app php vendor/bin/phpunit --do-not-cache-result --filter='CatalogNavigationTest|SelectedFundTest|ContractIssuancePermissionTest|RetiredContractListingTest'`: 18 testes, 16 aprovados e 2 falhas conhecidas em `test_user_needs_explicit_issuance_permission_for_creation_and_documents_but_can_consult` e `test_editing_a_contract_preserves_its_existing_manager`. Pint dos seis testes revisados passou. A suíte completa não foi repetida após esses ajustes somente em testes. Logs em `build/retired-contract-listing-check.log` e `build/retired-contract-listing-final-recheck.log`.
