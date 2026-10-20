# PDF do contrato de Fornecimento

## Ajuste de cabeçalho e assinaturas nesta instalação

A linha do cabeçalho passa de 120 pt para aproximadamente 100,6 pt, próxima à base da logomarca. A identificação contrato/processo/página fica em 88 pt e o corpo começa em 110 pt. Essas posições substituem as medidas anteriores descritas abaixo; o rodapé permanece reservado.

Data, assinaturas de contratante/contratada e as duas testemunhas formam um único bloco com quebra interna proibida. O bloco é levado inteiro para a página seguinte quando o espaço restante não é suficiente. A grade de nove colunas mescladas do Word foi traduzida para duas colunas iguais para as partes e linhas de largura completa para testemunhas, com espaço para assinatura manual. Fonte de 9 pt nas assinaturas, espaçamento regular e eliminação dos parágrafos vazios de diagramação; texto das cláusulas preservado. Não foi necessário diminuir a fonte do corpo para manter o bloco unido.

Refinamento para assinatura digital: data do fechamento alinhada à direita, sem alterar seu valor; área de assinatura de cada parte ampliada de 28 para 60 pt (aproximadamente 21 mm). Linhas das testemunhas recebem 38 pt de espaço superior, separando as duas áreas de assinatura; o CPF recebe somente 1 pt de padding superior e permanece próximo da própria testemunha. A linha do cabeçalho foi aproximada em mais 2 pt da logomarca. Nova prévia inicial/final conferida visualmente com Poppler; os testes verificam altura útil para assinatura digital, proximidade dos CPFs, separação das testemunhas e alinhamento da data nas coordenadas/estilos renderizados. `php artisan test --filter=SupplyContractTemplateTest`: 5 testes, 550 asserções, passou. Nenhum dado do contrato foi alterado.

Validação final do refinamento: `composer check` passou no Pint (299 arquivos) e PHPUnit (608 testes, 5.981 asserções, sem falhas, um ignorado). `composer check:architecture`: 3 testes, 4 asserções; `composer test:unit`: 77 testes, 169 asserções; `npm run build` via container `node`: sucesso; `git diff --check`: sucesso. Comandos PHP executados por `docker compose exec -T app`; testes em SQLite temporário. Prévia gerada com `php build/supply-preview/preview-layout.php` e rasterizada com `pdftoppm`, sem emissão autenticada nem gravação no banco.

Produtos continuam em tabela única, consolidada por item do contrato com quantidades e totais somados. A tabela pode ocupar várias páginas, repetindo os títulos das colunas. Dotações continuam por ficha. O bloco indivisível é apenas o fechamento, permitindo que o corpo, produtos e dotações paginem normalmente.

`SupplyContractTemplateTest` verifica as páginas e coordenadas efetivamente renderizadas pelo Dompdf: todas as assinaturas/testemunhas/data na mesma página, sem invasão de rodapé ou página vazia posterior, preservação do texto do Word e posição da linha do cabeçalho. Cenários incluem espaço final restrito, nomes extensos, 120 produtos e 35 dotações; também verificam o último produto/dotação e repetição dos cabeçalhos de produtos. Testes específicos: 5 testes, 486 asserções, aprovados.

Prévia de conferência gerada sem escrita no banco: `build/supply-preview/layout-updated.pdf`, com textos cadastrais fictícios e produtos/dotação do 015/25 do Município. As páginas inicial e final foram rasterizadas com Poppler e conferidas visualmente. Poppler foi instalado somente como ferramenta no container local, sem adicionar dependência ao projeto. A prévia não constitui emissão autenticada.

Verificação final deste ajuste: `docker compose exec -T app composer check` passou no Pint (299 arquivos) e PHPUnit (608 testes, 5.917 asserções, sem falhas; um teste ignorado). `composer check:architecture` passou com 3 testes e 4 asserções; `composer test:unit` passou com 77 testes e 169 asserções; `docker compose run --rm --no-deps node npm run build` concluiu; `git diff --check` passou. Testes em SQLite em memória e storage/cache temporários. Nenhum dado do banco instalado foi alterado por este ajuste visual. A primeira execução do teste novo identificou um problema na preparação dos marcadores fictícios divididos entre runs do Word; a preparação passou a ler os parágrafos XML antes de extrair marcadores, sem alterar as verificações de paginação.

