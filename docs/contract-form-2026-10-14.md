# Formulário de contratos — 14/10/2026

## Aviso de salvamento em janela — 27/10/2026

Após salvar no editor, a janela padrão do sistema abre automaticamente com **Contrato Registado com Sucesso** na criação e **Contrato Atualizado com Sucesso** nos salvamentos de um registro existente. O botão **OK** e Escape fecham a janela. A mensagem usa flash de sessão e não reaparece ao recarregar o editor; falhas de validação não recebem confirmação de sucesso. Este comportamento substitui os textos e a apresentação em aviso inline registrados em 22/10 abaixo.

O controlador define a mensagem pela rota efetivamente concluída e sinaliza `contract_saved` somente após a gravação bem-sucedida com `_stay_in_editor=1`. O layout do editor reutiliza `catalog-modal`, `data-modal-auto-open` e `data-modal-close`, sem JavaScript novo. Requisições externas sem a preferência de editor preservam respostas anteriores.

`docker compose exec -T -e COMPOSER_PROCESS_TIMEOUT=0 app composer check` concluiu: Pint passou em 357 arquivos; PHPUnit executou 836 testes, 8.580 asserções, 12 falhas e 1 ignorado. A quantidade de falhas permanece igual à registrada anteriormente em `architecture.md`, em testes de apresentação de detalhes, documentos, aditivos e `SelectedFundTest`. A rodada focada inicial incluindo `test_save_confirmation_renders_with_maintenance_audit_metadata` teve uma falha porque a tela de detalhes atual não contém “Histórico de alterações”; o template de detalhes já não contém esse bloco no HEAD. Essa asserção foi preservada. Não se declara aprovação integral de `composer check`.

Validação: testes focados de criação/atualização/falha (`docker compose exec -T app php vendor/bin/phpunit --filter='ContractWritingTest::test_new_contract_from_editor|ContractWritingTest::test_editor_save_returns|ContractWritingTest::test_failed_editor_save' --do-not-cache-result`) passaram: 3 testes, 43 asserções, incluindo janela acessível, botão OK, versão atual e ausência do aviso após recarga. `composer check:architecture` (3 testes), `composer test:unit` (81 testes), Pint dos PHP alterados, build Docker (`docker compose run --rm --no-deps node npm run build`), `php artisan view:clear` e `git diff --check` passaram. Testes com SQLite em memória e storage/cache temporários; sem mutações no banco instalado ou conferência visual autenticada.

## Painéis reservados no acervo — 22/10/2026

Na tela Contratos, os botões Aditivo e Distrato aparecem após Novo contrato, sob a mesma permissão de apresentação. Cada botão abre um painel modal vazio com título e fechamento por X ou Escape, reservado para implementação futura. Não há formulários de gravação, novas rotas ou alteração dos fluxos existentes de aditivos/distrato.

Validação: `docker compose exec -T app php artisan view:cache` e `view:clear`; `docker compose run --rm --no-deps node npm run build`; `docker compose exec -T app php vendor/bin/phpunit --filter='ContractIssuancePermissionTest|test_authenticated_pages_do_not_display_the_fund_switcher' --do-not-cache-result` (4 testes, 65 asserções, SQLite isolado); `git diff --check`. Todos passaram. Sem conferência visual autenticada no navegador.

## Confirmação ao salvar a edição — 22/10/2026

O botão Salvar do formulário de edição retorna ao próprio editor e exibe “Contrato alterado com sucesso.”, em vez de abrir os detalhes do contrato. A página do editor é recarregada com os dados persistidos, Secretaria e versão atuais, permitindo continuar a edição. O formulário envia `_stay_in_editor=1` como preferência de apresentação; esse campo não autoriza operações nem integra o patch persistido. As verificações de ator, contrato, policy, versão, validação e auditoria permanecem no fluxo existente. Criação e requisições externas sem essa preferência preservam seus redirecionamentos anteriores.

O formulário de novo contrato também envia essa preferência: ao salvar, abre o próprio editor do registro criado e exibe “Regstro Efetuado com Sucesso”, conforme o texto solicitado, sem passar pela tela de detalhes. O número atribuído, a versão e os painéis de produtos/documentos passam a usar o contrato persistido. Requisições sem a preferência mantêm o contrato HTTP anterior. Validação adicional: `docker compose exec -T app php vendor/bin/phpunit --filter=ContractWritingTest --do-not-cache-result` passou (42 testes, 712 asserções, SQLite isolado); Pint `--test` dos dois PHP alterados e `git diff --check` passaram. Sem build adicional, por alterar somente PHP e campo oculto da view; sem conferência visual autenticada.

