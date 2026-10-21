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

## Lançamentos com item repetido — 21/10/2026

Locação de Imóvel preserva cada lançamento ativo em uma linha própria, mesmo quando o número do item se repete com códigos, descrições ou preços diferentes. O agrupamento por item continua exclusivo de Fornecimento. SupplyContractFigures::products recebe um terceiro argumento opcional groupByItem, verdadeiro por padrão; o emissor escolhe pelo modelo persistido. Quantidade × preço, soma exata, arredondamento em centavos e totais por ficha continuam validados nos dois modelos. Não há alteração de dados gravados.

Validação da correção: SupplyContractPdfTest e SupplyContractFiguresTest passaram (65 testes, 285 asserções). O teste novo foi repetido após acrescentar a rejeição de total divergente: 1 teste, 13 asserções, passou. SQLite em memória e armazenamento isolado. Pint dos três PHP alterados e git diff --check passaram. Sem emissão autenticada no navegador; nenhum dado do banco instalado foi alterado.

## Conferência do contrato original e retorno de falhas — 21/10/2026

A captura de produtos para emissão agora usa OriginalContractProducts, o mesmo filtro do painel de produtos, para excluir aditivos e vínculos incompatíveis com o instrumento original. A conferência de valores permanece obrigatória; divergências informam os dois totais com quatro casas decimais. Uma consulta somente de leitura ao banco instalado confirmou contratos de Locação cuja soma total incluía aditivos e cuja soma original correspondia ao valor inicial. Nenhum registro foi alterado.

O botão Contrato solicita o PDF com fetch autenticado. Falhas fecham a guia reservada ao PDF e exibem a mensagem na ficha aberta, preservando o formulário e liberando o botão para nova tentativa. Sucesso mantém a visualização em nova guia; caso o navegador bloqueie a guia, oferece o download do PDF. O GET direto continua devolvendo PDF inline ou erro HTTP; a página de erro 422 agora contém Voltar ao contrato apontando para sua edição. JSON, autorização, versões e auditoria continuam no servidor.

Validação: SupplyContractPdfTest e SupplyContractFiguresTest passaram com 66 testes e 298 asserções, usando SQLite em memória/storage isolado. Os quatro testes JavaScript de contrato/extrato passaram, incluindo falha seguida de nova tentativa e fechamento da guia de erro. Pint dos quatro PHP alterados, build Docker, limpeza das views e git diff --check passaram. Sem conferência autenticada em navegador.

## Caixa de erro da emissão — 21/10/2026

Falhas do botão Contrato aparecem em um dialog no padrão catalog-modal/product-item-warning, com título Emissão do contrato e botão OK. Fechar retorna o foco ao botão de emissão e permite nova tentativa. Nenhuma guia é aberta antes da confirmação de resposta PDF válida; somente sucesso abre o PDF, com download como alternativa caso o navegador bloqueie a guia. Este comportamento substitui a reserva antecipada de guia descrita acima e evita duplicar o sistema após erros.

Validação: quatro testes JavaScript de contrato/extrato passaram, cobrindo caixa modal, retorno de foco, nova tentativa e ausência de abertura de guia em falhas. Build Docker, limpeza de views e git diff --check passaram. Sem conferência visual autenticada no navegador.

## Título e assinaturas — 21/10/2026

O modelo de execução property-lease.docx usa o título CONTRATO DE LOCAÇÃO DE IMÓVEL Nº seguido do número salvo, substituindo o parágrafo longo de apresentação. As assinaturas usam LOCATÁRIO e LOCADOR. Os dados da assinatura do locatário são somente nome do gestor/signatário e CPF, com o mascaramento por perfil já existente; Decreto e Cargo foram retirados desse bloco. A qualificação no corpo permanece. O modelo original em Reference não foi alterado.

Validação: teste de emissão real do PDF passou com 30 asserções, incluindo título e assinatura; testes de mascaramento e revalidação do gestor passaram (2 testes, 14 asserções). SQLite em memória e storage isolado. Pint do teste e git diff --check passaram. Sem conferência visual autenticada no navegador.

## Retorno após salvar e histórico de manutenção — 21/10/2026

A tela de detalhes agora apresenta apenas entradas de alteração com as duas chaves before/after. Eventos de manutenção podem conter outros metadados, como referências a backups privados; esses dados permanecem na auditoria e não são exibidos nessa tela. A falha anterior ao acessar uma chave before ausente interrompia a página de retorno do salvamento, impedindo a exibição da confirmação embora a atualização já tivesse sido persistida. Nenhum evento ou contrato foi alterado para corrigir a renderização.

Validação: ContractWritingTest passou com 40 testes e 687 asserções em SQLite em memória e storage isolado. O teste novo cobre salvar, redirecionar, apresentar Contrato atualizado, manter a auditoria integral e não expor metadados privados ou entradas incompletas. Pint do teste, limpeza das views e git diff --check passaram. Sem conferência visual autenticada no navegador.

## Título único e assinatura compacta do locador — 21/10/2026

O modelo de execução remove o título curto CONTRATO Nº para manter somente CONTRATO DE LOCAÇÃO DE IMÓVEL Nº, centralizado e em negrito. A assinatura do locador exibe somente nome e documento (CPF para pessoa física, CNPJ para pessoa jurídica), usando o marcador interno DOCUMENTO_LOCADOR e preservando mascaramento por perfil. Endereço, RG e representação continuam na qualificação no corpo, mas não se repetem na assinatura.

Validação: os testes de emissão real do PDF e mascaramento passaram (2 testes, 41 asserções), cobrindo título único/centralizado/negrito e ausência de endereço/RG/representante nas assinaturas. SQLite em memória/storage isolado. Pint dos dois PHP alterados e git diff --check passaram. Sem conferência visual autenticada no navegador.