Ajuste visual de 19/10/2026: Item ocupa 6% e VL_Unit 11% da tabela; TOTAL GERAL fica à direita. Dotação e valor são medidos com a fonte efetiva do Dompdf e permanecem na mesma linha, com pontilhado limitado ao espaço restante e redução proporcional da fonte quando necessário. O cabeçalho reserva 297 pt ao nome do Fundo, em negrito e até 18 pt, reduzindo-o para caber sem invadir a logomarca. A linha inferior fica na posição 120 pt da página; contrato/processo e paginação são desenhados juntos pelo canvas, alinhados à direita, logo acima da linha. O corpo começa em 130 pt.

Foram geradas prévias sintéticas e conferidas visualmente as páginas de cabeçalho, produtos e dotações, além de um nome de Fundo longo. Artefatos locais ignorados em `build/supply-preview/`. Nenhum dado do banco foi alterado para essas prévias. O layout usa apenas PHP; não exige migration ou build de frontend.

Validação do ajuste visual: Pint passou; arquitetura passou (3 testes, 4 asserções); unidade passou (77 testes, 169 asserções). `composer check` executou 603 testes e 5.547 asserções, com as três falhas anteriores de nomes em maiúsculas e um teste ignorado; Pint passou em 299 arquivos. A verificação específica final de `SupplyContractPdfTest|SupplyContractTemplateTest` passou com 28 testes e 311 asserções, incluindo a exigência de uma única linha de dotação mesmo com texto longo. `git diff --check` passou. Rasterização e extração de texto da prévia foram feitas com Poppler, sem dependência nova da aplicação.

Atualização de 19/10/2026: códigos de dotação vêm de `tb_Dotacao`; valores vêm da soma dos produtos por ficha. Conferência local somente leitura do `015/25` do Município: ficha `20250031`, dotação `02.0217.04.122.7008.8040.339030(100)20250031`, valor `2530.9200`, igual ao total do contrato. `contracts:import-budgets` confirmou 1.733 dotações do modelo já existentes, nenhuma nova ou divergente. Não houve migration ou alteração de frontend nesta atualização.

Validação desta atualização: Pint passou nos quatro arquivos alterados; testes `SupplyContractPdfTest|SupplyContractFiguresTest|SupplyContractTemplateTest` passaram (48 testes, 350 asserções); arquitetura passou (3 testes, 4 asserções); unidade passou (77 testes, 169 asserções). Testes usam SQLite em memória; o banco instalado foi consultado apenas para conferência dos valores, além da execução idempotente do importador de dotações. Os testes agora cobrem cálculo sem registros financeiros, duplicatas do catálogo, ausência/conflito de código, soma e centavos, e alteração/inclusão/exclusão de dotação durante a renderização.

Verificação geral desta atualização: `composer check` passou no Pint (299 arquivos); PHPUnit executou 603 testes e 5.547 asserções, com as mesmas três falhas anteriores de expectativas de nomes em maiúsculas em `AuditCorrectionsTest`, `CatalogDelegationTest` e `SupplierAssistanceTest`, e um teste ignorado. Os testes alterados passaram na suíte completa. `git diff --check` passou. Build não repetido, pois a mudança é exclusivamente PHP.

O botão **Contrato**, na tela de cadastro/edição, abre o PDF em outra aba para contratos salvos do tipo **Fornecimento**. Salve alterações pendentes antes da emissão. Outros tipos continuam aguardando seus modelos; o servidor verifica o tipo persistido, independentemente de parâmetros enviados na URL.

## Modelo e preenchimento

O original é `Reference/Modelos_Doc/Contratos/Modelo_Fornecimento.docx`. A cópia usada pela aplicação fica em `resources/documents/contracts/supply.docx` e deve acompanhar o código nas instalações. Por orientação do usuário, o parágrafo `#FICHA` foi removido dessa cópia de emissão; o arquivo de referência original foi preservado. Não exige migration, LibreOffice ou serviço externo.

`SupplyContractTemplate` lê os parágrafos e a formatação de ênfase do Word, inclusive marcadores divididos entre runs. Preserva as dezesseis cláusulas, as assinaturas e as testemunhas. Usa as imagens institucionais do modelo; substitui a legenda de gestão embutida no rodapé pela administração configurada. Os números de contrato/processo e a paginação do cabeçalho são calculados na emissão. A paginação se adapta ao conteúdo e pode diferir da paginação no Word.