Validação: `docker compose exec -T app php vendor/bin/phpunit --filter='ContractWritingTest|InspectorDefaultsTest' --do-not-cache-result` passou (50 testes, 812 asserções, SQLite e storage isolados); Pint `--test` de ContractController e ContractWritingTest passou; `docker compose exec -T app composer check:architecture` passou (3 testes, 4 asserções); `git diff --check` passou. Sem alteração de assets ou build adicional; sem conferência visual autenticada no navegador.

## Verificação final antes dos commits

Na consolidação das alterações de contratos, fornecedores e Ordens de Serviço, foram executados pelo Docker: `composer check` (Pint passou; 366 testes, 3346 asserções, 1 ignorado), `composer check:architecture` (3 testes), `composer test:unit` (57 testes), `npm test` (39 testes) e `npm run build`, todos com sucesso. A suíte PHP usou SQLite em memória e storage/cache temporários, sem testes no banco instalado. `git diff --check` passou. A única migration aplicada nesta sessão foi a aditiva de Ordens de Serviço, no MySQL local; a nova soma de produtos/serviços ainda não foi implementada. Não houve implantação em hospedagem externa.

## Sugestão de Objeto ao alterar os campos de origem

Alterar Tipo de contrato, Endereço do imóvel, Instalações, Cargo Credenciamento - Saúde, Local do serviço ou CPF/CNPJ do fornecedor atualiza Objeto nas seguintes condições: Locação de Imóvel com Endereço do imóvel e Instalações preenchidos gera “Locação do imóvel situado no(a) [endereço] destinado as instalações do(a) [instalações].”; Credenciamento com Cargo Credenciamento - Saúde e Local do serviço preenchidos gera “Prestação de Serviços como [cargo] para trabalhar no(a) [local] ...” para CPF e limpa Objeto para CNPJ. Sem os dados exigidos, ou para outros tipos, a descrição atual permanece. Os gatilhos são input, change e saída de foco dos campos de origem, além da seleção de fornecedor pelo catálogo; não se aplica retroativamente ao abrir a página. Não emite nova alteração se o texto já for igual.

Na edição com documento pessoal protegido, o HTML expõe apenas a classificação CPF/CNPJ, sem revelar o documento para permitir a sugestão. A descrição gerada continua editável e passa pela validação, autorização e auditoria habituais ao salvar. Trata-se de assistência de preenchimento; nenhuma regra jurídica é inferida.

Validação: `npm test` pelo Docker passou (39 testes); build passou; teste PHP filtrado da proteção do resumo passou (44 asserções), `view:clear` executado e `git diff --check` passou. Sem ensaio interativo no navegador nesta alteração.

O painel **Justificativa da alteração** foi removido do formulário de contratos. Na edição, um campo oculto envia o motivo padrão “Atualização cadastral pelo formulário de contratos.”. A validação existente de motivo e a auditoria transacional de ator/diferenças permanecem ativas; o texto é uma descrição, sem efeito de autorização. Validação: teste `test_editor_omits_reason_panel_and_submits_default_audit_reason` passou (8 asserções, SQLite em memória), Pint passou, `view:clear` executado e `git diff --check` passou. Sem build adicional por ser alteração apenas de view e teste.

## Indicadores financeiros somente para leitura

Cada indicador salvo exibe abaixo seu texto por extenso correspondente (`ext_parcial`, `ext_total` e `ext_acumulado`), em negrito e azul Del Rey. Sem texto registrado, aparece “Valor por extenso não informado.”; sem valor monetário, não há mensagem. Os textos não são editáveis, enviados ou recalculados. Validação desta apresentação: teste filtrado dos indicadores passou (28 asserções), Pint passou, build passou, `view:clear` executado e `git diff --check` passou. Sem inspeção interativa no navegador nesta alteração.

O painel Financeiro apresenta **Valor_Mensal**, **Valor do Contrato** e **Valor acumulado Aditivo**, usando os valores persistidos de `vl_parcial`, `valor_total` e `valor_acumulado`, sem controles editáveis ou campos enviados no POST. Contratos novos exibem A calcular. Saldo do contrato informado também foi removido da exibição; sua coluna permanece preservada.

O POST de criação aceita a ausência de `valor_total` enquanto o cadastro de itens não existir, armazenando-o como não informado. Se um cliente existente enviar esse campo, continuam valendo as validações monetárias e a obrigatoriedade de conteúdo; os contratos HTTP anteriores que enviam valores permanecem aceitos. As interfaces internas/CLI não mudam. A edição pela tela omite esses indicadores e preserva os valores históricos. Não há cálculo de mensalidade ou soma de aditivos nesta etapa.

