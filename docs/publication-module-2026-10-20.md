# Menu Publicação

Registro histórico: o painel e seu item de menu foram retirados em 29/10/2026. PDFs e histórico permanecem preservados. Consulte o [comportamento atual e a compatibilidade](publication-panel-retirement-2026-10-29.md).

O menu **Publicação** abre `/publication` para enviar e consultar PDFs assinados. A lista mostra contratos ativos do Fundo selecionado no login, de todos os exercícios, limitada aos escopos atuais de Fundo/Secretaria. A busca aceita número do contrato ou processo, com paginação. Selecione um contrato para abrir o formulário e seus documentos.

Categorias: Contrato, Aditivo, Ordem de Serviço, Concordância, Distrato e Ata. Cada envio aceita somente PDF de até 10 MB, com extensão e MIME verificados no servidor. Os bytes recebidos são preservados, sem reprocessar o PDF ou validar criptograficamente sua assinatura. O envio armazena o arquivo no disco privado `contract_documents` e grava metadados/referência no banco, nos seis campos já existentes de `contracts`. O nome Publicação não torna arquivos públicos nem envia documentos a serviços externos.

Quando a categoria já possui documento, é obrigatório confirmar a substituição. A referência passa a apontar para o novo PDF; arquivos anteriores continuam no histórico e podem ser baixados. Aditivos e OS podem ter vários envios; o campo do contrato identifica o mais recente de cada categoria, sem presumir vínculo a um aditivo/OS específico.

## Implementação

`SignedDocumentController` trata HTTP e projeções explícitas das listas, sem snapshots pessoais ou caminhos dos arquivos. `Infrastructure/Documents/SignedDocuments` concentra validação de upload, disco, autorização contextual, persistência e auditoria. A composição por construtor reutiliza `TransactionManager`, `ContractAccess`, `LockActor` e `SelectedFund`.

O upload reautoriza antes de armazenar o arquivo e novamente dentro da transação, usando `ContractAccess::lockForSave` (ator antes do contrato). Verifica o Fundo da sessão, versão atual e confirmação de substituição; grava anexo, referência, nova versão, autoria e auditoria inseparáveis. O arquivo é preparado fora da transação de banco e removido se qualquer etapa falhar. A autorização considera o usuário atual e os escopos persistidos, inclusive para administradores fora do Fundo selecionado.

O download revalida ator, contrato, Fundo, policy, vínculo e anexo ativo no servidor; verifica SHA-256 e registra auditoria antes de entregar resposta privada sem cache. Arquivos antigos substituídos permanecem acessíveis com as mesmas regras; anexos removidos logicamente ficam indisponíveis. A emissão do PDF de publicação já existente em `/contracts/{contract}/publication` permanece independente do novo menu.

Rotas autenticadas:

- `GET /publication`: busca e formulário.
- `POST /publication/contracts/{contract}`: upload multipart com `category`, `version`, `file` e `replace_confirmation` opcional; limite de 20 requisições/minuto.
- `GET /publication/contracts/{contract}/documents/{document}`: download autorizado.

Uma versão antiga gera orientação para recarregar e selecionar o arquivo novamente, sem apresentar snapshots no conflito. Erros de validação preservam somente os campos textuais comuns pelo fluxo Laravel; o arquivo deve ser selecionado novamente.

## Instalação e validação

Migration aditiva `2026_10_20_000005_add_signed_category_to_contract_attachments` acrescenta categoria opcional e índice a `contract_attachments`, preservando anexos anteriores. Foi revisada com `--pretend` e aplicada ao banco configurado do container `app`, via `php artisan migrate --path=database/migrations/2026_10_20_000005_add_signed_category_to_contract_attachments.php --force`. `view:clear` e `route:list --path=publication` foram executados. Nenhum arquivo de usuário foi enviado durante a implantação.

Validação via Docker: `php artisan test --filter=PublicationTest` passou (9 testes, 130 asserções); testes filtrados `SignedDocumentSchemaTest|ContractAttachmentsTest` passaram (8 testes, 70 asserções); `composer check:architecture` passou (3 testes, 4 asserções); `composer test:unit` passou (77 testes, 169 asserções); Pint dos PHP envolvidos e `npm run build` passaram. Testes usam SQLite em memória e storage/cache temporários. Cobertura inclui as seis categorias, preservação dos bytes, confirmação/histórico, permissões e Fundo selecionado, identidade revogada, arquivos inválidos/grandes, versão antiga, integridade, exclusão lógica e reversão por falha de auditoria. Sem inspeção visual em navegador.

Verificação completa: `composer check` passou (Pint em 320 arquivos; PHPUnit com 656 testes, 6.521 asserções e 1 ignorado, de concorrência que exige MySQL explicitamente isolado). O teste específico foi repetido após acrescentar tratamento de requisição com vários arquivos no campo único e passou com as 130 asserções registradas acima. `git diff --check` passou. Conferência no banco configurado confirmou 2.145 contratos, a coluna `signed_category` instalada e nenhum upload de teste nos dados reais.