| Marcadores | Origem |
| --- | --- |
| `#CONTRATO`, `#PROCESSO`, `#MODALIDADE`, `#N_LICIT`, `#DATA_LICIT`, `#DATA_CONTRATO` | Dados processuais salvos no contrato; datas em dia/mês/ano |
| `#FUNDO`, `#CNPJ_FUNDO`, `#END_FUNDO` | Snapshots do contratante salvos no contrato |
| `#NOME_GESTOR`, `#CARGO_GESTOR`, `#CPF_GESTOR`, `#RG_GESTOR` | `ManagerSignatory`: responsável atual autorizado pelo Executivo municipal; nos demais Fundos, snapshot do contrato |
| `#FORNECEDOR`, `#CNPJ_FORNECEDOR`, `#END_FORNECEDOR` | Snapshots salvos do fornecedor |
| `#REPRESENTANTE_FORNECEDOR`, `#CPF_REPRESENTANTE`, `#RG_REPRESENTANTE` | Sócio/representante salvo no contrato |
| `#OBJETO`, `#VIGENCIA`, `#INICIO`, `#FINAL` | Objeto, vigência por extenso, início e término **original** |
| `#VL_CONTRATO`, `#EXTENSO` | Valor inicial e seu extenso já salvo; não usa valor acumulado nem calcula reajustes |
| Tabela única de produtos, sem `#FICHA` | Produtos ativos de `tb_Produtos_Contratos`, exclusivamente pelo `contract_id`, agrupados por número de item independentemente da ficha. Colunas Item, Cod, Discriminação, Marca, UND, QTD somada, VL_Unit e VL_Total somado; última linha TOTAL GERAL |
| `#DOTAÇÃO`, `#VL_FICHA` | Código consultado na `tb_Dotacao` pela ficha exata dos produtos ativos do contrato autorizado. Valor calculado pela soma dos totais desses produtos por ficha, com uma linha por ficha, código à esquerda e valor à direita, ligados por pontilhado, sem cabeçalho ou grade |

A linha fictícia de produtos do modelo nunca é emitida. Descrições extensas de produtos e códigos de dotação são divididos em linhas de continuação, sem repetir quantidades ou valores, para permitir a paginação. A emissão não altera contratos, produtos, saldos ou registros financeiros. Somente os produtos ativos do contrato autorizado fornecem fichas e valores; `tb_Dotacao` fornece o código pela ficha, sem filtro adicional de Fundo/Secretaria. Dados cadastrais ausentes são identificados como não informados.

### Conferência dos produtos

`SupplyContractFigures` usa Brick Math e strings decimais, sem ponto flutuante. Valida QTD positiva, preço/total não negativos e até quatro casas decimais. Confere cada lançamento com `QTD × VL_Unit`, arredondado para quatro casas por HalfUp, como na persistência existente. Para cada item, soma QTD e total e confere novamente a multiplicação com o preço unitário único. Item não é confundido com código: itens distintos com o mesmo código continuam separados. Zeros iniciais no item e na ficha são preservados.

Lançamentos do mesmo item com códigos, descrições, marcas, unidades ou preços diferentes são recusados, em vez de escolher arbitrariamente um dos dados. A soma geral exata deve corresponder ao **valor inicial salvo** do contrato. A soma dos totais exibidos em centavos também precisa corresponder ao total do contrato em centavos. Divergências de arredondamento não geram correções automáticas. Quantidades e preços exibem no mínimo duas casas e preservam até quatro casas significativas; totais monetários aparecem em centavos.

Ausência de produtos, divergência de multiplicação, soma ou dados incompatíveis impede a emissão com 422 e mensagem de revisão. Nenhum PDF ou evento de emissão é entregue nessa situação, e os dados salvos ficam intactos. Os totais dos produtos são somados por ficha exata e a soma das dotações deve corresponder ao valor total inicial do contrato, inclusive nos centavos exibidos. Não depende de registros financeiros de dotação/ficha nem de `tb_ValorFicha`. Produtos sem ficha, dotação ausente/vazia ou códigos conflitantes para a mesma ficha exigem revisão. Duplicatas do mesmo código no catálogo não duplicam o valor da ficha.

## Segurança e transporte

`GET /contracts/{contract}/document?version=...` (`contracts.document`) exige autenticação, usuário ativo, permissão `issue`, escopos atuais e versão válida. Retorna PDF inline, com cache `private, no-store` e `nosniff`. Não grava arquivo público nem gera link de download reutilizável sem autenticação.

`SupplyContractPdf` bloqueia ator antes do contrato, autoriza e captura uma projeção explícita sob transação. CPF e RG seguem o mascaramento por perfil usado nos demais documentos; apenas administradores recebem esses campos completos. O PDF é renderizado fora da transação. Antes da entrega, uma segunda transação reautoriza o ator e compara tipo, perfil, versão, campos, produtos/fichas, linhas correspondentes de `tb_Dotacao`, responsável pela assinatura, administração e hash do modelo. Alteração, inclusão ou exclusão de código na ficha utilizada recebe conflito, sem entregar o PDF.