Validação: `ContractWritingTest` passou (33 testes, 542 asserções), Pint nos arquivos PHP alterados passou, `composer check:architecture` passou, `view:clear` executado e `git diff --check` passou. Testes usam SQLite em memória. Sem build adicional ou ensaio interativo no navegador nesta alteração de view e validação contextual.

O painel Financeiro omite os controles Saldo disponível informado, os três valores por extenso, Ficha e Empenho no cadastro e na edição. Colunas, registros históricos e dados omitidos na gravação permanecem preservados. Validação: teste `test_removed_financial_controls_preserve_stored_history` passou (25 asserções, SQLite em memória), Pint passou, `view:clear` executado e `git diff --check` passou. Sem build adicional, pois somente a view e o teste foram alterados nesta etapa.

## Vigência e garantia

O painel inferior **Resumo de execução e aditivos** foi removido da tela. Dados históricos de execução permanecem no banco e protegidos contra edição pelo formulário. Os três indicadores de término atualizado, número e processo do aditivo continuam na linha de vigência. Validação desta remoção: teste filtrado do resumo passou (44 asserções), `view:clear` executado e `git diff --check` passou; sem build adicional por ser mudança apenas de view e teste.

A linha de término agora contém Final da Vigência, Final_Vig_Atualizada, Nº Aditivo e Proc_Aditivo nas proporções 50:10:10:30 do espaço útil. O término atualizado ocupa 20% da largura anterior. Número e processo vêm de `n_aditivo` e `proc_aditivo` do contrato, correspondentes a `N_Aditivo` e `Proc_Aditivo`, sem edição nem envio HTTP. São os resumos persistidos, sem seleção automática de um aditivo mais recente. O resumo inferior não repete esses campos. Validação desta disposição: teste filtrado de proteção do resumo passou (45 asserções), Pint e build passaram, `view:clear` executado e `git diff --check` passou. Sem ensaio interativo no navegador nesta etapa.

A mensagem por extenso inicia oculta, mesmo quando há valor salvo. Só aparece ao sair de Valor da garantia com conteúdo preenchido; editar o valor oculta novamente, e sair com campo vazio mantém a mensagem oculta. Validação desta interação: `npm test` pelo Docker passou (36 testes), teste PHP filtrado da mensagem passou (8 asserções), build passou e `view:clear` foi executado. Sem ensaio interativo no navegador nesta etapa.

**Final da Vigência** (antigo Término original) e **Final_Vig_Atualizada** ficam na mesma linha. O segundo exibe exclusivamente `Final_Vig_Atualiz`/`vigencia_fim_atual` persistido, somente para leitura e sem atributo `name`; entradas HTTP ou old input não substituem esse valor. A proteção de escrita do resumo de execução permanece no validador. Sem data persistida, exibe Não informado. O resumo inferior mantém apenas os demais dados de execução, evitando repetir o término atualizado.

Na linha seguinte, Tipo de garantia, **(%) Garantia**, Valor da garantia e Prazo da obra ficam juntos, nas proporções 18:7,5:32,5:42 do espaço útil. Tipo e percentual ficaram respectivamente 10% e 50% menores que na disposição anterior. Até 600 pixels, os campos passam para uma coluna para manter a leitura. O ícone de Ordens de Serviço permanece no prazo.

Garantia por extenso deixa de ser um campo editável e exibe o texto `ext_garantia` persistido abaixo de Valor da garantia, em negrito e azul Del Rey (`#1b3b6f`). Sem conteúdo salvo, mostra “Garantia por extenso não informada.”. A mensagem não é enviada pelo formulário nem recalculada; valores históricos omitidos são preservados na edição. Validação: `ContractWritingTest` passou (31 testes, 494 asserções); Pint no teste, build pelo Docker e `git diff --check` passaram; cache de views limpo. Sem ensaio interativo de navegador nesta alteração.

## Abertura direta pela lupa

Quando Nº do Contrato estiver preenchido, clicar na lupa (ou pressionar Enter) consulta `GET /contracts/lookup` com `exact=1`. O servidor usa igualdade do número e o Fundo autorizado da sessão, mantendo `visibleTo`, revalidação do ator e projeção restrita a número, processo e URL autorizada. Um único resultado abre diretamente a edição do contrato, sem exibir a janela de consulta. Número vazio, ausente ou ambíguo abre a consulta habitual; duplicidades históricas nunca selecionam arbitrariamente um contrato. Alterar o número cancela a busca pendente. A requisição padrão sem `exact` mantém a busca parcial anterior.

