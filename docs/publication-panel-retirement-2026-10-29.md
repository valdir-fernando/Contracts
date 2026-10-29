# Retirada do painel Publicação

O painel de envio e consulta de PDFs assinados foi retirado da interface, incluindo o item Publicação do menu e a view `signed-documents.index`. Nenhum documento, referência, campo ou registro histórico foi excluído.

O endereço autenticado `GET /publication` mantém nome e assinatura por compatibilidade, mas apenas revalida o ator e a permissão de consulta e redireciona para Relatórios, com resposta privada sem cache. Parâmetros antigos não selecionam contratos nem expõem documentos. Não existem mais links da interface para esse painel.

As rotas de upload e download já existentes continuam protegidas por autenticação, Fundo selecionado, escopos, versão, confirmação de substituição, integridade e auditoria. Uploads compatíveis e conflitos retornam ao resumo do contrato. O histórico dos PDFs permanece privado. A emissão do documento do Diário Público pelo botão Publicação no cadastro do contrato é uma operação independente e permanece disponível.

Validação: `docker compose exec -T app php vendor/bin/pint --test app/Http/Controllers/SignedDocumentController.php tests/Feature/PublicationTest.php` e `docker compose exec -T app php vendor/bin/phpunit --do-not-cache-result --filter='PublicationTest|SignedDocumentSchemaTest|CatalogNavigationTest|RetiredContractListingTest'` passaram (15 testes e 218 asserções). Os testes de apresentação da tela retirada foram atualizados para redirecionamento e ausência de links; a cobertura de upload, seis categorias, histórico, autorização, versão, integridade e rollback de auditoria foi preservada. SQLite em memória e storage temporário isolado; sem validação visual autenticada.

Também passaram `docker compose exec -T app composer check:architecture` (3 testes), `docker compose exec -T app composer test:unit` (81 testes), `docker compose run --rm --no-deps node npm run build` e `git diff --check`. A suíte completa não foi repetida nesta alteração; as falhas conhecidas da execução anterior estão documentadas na retirada da listagem de contratos.