O evento `contract_document_generated` é gravado na transação final, com versões e hashes do PDF/modelo/origem, sem conteúdo pessoal em claro. Uma falha de auditoria impede a resposta com o PDF. O interpretador aceita somente o modelo confiável empacotado e escapa valores; PHP e recursos remotos do Dompdf ficam desabilitados.

## Validação

Os testes usam SQLite em memória e storage/cache temporários. `SupplyContractPdfTest` cobre PDF real, projeções, tipos, revisão, escopos, permissão de emissão, mascaramento, exclusões, rascunhos, alterações durante a renderização, falha de auditoria, produtos extensos, tabela única, soma de dotações por ficha, rejeição de divergências e disponibilidade do botão. `SupplyContractTemplateTest` compara todos os parágrafos do corpo com o texto do Word, inclusive pontuação e marcadores divididos. `SupplyContractFiguresTest` usa PHPUnit puro, sem Laravel ou banco, para testar cálculo decimal, agrupamento, preços incompatíveis, arredondamentos e somas por ficha. O teste JavaScript verifica alterações pendentes, inclusive mudança do tipo, e atualização da versão após salvar produtos.

Foi gerada uma prévia com dados fictícios e conferidas visualmente as páginas inicial, de produtos, dotações e assinaturas. Na atualização de consolidação, quatro lançamentos de duas fichas resultaram em dois itens: QTD 2 × 327.990,00 = 655.980,00 e QTD 2 × 333.990,00 = 667.980,00, totalizando 1.323.960,00, igual ao valor do contrato. As dotações ficaram em duas linhas de 661.980,00 por ficha. Nenhum banco instalado foi alterado para validar a emissão.

Comandos efetivamente executados em 19/10/2026:

| Comando | Resultado |
| --- | --- |
| `docker compose exec -T app composer check:architecture` | 3 testes, 4 asserções; passou |
| `docker compose exec -T app composer test:unit` | 75 testes, 165 asserções; passou |
| `docker compose exec -T app composer check` | Verificação final: Pint passou em 295 arquivos. PHPUnit executou 589 testes, 5.446 asserções, com 3 falhas e 1 ignorado; ver limitações abaixo |
| `docker compose exec -T app php artisan test --filter='SupplyContractPdfTest\|SupplyContractTemplateTest\|SupplyContractExtractTest\|DocumentSettingsTest\|ExecutiveSignatoryTest'` | Validação anterior à consolidação: 45 testes, 447 asserções; passou |
| `docker compose exec -T app php artisan test --filter='SupplyContractPdfTest\|SupplyContractTemplateTest\|SupplyContractFiguresTest'` | Consolidação final, validação dos totais e soma por ficha: 41 testes, 328 asserções; passou |
| `docker compose exec -T app vendor/bin/pint app/Infrastructure/Documents/SupplyContractTemplate.php tests/Feature/SupplyContractPdfTest.php` | Formatação dos arquivos da emissão; passou |
| `docker compose exec -T app composer check:static` | Passou no escopo configurado de Domain/Application |
| `npm.cmd test` | 87 testes JavaScript; passou |
| `npm.cmd run build` | Build Vite concluído |
| `docker compose exec -T app php artisan route:list --path=contracts --name=contracts.document` | Rota de emissão registrada |
| `git diff --check` | Sem erros |

As três falhas da suíte completa são expectativas de nomes anteriores à normalização para maiúsculas já presente no workspace: `AuditCorrectionsTest::test_old_user_form_cannot_restore_replaced_scopes` espera `Regular`, mas recebe `REGULAR`; `CatalogDelegationTest::test_supplier_permission_allows_creation_and_own_catalog_but_not_unrelated_records` espera `Fornecedor próprio`, mas recebe `FORNECEDOR PRÓPRIO`; `SupplierAssistanceTest::test_supplier_modal_keeps_validation_and_success_on_the_supplier_list` espera `Fornecedor novo`, mas recebe `FORNECEDOR NOVO`. As alterações existentes de usuários/fornecedores e esses testes foram preservados. A suíte completa foi repetida após a consolidação final, sem novas falhas na emissão. A validação não incluiu conexão MySQL descartável ou ensaio de navegador autenticado. Não foi necessário recompilar assets nesta última correção, restrita ao PHP e ao DOCX.