Validação: `docker compose exec -T app php artisan test --filter=ContractWritingTest` passou (30 testes, 476 asserções, SQLite em memória); `docker compose run --rm --no-deps node npm test` passou (35 testes); Pint nos dois arquivos PHP alterados e build pelo Docker passaram; `view:clear` executado e `git diff --check` passou. Os testes cobrem número exato, Fundo da sessão, escopos, duplicidade, abertura direta, consulta alternativa, resposta obsoleta e falha de rede. Sem ensaio interativo de navegador nesta alteração.

## Apresentação final

Os campos editáveis do sistema usam fundo #808080, com texto e placeholder escuros para leitura. A regra se aplica a inputs de dados, selects e textareas habilitados; controles somente leitura e desabilitados mantêm sua aparência anterior. Botões, checkboxes e rádios preservam seus estilos.

Todos os controles de uma linha do formulário (inputs, selects e textareas de uma linha) usam a altura compacta de Instalações, inclusive Secretaria e Tipo de contrato. Valores somente leitura têm a mesma altura mínima e podem crescer para exibir conteúdo longo. Campos de várias linhas preservam sua altura. O cálculo acompanha a fonte maior em telas pequenas; o botão de calendário cabe dentro do controle compacto.

Dados Contratante apresenta somente valores de consulta. O fiscal e seus dados são preenchidos pelo Fundo ou pela Secretaria do Município, sem entrada manual nem confiança em snapshots HTTP. [Regras, importação de tb_Fiscal e validação](inspector-autofill-2026-10-15.md).

Fiscal tem indicação de campo obrigatório. A gravação exige o fiscal automático ou um histórico preenchido mantido no mesmo contexto; ausência e ambiguidade impedem salvar e exibem erro junto ao campo. A obrigatoriedade também é validada no servidor.

Escopo usa seleção opcional com as opções “Escopo” e “Contínuo”, validadas no servidor. Na edição, valores históricos diferentes permanecem disponíveis para aquele contrato e são preservados quando omitidos; podem ser substituídos pelas opções atuais.

Um ícone informativo ao lado de Escopo abre uma explicação em popover ao passar o mouse, focar pelo teclado ou clicar/tocar. A mensagem segue Reference/Escopo_Continuo.jpg: introdução e tabela comparativa com objetivo, execução, exemplos, encerramento e vigência. Os prazos foram descritos com as condições dos arts. 106, 107 e 111 da Lei nº 14.133/2021, sem efeitos automáticos no sistema. O popover tem largura ampliada, rolagem vertical e tabela com rolagem horizontal em telas pequenas. A janela aparece acima dos painéis, sem ser cortada pela rolagem; permite Escape e fechamento ao clicar fora.

O cabeçalho apresenta o campo editável “Nº do Contrato” no lugar do título visual Novo contrato/Editar contrato, com placeholder “N° do Contrato” e lupa interna. Ao abrir um contrato, exibe `numero` persistido. Digitar um número e clicar na lupa ou pressionar Enter abre uma janela de busca; selecionar um resultado abre a edição. A digitação serve à consulta e não altera a numeração automática nem integra o formulário de gravação.

`GET /contracts/lookup` recebe `q` (trecho literal do número, até 100 caracteres) e `page`, com 15 resultados por página. Revalida o ator e o Fundo da sessão, aplica `visibleTo` e exclui soft deletes. Parâmetros HTTP não substituem o Fundo. A resposta privada, sem cache, projeta apenas número, processo e URL autorizável de edição, além da página atual e final; não retorna models nem dados pessoais. A edição reautoriza o acesso ao abrir. A janela oferece busca, paginação, estado vazio/erro, fechamento por Escape e retorno do foco à lupa. Consultas substituídas ou encerradas são canceladas.

Validação desta busca: `ContractWritingTest` passou (26 testes, 405 asserções), `composer check:architecture` passou (3 testes), Pint passou nos arquivos PHP alterados, `npm test` passou (11 testes existentes), `npm run build` e `git diff --check` passaram. Não foi executado ensaio de navegador para a nova janela.

No painel Vigência execução e aditivos, Término atualizado informado, Número de aditivo (resumo legado), Processo do aditivo, Número da ordem de serviço e Data da ordem de serviço aparecem somente na edição, no quadro interno “Resumo de execução e aditivos”, sem controles editáveis. O quadro consulta os valores persistidos e respeita a apresentação autorizada por perfil. As rotas de cadastro e edição recusam valores não vazios desses campos e nunca os incluem no patch gravável, mesmo quando enviados como null ou vazios. As integrações internas permanecem compatíveis; uma janela específica para manutenção posterior ainda será implementada. A alteração não aplica efeitos financeiros ou de vigência.

