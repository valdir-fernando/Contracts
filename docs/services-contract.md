# Contrato de Serviços Diversos

O botão **Contrato** emite o PDF de contratos salvos do tipo **Serviços Diversos**, usando `Reference/Modelos_Doc/Contratos/Modelo_Servicos.docx`. A cópia de execução é `resources/documents/contracts/services.docx`; o original permanece intacto. O tipo histórico sem acentos também é reconhecido pelo normalizador existente. Salve alterações pendentes antes de emitir.

O documento preserva os parágrafos e as cláusulas do Word. Número, processo, modalidade, licitação, datas, objeto, vigência, valor inicial e extenso vêm do contrato salvo. Fundo, fornecedor e representante usam os snapshots existentes; gestor segue `ManagerSignatory`. CPF e RG respeitam o perfil do emissor. A administração do rodapé segue a configuração dos documentos.

A tabela possui seis colunas: Item, Discriminação, UND, QTD, VL_Unit e VL_Total. O interpretador reconhece o título `DISRIMINAÇÃO` presente na tabela do Word e substitui suas linhas exemplificativas pelos lançamentos ativos do contrato original, filtrados por `OriginalContractProducts`. Serviços mantém os lançamentos separados, sem agrupamento por número de item. Quantidades, preços e totais usam a conferência decimal existente; soma divergente do valor inicial, produtos ausentes ou ficha sem dotação impedem a emissão. Não altera valores, vigências, produtos ou aditivos.

O endpoint permanece `GET /contracts/{contract}/document?version=...`. O modelo interno `services` é escolhido pelo tipo persistido; parâmetros HTTP não escolhem outro modelo ou caminho. O retorno é PDF privado inline, com nome `contrato-servicos-{id}.pdf`. Falhas na interface aparecem na caixa de mensagem de emissão já existente. Não há nova migration.

`SupplyContractPdf` preserva autorização `issue`, locks de ator antes do contrato, versão e captura transacional. A renderização ocorre fora da transação. Antes da entrega, revalida dados, produtos, dotações, configuração, assinatura, permissões, perfil e hash do modelo; a auditoria `contract_document_generated` registra a chave `services` e hashes. O Extrato mantém seus modelos atuais.

## Validação em 21/10/2026

- `docker compose exec -T app php vendor/bin/phpunit --filter='SupplyContractPdfTest|SupplyContractTemplateTest|SupplyContractFiguresTest' --do-not-cache-result`: 88 testes, 1.174 asserções, passou. Inclui preservação dos parágrafos do modelo, PDF real, seis colunas, tipo definido pelo servidor e 14 cenários de alterações durante a renderização de Serviços.
- Teste adicional de permissão de emissão e mascaramento, executado depois da suíte: 1 teste, 6 asserções, passou.
- `docker compose exec -T app composer check:architecture`: 3 testes, 4 asserções, passou.
- Pint dos seis arquivos PHP alterados, `git diff --check` e limpeza de views passaram.
- `docker compose exec -T app php scripts/preview-services-contract.php`: prévia sintética em `build/services-contract-preview/contract.pdf`, sem consultas ou escrita no banco.

Os testes usam SQLite em memória e storage/cache isolados. Nenhum dado do banco instalado foi alterado. Não houve conferência visual autenticada no navegador; a rasterização local da prévia não foi possível porque Python e os utilitários de PDF não estavam disponíveis.
