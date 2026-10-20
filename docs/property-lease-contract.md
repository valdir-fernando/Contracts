# Contrato de Locação de Imóvel

O botão **Contrato** emite o PDF de contratos salvos do tipo **Locação de Imóvel**, além de Fornecimento. Salve alterações pendentes antes de emitir. O Extrato permanece disponível pelo botão próprio.

O original fornecido está em `Reference/Modelos_Doc/Contratos/Modelo_Loc_Imovel.docx`. A cópia de execução é `resources/documents/contracts/property-lease.docx`. Foi acrescentado um espaço antes de `n.` na referência à modalidade para separar os marcadores divididos em runs do Word; o original permanece intacto. As cláusulas são as do modelo fornecido, inclusive a previsão de IPTU. Não há alteração automática de valores, vigência ou obrigações cadastradas.

## Preenchimento

- Número, processo, modalidade, licitação, Fundo e endereço vêm do contrato salvo.
- Destinação usa `instalacoes`; valor mensal e extenso usam `vl_parcial` e `ext_parcial`; valor global e extenso usam `valor_total` e `ext_total`. Não se usa valor acumulado nem se calcula o aluguel pelo total.
- O locador vem dos snapshots do fornecedor. CPF identifica pessoa física; CNPJ identifica pessoa jurídica. A qualificação usa documento e endereço; pessoa física inclui RG, pessoa jurídica inclui representante/CPF/RG. CPF/RG seguem as regras de mascaramento por perfil.
- O gestor segue `ManagerSignatory::forContract`, preservando a regra de assinatura do Executivo para o Fundo municipal. Decreto e Matrícula são consultados na `tb_Gestor` somente com correspondência única por Fundo, nome e CPF do signatário. Dados ausentes ou vínculo ambíguo aparecem como **Não informado**, sem selecionar outro gestor pelo nome apenas. Esses dois campos também são revalidados após a renderização.
- A tabela usa os produtos ativos do próprio contrato, com sete colunas do modelo: Item, Cod, Discriminação, UND, QTD, VL_Unit e VL_Total. Cadastre os lançamentos no painel Produtos. Não são criados itens fictícios nem calculadas quantidades a partir da vigência.
- A conferência decimal existente exige multiplicação, soma dos produtos e valor inicial coerentes; dotações são resolvidas por ficha e somadas pelos produtos. Produtos ausentes, fichas sem dotação ou valores divergentes impedem a emissão com mensagem de revisão. Nada é corrigido no banco durante a emissão.

## Integração e segurança

As assinaturas públicas de `SupplyContractPdf::generate` e do controller permanecem iguais. `SupplyContractPdf::modelFor` resolve apenas as chaves internas `supply` e `property-lease` a partir do tipo persistido. `SupplyContractTemplate::path` e `render` recebem uma chave opcional, mantendo Fornecimento como padrão; não aceitam caminhos fornecidos pelo cliente.

O endpoint existente `GET /contracts/{contract}/document?version=...` mantém autorização `issue`, Fundo/Secretaria, locks de ator antes do contrato, versão e auditoria transacional. A renderização fica fora da transação. Antes da entrega são revalidados dados, produtos/dotações, perfil, permissões, signatário, Decreto/Matrícula, administração e hash do modelo. A auditoria `contract_document_generated` registra `model: property-lease` e hashes, sem dados pessoais em claro. O PDF é inline, privado e sem cache, com nome `contrato-locacao-imovel-{id}.pdf`. Não exige migration.

## Validação em 20/10/2026

- `docker compose exec -T app php artisan test --filter='SupplyContractPdfTest|SupplyContractTemplateTest|SupplyContractExtractTest' --compact`: 65 testes, 1.046 asserções; passou com SQLite em memória e storage/cache temporários. Cobre texto integral dos dois modelos, PDF real, sete colunas, mascaramento, botão, unicidade do gestor e alterações de dados/ator/permissão durante a renderização para ambos os tipos.
- `docker compose exec -T app composer check:architecture`: 3 testes, 4 asserções; passou.
- `docker compose exec -T app composer test:unit`: 77 testes, 169 asserções; passou.
- Pint dos sete PHP envolvidos passou.
- `docker compose exec -T app composer check`: Pint passou em 321 arquivos; PHPUnit concluiu 675 testes, 6.723 asserções e 1 ignorado, sem falhas. `docker compose exec -T app composer check:static` também passou no escopo configurado.
- `node --test tests/JavaScript/contract-document.test.mjs`: 1 teste passou, cobrindo versão e bloqueio de alterações pendentes. `npm.cmd run build` e `git diff --check` passaram.
- `docker compose exec --user www-data -T app php scripts/preview-property-lease-contract.php`: prévia sintética de nove páginas em `build/property-lease-preview/contract.pdf`, sem consultas ou escrita no banco. Texto extraído por `pdftotext`; primeira página e assinaturas rasterizadas com `pdftoppm` e conferidas visualmente. Ajustado o posicionamento da gestão no rodapé específico do modelo.

As primeiras execuções dos testes novos identificaram uma asserção que procurava marcadores também no CSS (`#fff`) e um usuário fictício sem permissão, porque o model protege `catalog_permissions` contra atribuição em massa. O teste passou a inspecionar somente o corpo do documento e a conceder explicitamente a permissão no preparo. O teste anterior do Extrato foi atualizado para esperar o botão Contrato agora disponível para Locação de Imóvel.

Não foi realizada emissão autenticada em navegador; a conferência visual usou apenas a prévia sintética. Nenhum registro do banco instalado foi alterado para validar esta implementação.