O painel Dados Processuais não exibe Código de origem, Número do contrato ou Identificação completa no cadastro e na edição. A geração automática da numeração, o número apresentado no cabeçalho e os dados históricos permanecem preservados.

Cadastro e edição abrem com apenas o primeiro painel (Dados Processuais) ativo. Após a inicialização do Alpine, o foco vai para Secretaria quando habilitada para o Município de Niquelândia; nos demais casos, vai para Tipo de contrato. O foco não desloca a página. Novo Contrato abre o cadastro com essa mesma inicialização. Expandir Tudo continua disponível para exibir todos os painéis.

No cadastro e na edição, cabeçalho, Secretaria, Tipo de contrato e navegação permanecem fixos; somente os painéis têm rolagem própria. Os botões de seção deslocam apenas essa área, com foco no título sem mover a página. A barra de ações contém Salvar contrato, Novo Contrato, Editar Contrato e Excluir Contrato, com as cores azuis e hover da navegação; Cancelar foi removido. Editar e Excluir ficam desabilitados antes do primeiro salvamento. Em contratos existentes, as ações respeitam as policies, e Excluir abre a confirmação existente (número e motivo) na tela de detalhes. Os botões permanecem acessíveis; Anterior/Próxima seção foram retirados.

O cabeçalho não apresenta data, calendário, avatar, breadcrumb duplicado ou seletor de Fundo. Voltar fica acima do nome do Fundo, cuja fonte se adapta à largura disponível; o campo Nº do Contrato fica à direita, e o nome do fornecedor fica à esquerda, logo abaixo do Fundo, em vermelho claro (#f5a3a3), igual ao hover de Expandir Tudo. Secretaria e Tipo de contrato ficam sem moldura, com os botões de documentos à direita, alinhados à base. A navegação não tem painel nem título Dados do contrato; Expandir Tudo exibe todas as seções e ocupa o espaço restante da linha.

Botões de navegação usam azul del rey/texto azul e, no hover/foco, azul claro/texto preto. Expandir Tudo usa dourado e, no hover/foco, vermelho claro/texto preto. Espaçamento entre painéis foi reduzido em 60%, e existe uma margem de 8px abaixo do cabeçalho.

Escopo/Tipo de veículo, Cargo de credenciamento/Local do serviço e Instalações têm altura de uma linha e ocupam uma coluna. Dados do veículo também tem uma linha. Endereços do fornecedor/fundo têm duas linhas e altura compacta, inclusive em aditivos. IPTU fica compacto junto de Endereço do imóvel, que ocupa o restante da linha; ambos têm altura de 44px e o espaçamento padrão dos campos. As opções de IPTU possuem títulos explicativos; o campo atualiza sua dica conforme a seleção. A apresentação de dicas em opções do select nativo depende do navegador.

## IPTU

O rótulo de IPTU possui ícone informativo com o mesmo popover de Escopo: abre ao passar o mouse, focar ou tocar e exibe tabela comparando Locador(a) e Locatário, exemplos e orientação para conferir a cláusula de IPTU. A explicação distingue a obrigação contratual e cita a Lei nº 8.245/1991, art. 22, VIII. O conteúdo não altera a seleção nem produz efeitos financeiros.

Tipo de contrato usa um select de escolha única com as onze opções fixas de `ContractFields::CONTRACT_TYPES`, ordenadas pela quantidade de caracteres (incluindo espaços), da maior para a menor. Empates preservam a ordem da lista definida. Na edição, um tipo histórico fora da lista aparece somente para preservar o valor daquele contrato. As regras por tipo continuam independentes das opções. Não há alteração de schema ou dos dados históricos.

IPTU é apresentado como select compacto, com o rótulo `IPTU por conta do(a)>` e opções `Locador(a)` e `Locatário`. A seleção permanece opcional. O adaptador contextual valida as opções dentro da transação existente; valores livres novos são recusados antes da persistência/auditoria. Registros históricos com texto diferente continuam exibindo seu valor anterior e podem preservá-lo ou substituí-lo por uma das opções atuais. Campos omitidos mantêm o histórico, sem migration ou alteração de dados existentes. Não há efeito financeiro automático.

Os testes verificam as duas opções, gravação de cada responsabilidade, recusa de texto novo inválido, preservação de valor histórico omitido/reapresentado e troca explícita por opção válida.

Validação executada via Docker: Pint e `composer check` com `QUEUE_CONNECTION=database` passaram (329 testes, 2703 asserções, 1 ignorado); `composer check:architecture` passou (3 testes), `composer test:unit` passou (57 testes) e `npm run build` concluiu. Views compiladas foram limpas e `git diff --check` passou. Os testes utilizaram SQLite/storage/cache isolados; a suíte de concorrência MySQL não foi executada.

## Controles compactos com Fundo da sessão

O grupo superior não exibe moldura, título, seletor de Fundo nem Situação administrativa. Contém apenas Secretaria e Tipo de contrato, este último em um input de uma linha com indicação de obrigatoriedade. O campo Tipo de contrato foi retirado do grupo Objeto e dados especiais, evitando controles duplicados. O grupo mantém nome acessível, espaçamento interno reduzido e os botões de documentos alinhados à sua base. O helper JavaScript do antigo seletor de Fundo foi retirado; a dependência de Secretaria agora é renderizada pelo servidor e validada na transação.

Secretaria é habilitada conforme o Fundo autorizado da sessão, escolhido no login: somente `MUNICIPIO DE NIQUELANDIA`, após normalização. Não há seletor de Fundo no formulário. Na edição, a seleção também exige que o Fundo do contrato corresponda à sessão; contratos de outros Fundos preservam seu vínculo e histórico.

Em `POST /contracts`, o validador contextual recebe Request/SelectedFund por construtor na infraestrutura. Depois dos locks de ator/contrato, reautoriza o Fundo da sessão e bloqueia seu cadastro na mesma transação. O Fundo é preenchido no servidor; um ID HTTP divergente recebe 403. Sem Fundo válido na sessão, a criação falha por validação. Para os demais Fundos, uma Secretaria enviada à força é recusada; o cadastro normal mantém `department_id=null`. A atribuição final continua passando por `ContractAccess::canAssign`, numeração, persistência e auditoria existentes.

Na edição HTTP, trocar Secretaria para um valor não vazio exige que o Fundo atual da sessão seja o Município e corresponda ao Fundo do contrato. Omitir Fundo, Secretaria e Situação administrativa preserva os valores existentes. Situação de novos contratos segue o padrão `rascunho`. Consumidores CLI e demais contratos HTTP mantêm suas entradas explícitas e controles anteriores; a dependência de Request permanece na infraestrutura, sem sessão em Domain/Application.

Os testes verificam a ausência dos controles removidos, localização única do Tipo de contrato, criação sem `fund_id` HTTP, rejeição de Fundo divergente e Secretaria indevida, falha sem contexto de sessão e preservação de Fundo/situação/Secretaria em edições históricas. As fixtures HTTP foram ajustadas para fornecer a sessão que o login estabelece; testes de policy sem contexto de sessão continuam verificando o controle anterior.

Validação executada: Pint e `docker compose exec -T -e QUEUE_CONNECTION=database app composer check` passaram (327 testes, 2667 asserções, 1 ignorado). `ContractWritingTest` passou com 19 testes/244 asserções após a última alteração. `npm test` passou com 11 testes e `npm run build` concluiu, ambos via `docker compose run --rm --no-deps node`. `php artisan view:clear` e `git diff --check` também passaram. Foram utilizados SQLite/storage/cache temporários; não foram executados testes de concorrência MySQL nem migrations no banco instalado.

## Fornecedor e representante: dados exclusivos do cadastro

Fornecedor, RG do fornecedor, Documento profissional, PIS/PASEP, Endereço do fornecedor, Sócio/representante, CPF do sócio e RG do sócio são somente leitura no formulário de criação e edição. Inputs e textarea usam `readonly`, permitindo seleção e cópia, e têm o fundo da janela (`var(--canvas)`). CPF/CNPJ continua sendo o campo de consulta. Dados pessoais permanecem limitados ao perfil autorizado; campos já protegidos na edição continuam mascarados.

O adaptador contextual remove esses oito valores da entrada do cliente. Para novos contratos ou mudança de documento, resolve um fornecedor acessível no banco, sob lock dentro da mesma transação de ator/contrato, usa a projeção de `SupplierCatalog` e valida os valores do cadastro. Documento sem correspondência acessível ou ambíguo recebe erro de validação; não há preenchimento manual alternativo. Seleção explícita fornece `supplier_id`, tratado somente como identificador: deve corresponder ao documento e à visibilidade atual. O ID resolvido entra no patch validado; `SaveContract` não confia no ID HTTP diretamente nem perde a seleção explícita quando há cadastros duplicados.

Na edição com documento omitido ou inalterado, preserva snapshots e vínculo existentes, mesmo quando o catálogo foi alterado depois. Modificar somente campos de consulta pela requisição não altera o contrato. Quando um usuário comum muda o CNPJ, os campos pessoais antigos do fornecedor anterior são limpos, sem carregar dados restritos do novo fornecedor. Retornos de validação reconstroem os campos de consulta a partir do catálogo; valores enviados pelo cliente não são restaurados nesses campos.

O navegador limpa os dados de consulta e a seleção explícita ao trocar o documento, descarta respostas antigas e aguarda consultas pendentes antes de enviar. Documentos já selecionados ou existentes não disparam uma consulta ao simplesmente perder o foco. Os contratos HTTP da consulta e as projeções públicas existentes foram preservados.

Validação executada: Pint e `composer check` via Docker com `QUEUE_CONNECTION=database` passaram (323 testes, 2611 asserções, 1 ignorado); a nova regra unitária também passou em `composer test:unit` (57 testes, 129 asserções). `composer check:architecture` passou (3 testes), e a última verificação de `SupplierAutofillTest` passou (11 testes, 150 asserções). `npm test` passou com 10 testes; `npm run build` concluiu via `docker compose run --rm --no-deps node`. Views compiladas foram limpas e `git diff --check` passou. Os testes usam SQLite/storage/cache isolados; MySQL de concorrência e verificação interativa de navegador não foram executados. Nenhum dado do catálogo instalado foi modificado.

## Dados Processuais

O formulário de criação e edição apresenta Número do edital (`n_edital`) e Processo do edital (`proc_edital`) no painel Objeto e dados especiais, logo após Cargo Credenciamento - Saúde e Local do serviço. Data da licitação (`data_licit`) e Número da solicitação (`n_solicit`) permanecem ocultos. As colunas, importação, visualização histórica e compatibilidade HTTP são preservadas; uma edição com campos omitidos não apaga os valores anteriores.

Modalidade (`modalidade`) e Número da licitação (`n_licit`) são obrigatórios para novos contratos, com indicação no formulário e validação no adaptador contextual dentro da transação existente. Na edição, valores já preenchidos não podem ser esvaziados; omissão em patches preserva os valores existentes. Registros históricos incompletos continuam editáveis sem exigir preenchimento retroativo, seguindo a regra existente dos demais campos básicos.

Data do contrato (legado) corresponde à coluna de origem `Data_Cont_Verd`. Seu significado operacional ainda exige confirmação, conforme `01_ESPECIFICACAO_FUNCIONAL_E_TECNICA.md` e `02_DICIONARIO_DE_DADOS_SQL.md`; não deve ser interpretada automaticamente como data de assinatura nem substituir `data_contrato`. O campo foi mantido.

Os testes de integração verificam rejeição de campos obrigatórios omitidos, nulos, vazios ou com espaços, impedimento de limpeza de valores preenchidos, edição histórica incompleta e preservação dos quatro campos retirados do formulário. As fixtures de criação receberam Modalidade/Número da licitação para atender à nova regra sem enfraquecer asserções existentes.

Validação desta alteração: `docker compose exec -T app vendor/bin/pint --dirty` passou; `docker compose exec -T -e QUEUE_CONNECTION=database app composer check` passou com 318 testes, 2541 asserções e 1 teste ignorado; `composer check:architecture` passou com 3 testes e `composer test:unit` com 56 testes, ambos executados via `docker compose exec -T app`. `php artisan view:clear` e `git diff --check` também concluíram sem erros. Não houve alteração de assets nesta etapa; não foi necessário repetir o build. A suíte MySQL de concorrência não foi executada.

## Secretaria e seleção de texto

No formulário de Novo Contrato, Secretaria fica habilitada somente quando o nome normalizado do Fundo da sessão corresponde a `MUNICIPIO DE NIQUELANDIA`. A comparação utiliza o cadastro autorizado atual, sem ID fixo, tolerando acentos, caixa e espaços. Sem Fundo ou para os demais Fundos, o controle fica desabilitado. O estado inicial é renderizado pelo servidor, e a gravação aplica a validação contextual descrita acima.

Na edição, a Secretaria também depende da correspondência entre o Fundo do contrato e da sessão. A omissão do campo preserva as Secretarias históricas; os controles de autorização e escopos permanecem no servidor.

Inputs, textareas e valores de consulta (`.readonly-field`) definem `user-select: text`, incluindo a propriedade WebKit, para permitir seleção e cópia pelo navegador sem tornar os valores de consulta editáveis nem ampliar a exposição de campos pessoais.

Os testes cobrem a renderização inicial por Fundo, a preservação da edição, a troca de Fundo, a limpeza da Secretaria e o retorno à página. A validação utiliza SQLite em memória e storage/cache temporários. Não foram executadas migrations ou testes no banco instalado.

Comandos executados:

- `docker compose exec -T app composer check:architecture`: 3 testes passaram.
- `docker compose exec -T app composer test:unit`: 56 testes passaram.
- `docker compose exec -T app php vendor/bin/phpunit --do-not-cache-result --filter ContractWritingTest`: 14 testes passaram.
- `docker compose run --rm --no-deps node npm test`: 8 testes passaram.
- `docker compose run --rm --no-deps node npm run build`: build concluído e disponível na aplicação local.
- `docker compose exec -T app composer check`: Pint passou; PHPUnit apresentou uma falha em `ReportScaleTest`, pois `.env` local usa `QUEUE_CONNECTION=sync` e o teste espera uma exportação pendente.
- `docker compose exec -T -e QUEUE_CONNECTION=database app php vendor/bin/phpunit --do-not-cache-result --filter ReportScaleTest`: os 2 testes passaram com a fila esperada, sem modificar `.env`.
- `docker compose exec -T -e QUEUE_CONNECTION=database app composer check`: Pint e suíte completa passaram; 316 testes, 2502 asserções, 1 teste ignorado.
- `docker compose exec -T app php artisan view:clear`: views compiladas limpas.
- `git diff --check`: sem erros; `/up`: HTTP 200.

Não foi realizada validação interativa de seleção/área de transferência em navegador; Playwright não está disponível no Node local. A alteração de seleção usa as propriedades CSS nativas do navegador.

## Seleção de fornecedor sem perder o formulário (15/10/2026)

O controle Selecionar fornecedor cadastrado abre o catálogo original completo em uma janela ampla sobre o formulário atual, com a busca, tabela, detalhes e paginação existentes. O catálogo é carregado em um iframe da própria aplicação. Ao clicar em Usar no novo contrato, a janela lê apenas a projeção autorizada de fornecedor retornada pelo formulário de criação e fecha. Escolher um cadastro atualiza somente supplier_id, documento e os oito campos de fornecedor; processo, objeto, valores, datas e os demais dados digitados permanecem intactos. Fechar ou cancelar não altera o formulário. Consultas anteriores por documento são canceladas para não sobrescrever a seleção.

O catálogo utiliza as rotas HTML existentes, sem endpoint JSON adicional para listagem. A autorização, os escopos e as restrições de dados pessoais continuam aplicados no servidor. A gravação continua revalidando o fornecedor no servidor.

Validação: npm test (16 testes), npm run build, SupplierAutofillTest (12 testes, 163 asserções), composer check:architecture (3 testes), composer test:unit (57 testes) e Pint passaram, executados pelo Docker. Testes PHP usam SQLite e storage/cache isolados. Sem validação interativa em navegador nesta execução.

Verificação geral: docker compose exec -T -e QUEUE_CONNECTION=database app composer check passou (Pint; 344 testes, 2977 asserções, 1 ignorado). php artisan view:clear executado; git diff --check sem erros de whitespace.

Após restaurar o catálogo original: npm test passou (16 testes), npm run build passou, SupplierAutofillTest passou (12 testes, 165 asserções), Pint nos dois arquivos PHP envolvidos passou e git diff --check não apontou erros de whitespace. A suíte completa acima refere-se à implementação anterior; não foi repetida para esta troca de apresentação. Sem validação interativa do iframe em navegador.

O iframe do seletor agora se identifica como contracts-supplier-catalog. O layout oculta a barra lateral somente nesse contexto e deixa o catálogo ocupar toda a largura; a identificação permanece ao navegar entre busca, paginação e detalhes. Acesso normal ao catálogo mantém o menu. Validação: npm run build pelo Docker passou; php artisan view:clear executado; git diff --check sem erros de whitespace. Sem inspeção visual em navegador ou repetição da suíte PHP para esta alteração de apresentação.
# Valor dos produtos e serviços na barra de ações

O formulário exibe **Valor do contrato** à esquerda dos botões Excluir, Novo e Salvar. Por enquanto, o campo é somente leitura e mostra **A calcular**, sem enviar valor no POST. Ele será alimentado pela soma do futuro cadastro de produtos/serviços, cujos registros deverão ser armazenados em `tb_Produtos_Contratos`. Esta etapa não cria esse cadastro nem calcula totais; os valores financeiros existentes permanecem preservados.

Validação desta alteração: teste filtrado `test_editor_action_toolbar_respects_persistence_and_delete_permission` passou (21 asserções, SQLite em memória), Pint no teste passou, `npm run build` pelo Docker passou, `view:clear` executado e `git diff --check` passou. Sem inspeção interativa de navegador nesta etapa.
