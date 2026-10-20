# Referências aos documentos assinados

Evolução posterior: o [menu Publicação](publication-module-2026-10-20.md) implementa o envio, a vinculação e a consulta por categoria. O texto abaixo registra a etapa inicial de preparação do schema.

O banco atual usa a tabela `contracts`; `TB_Contratos` é o nome da origem importada. A migration `2026_10_20_000004_add_signed_document_references_to_contracts` acrescenta ao final, nesta ordem, `Contrato_PDF`, `Aditivo_PDF`, `OS_PDF`, `Concordancia_PDF`, `Distrato_PDF` e `ATA_PDF`.

Cada campo é `BIGINT UNSIGNED NULL`, com chave estrangeira para `contract_attachments.id` e exclusão física restrita. Guarda a referência ao documento privado; o conteúdo do PDF permanece no disco `contract_documents`, fora da pasta pública. O anexo existente conserva nome, caminho privado, MIME, tamanho, SHA-256, autor e exclusão lógica. Os seis campos começam vazios, sem preencher documentos automaticamente. O model oculta essas referências na serialização e impede atribuição em massa pelo formulário genérico.

Esta entrega prepara somente o schema. O envio e a consulta por essas categorias ainda precisam ser integrados à interface e a um adaptador autorizado de gravação. Essa integração deve verificar PDF, vínculo do anexo ao mesmo contrato, anexo ativo, ator, policy e versão sob locks na transação, persistindo a referência e auditoria inseparáveis. A chave estrangeira sozinha garante somente a existência do anexo. A consulta deve usar a autorização por contrato e a verificação de integridade existentes, sem expor URLs públicas. Os campos identificam um documento de cada categoria por contrato; os anexos e registros próprios continuam necessários para históricos e múltiplos aditivos/OS. Não há validação criptográfica de assinatura nesta entrega.

A migration tem reversão sem exclusão das colunas para preservar documentos e histórico. Instalação específica:

```sh
php artisan migrate --path=database/migrations/2026_10_20_000004_add_signed_document_references_to_contracts.php --force
```

Executado no banco configurado do container `app`, após revisão por `--pretend`. A conferência confirmou os seis campos como as últimas colunas, todos anuláveis, e 2.145 contratos antes e depois.

Validação via Docker: `php artisan test --filter='SignedDocumentSchemaTest|HistoricalContractTypesTest|ContractWritingTest' --stop-on-failure` confirmou os 39 testes de gravação e quatro de tipos históricos; falhou no novo teste por ausência de `uploaded_by` no anexo fictício. Foi acrescentado o usuário de teste, preservando as restrições e asserções. `php artisan test --filter=SignedDocumentSchemaTest` passou (2 testes, 12 asserções). `composer check:architecture` passou (3 testes, 4 asserções); `composer test:unit` passou (77 testes, 169 asserções). Pint nos três PHP envolvidos e `git diff --check` passaram. Testes usaram SQLite em memória e storage/cache temporários. A suíte completa não foi repetida nesta entrega; sem mudanças em assets, dispensando build. Nenhum upload ou download novo foi implementado.
