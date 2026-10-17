# Cadastro completo de fornecedores

Salvar, Novo e Excluir de Novo fornecedor usam a classe compartilhada `action-toolbar`, também aplicada à barra de contratos: mesmas cores, dimensões, ícones, hover, foco e estado desabilitado. Validação visual estática: `docker compose run --rm --no-deps node npm run build`, `docker compose exec -T app php artisan view:clear` e `git diff --check` passaram. Sem inspeção interativa no navegador ou repetição de testes, pois a mudança está restrita às classes das views e CSS.

Tab percorre os campos de Novo fornecedor na ordem das linhas, da esquerda para a direita; Shift+Tab percorre a ordem inversa. Campos ocultos e desabilitados são ignorados, e o botão auxiliar de calendário não interrompe a sequência entre data de nascimento e sexo. Nas extremidades, a navegação nativa do diálogo continua disponível. Validação: `docker compose run --rm --no-deps node npm test` passou (29 testes); `docker compose run --rm --no-deps node npm run build` e `git diff --check` passaram. Sem inspeção interativa no navegador.

Ao sair do campo CPF/CNPJ de Novo fornecedor, o navegador verifica tamanho, sequências repetidas e os dois dígitos verificadores antes de consultar o cadastro. Ao perder o foco com documento inválido preenchido, uma caixa de mensagem da aplicação, com título “Gestão Sistem informa:” e botão OK, mostra o tipo e o número formatado: “CPF 633.741.021-16 é um CPF inválido. Verifique antes de efetuar o registro.” (ou CNPJ). O envio permanece bloqueado pela validação nativa até a correção. Campo vazio e restauração inicial não abrem a caixa. A inicialização também cobre formulários inseridos após o carregamento. O teste inclui o CPF inválido informado na reprodução. A validação do servidor permanece obrigatória.

Validação desta mudança: `docker compose run --rm --no-deps node npm test` passou (28 testes), `docker compose run --rm --no-deps node npm run build` passou e `git diff --check` passou. Sem inspeção visual em navegador ou execução de testes PHP; a alteração está restrita ao JavaScript.

O modal Novo fornecedor, disponível para administradores no catálogo de fornecedores e nos cadastros administrativos, oferece 24 campos de preenchimento. Nome e CPF/CNPJ continuam obrigatórios; os 22 campos adicionais são opcionais e organizados em quatro grupos: dados cadastrais, endereço/contatos, sócio/representante e pessoa de contato. Endereço do sócio ou representante (End_Socio) foi retirado do formulário e da projeção de gravação; os dados históricos permanecem preservados.

Telefones e WhatsApp usam máscara com DDD: (00) 0000-0000 ou (00) 00000-0000. PIS/PASEP usa 000.00000.00-0. As máscaras são aplicadas ao digitar, restaurar o formulário e consultar cadastro existente. Novos dados são validados no servidor e armazenados como strings de dígitos, sem conversão numérica; valores vazios continuam opcionais. Não se aplica regra nova de dígito verificador do PIS. Dados legados não são regravados.

Todos os campos de data que usam o controle brasileiro compartilhado restringem a digitação a números e separadores, limitam a entrada a dd/mm/aaaa e limpam letras inseridas por colagem. A validação verifica datas civis reais (incluindo anos bissextos) e os limites min/max existentes; o calendário permanece disponível e o transporte continua ISO Y-m-d. Datas incompletas ou impossíveis produzem erro de validação. As validações do backend permanecem obrigatórias, inclusive nos formulários com novalidate.

Validação das máscaras: npm test passou (24 testes), build passou, PHPUnit filtrado por SupplierRegistrationTest, SupplierAssistanceTest, SupplierAutofillTest e BrazilianLocalizationTest passou (29 testes, 405 asserções). Pint dos dois arquivos PHP passou; view:clear executado e git diff --check sem erros de whitespace. Sem inspeção visual interativa em navegador nesta execução.

Verificação geral das máscaras: composer check:architecture passou (3 testes), composer test:unit passou (57 testes) e composer check passou com QUEUE_CONNECTION=database apenas no comando (Pint; 355 testes, 3175 asserções, 1 ignorado). Execução pelo Docker com SQLite e storage/cache de testes isolados. Build e testes JavaScript repetidos após permitir também a colagem de datas ISO com hífens: passaram.

Validação da retirada de End_Socio: SupplierRegistrationTest passou (5 testes, 108 asserções), incluindo ausência no formulário, descarte de envio direto e preservação histórica. Pint dos dois arquivos PHP passou; view:clear executado e git diff --check sem erros de whitespace. Suíte completa não repetida nesta alteração.

O cadastro usa o mesmo limite de largura do catálogo (1600 px, respeitando a tela). Campos de linha única compartilham altura e espaçamento vertical com o formulário de contrato; rótulos e títulos de seção seguem o mesmo padrão. Os botões existentes de salvar, novo, editar e excluir recebem ícones SVG do componente `nav-icon`, mantendo suas ações e permissões.

O cabeçalho e a descrição permanecem visíveis no topo da janela Novo fornecedor. Apenas o formulário tem rolagem, mantendo o título e o botão de fechar acessíveis. Validação desta mudança CSS: npm run build passou; view:clear executado; git diff --check sem erros de whitespace. Sem novos testes PHP ou inspeção visual em navegador.

O cabeçalho do cadastro contém Salvar, Novo e Excluir com ícones. Salvar e Novo se associam ao formulário pelo atributo form: Salvar envia o POST habitual e Novo limpa os campos visíveis e as mensagens, cancela consultas pendentes e preserva CSRF/kind/return_to. Excluir aparece desabilitado na janela de novo cadastro, pois não há registro salvo selecionado para exclusão. O rodapé de ações foi removido somente do modal de fornecedor; fechar continua disponível no cabeçalho. Validação: 21 testes JavaScript e build passaram; SupplierRegistrationTest e SupplierAssistanceTest passaram (12 testes, 198 asserções), incluindo vínculo dos botões com o formulário e ausência de botões inferiores. Pint passou, view:clear executado, git diff --check sem erros de whitespace. Suíte completa não repetida e sem inspeção visual em navegador nesta alteração.

Ao sair de CPF/CNPJ no cadastro de fornecedor, o formulário consulta `POST /suppliers/registration-lookup` com CSRF e documento no corpo. Somente administradores atuais podem consultar os dados completos de cadastro. A resposta é privada (`private, no-store`) e contém uma projeção explícita de nome, documento e campos ativos de `SupplierFields`; não retorna payload completo, dados descontinuados ou controles internos. A consulta de fornecedor para contratos continua com a projeção restrita anterior.

Um cadastro encontrado preenche os campos sem mostrar mensagem informativa de duplicidade. Salvar fornecedor permanece habilitado, inclusive durante a consulta ou em caso de falha; a consulta não intercepta o envio do formulário. Documento inválido e múltiplos cadastros geram avisos. Documentos restaurados após validação também são consultados. Ao mudar o documento, consultas antigas são canceladas e seus resultados descartados; valores automáticos são retirados, recuperando os valores manuais anteriores. O servidor recusa duplicidade por document_key dentro da transação, depois do lock e reautorização do administrador, sem alterar o cadastro existente nem auditar uma criação recusada. A leitura do cadastro existente usa lock e a transação permite três tentativas em deadlock.

Validação após retirar o bloqueio visual de Salvar: npm test passou (20 testes), npm run build passou, SupplierRegistrationTest passou (8 testes, 152 asserções, incluindo criação nova e rejeição de duplicidade no servidor) e git diff --check sem erros de whitespace. Suíte completa não repetida; sem inspeção visual em navegador.

Validação desta consulta: SupplierRegistrationTest, SupplierAssistanceTest e SupplierAutofillTest passaram (23 testes, 357 asserções), incluindo autorização atual, projeção dos campos, documento inválido/inexistente/ambíguo e bloqueio de criação direta duplicada. npm test passou (20 testes) e npm run build passou. Sem inspeção visual interativa em navegador ou ensaio concorrente MySQL.

Verificação geral após a consulta: composer check:architecture passou (3 testes), composer test:unit passou (57 testes), e composer check com QUEUE_CONNECTION=database apenas no comando passou (Pint; 353 testes, 3156 asserções, 1 ignorado). Todos pelo Docker, com banco SQLite e storage/cache de teste isolados. view:clear executado; git diff --check sem erros de whitespace.

Enquanto Novo fornecedor estiver aberto dentro do catálogo, Fechar catálogo fica desabilitado, inclusive quando o cadastro reabre após erros de validação. Fechar ou cancelar o cadastro reativa o botão. Escape na janela externa também respeita esse bloqueio. O estado acompanha o atributo open do diálogo interno e não altera o formulário de contrato. Validação: npm test passou (17 testes, incluindo bloqueio/reativação, Escape e reabertura), build final passou e git diff --check sem erros de whitespace. Sem alterações PHP nem inspeção visual em navegador.

Pessoa de contato apresenta nome, celular, WhatsApp e e-mail na mesma linha em telas maiores que 900 px, nas proporções 2:1:1:1,6. Celular e WhatsApp são mais estreitos; e-mail mantém mais espaço. Até 900 px são duas colunas e até 600 px uma coluna. Validação desta alteração visual: npm run build passou, view:clear executado e git diff --check sem erros de whitespace. Sem novos testes PHP ou inspeção visual em navegador.

Sexo é um seletor opcional com Masculino e Feminino. O servidor rejeita outros valores em novos cadastros; registros legados permanecem preservados. Validação desta alteração: SupplierRegistrationTest passou (4 testes, 106 asserções), incluindo persistência de Feminino e rejeição de opção inválida; Pint nos três arquivos PHP passou, view:clear executado e git diff --check sem erros de whitespace. Sem repetição da suíte completa ou inspeção visual em navegador.

O ID é gerado pela tabela nativa `suppliers`; não se atribui um ID legado a um cadastro novo. `PF_PJ` é derivado do CPF/CNPJ validado (`PF` ou `PJ`) e `Data_Atualizacao` vem do relógio do servidor, no formato `Y-m-d`. Nome e documento são gravados nas colunas nativas e nas chaves `Nome_Fornecedor` e `CPF_CNPJ` do `raw_payload`. Os demais dados usam as mesmas chaves do legado, sem escrever diretamente na tabela antiga nem alterar registros importados. `Data_Nasc` usa `Y-m-d`.

`SupplierFields::GROUPS` define os campos permitidos e os rótulos. O controller valida os detalhes após bloquear e reautorizar o administrador, dentro da transação de criação/auditoria. CPF do representante, e-mails, data e limites de texto são validados no servidor. ID legado, payload bruto, tipo PF/PJ e data de atualização enviados pelo cliente não são usados.

A seção Informações tributárias e complementares foi descontinuada. Os 11 campos VL_Pensao, ISSQN, Dependentes, INSS, Situacao, Tributario, Honorario, Empregados, Origem, Porte e NaturezaJuridica não aparecem no cadastro nem são aceitos na projeção de gravação, mesmo se enviados diretamente. Dados históricos existentes continuam preservados no payload legado, sem uso funcional e sem exclusão de registros.

Validação da descontinuação: PHPUnit filtrado por SupplierRegistrationTest, SupplierAssistanceTest e SupplierAutofillTest passou (20 testes, 313 asserções), incluindo ausência dos campos no formulário, descarte de envio direto e preservação do payload histórico. Pint nos três arquivos PHP e npm run build passaram; view:clear executado; git diff --check sem erros de whitespace. A busca em app/resources não encontrou referências aos campos tributários descontinuados. Suíte completa não repetida e sem inspeção visual em navegador nesta alteração.

Erros reabrem o modal, preservando os valores e mostrando as mensagens nos campos. Uma falha de auditoria reverte a criação inteira. Nome/documento e os dados complementares usados pelo `SupplierCatalog::defaults` passam a preencher os contratos; sua projeção por perfil permanece restrita. Contatos e dados tributários não são acrescentados às respostas da busca de contratos.

## Validação

Comandos executados no Docker com SQLite e storage/cache de teste isolados:

- PHPUnit filtrado por `SupplierRegistrationTest|SupplierAssistanceTest|SupplierAutofillTest|AdministrativeMutationSecurityTest`: 22 testes, 321 asserções, passaram. Cobrem os 34 campos complementares, dados automáticos protegidos, integração com lookup, restauração após validação, cadastro mínimo/PF, permissões e rollback de auditoria.
- Pint nos três arquivos PHP alterados: passou.
- `composer check:architecture`: 3 testes passaram.
- `composer test:unit`: 57 testes passaram.
- `npm test`: 16 testes passaram.
- `npm run build`: passou.
- `php artisan view:clear`: executado; `git diff --check`: sem erros de whitespace.

A primeira execução de `composer check` encontrou duas asserções antigas em `ContractWritingTest` e `FundSwitchingTest` que ainda exigiam o botão Alterar Fundo, removido em uma solicitação anterior. A causa foi confirmada nos testes e no layout compartilhado. As duas asserções passaram a exigir a ausência do controle, mantendo as verificações de ordem do cabeçalho e de autorização do endpoint.

A execução final de `docker compose exec -T -e QUEUE_CONNECTION=database app composer check` passou: Pint e PHPUnit, 349 testes, 3106 asserções, 1 teste ignorado. A variável de fila foi aplicada apenas ao comando, sem alterar o ambiente da aplicação.

Sem migrações, importação ou alteração direta em banco real. Sem inspeção visual interativa em navegador nesta execução.

## Ajuste visual posterior

O cadastro agora usa largura máxima de 1600 px, com nome/documento lado a lado e quebra para uma coluna em telas pequenas. Campos de linha única e botão de calendário compartilham as regras do contrato, sem marcar o formulário como editor de contrato. Foram incluídos ícones de disquete, adição, lápis e lixeira nos botões existentes dessas ações nas telas do sistema; não foram criadas novas operações de edição/exclusão de fornecedor.

Validação desta apresentação: build final passou; SupplierRegistrationTest e SupplierAssistanceTest passaram (7 testes, 144 asserções); view:clear executado e git diff --check sem erros de whitespace. Composer check executou 349 testes, com uma falha na asserção antiga que exigia texto imediatamente após a abertura do botão desabilitado. A verificação foi atualizada para inspecionar no DOM o rótulo, o atributo disabled e a presença do SVG; a repetição desse teste passou (1 teste, 17 asserções), assim como Pint do arquivo. A suíte completa não foi repetida após esse ajuste da asserção. Sem inspeção visual em navegador.

A mensagem informativa de fornecedor já cadastrado foi removida a pedido do usuário. Build executado com sucesso e git diff --check sem erros de whitespace; sem novos testes para esta alteração de texto.

O título visual Dados cadastrais foi retirado, mantendo todos os campos e um rótulo acessível para o grupo. O primeiro grupo não tem mais separador e usa margem superior de 10 px para aproximar os campos de Nome/CPF/CNPJ. Validação: npm run build passou, view:clear executado e git diff --check sem erros de whitespace. Sem novos testes para esta alteração de apresentação e sem inspeção visual em navegador.

A orientação de pontuação do CPF/CNPJ fica junto ao rótulo, na mesma linha quando houver espaço. A área de status vazia fica oculta, retirando o espaço inferior e aproximando os demais campos da linha de Nome. Validação: npm run build passou, view:clear executado e git diff --check sem erros de whitespace; sem novos testes ou inspeção visual em navegador para este ajuste de apresentação.

Nome fantasia ocupa duas colunas da mesma grade de três colunas usada por Nome, garantindo larguras iguais e alinhamento. Em telas de até 600 px, ambos ocupam a largura inteira. Validação: build final passou, view:clear executado e git diff --check sem erros de whitespace; sem novos testes ou inspeção visual em navegador para este ajuste de apresentação.

O modal Novo fornecedor passa a ocupar toda a largura e altura disponíveis da janela que o contém (incluindo o iframe do catálogo), sem margem externa, limite de 1600 px ou bordas arredondadas. O cabeçalho permanece fixo e o formulário preenche o espaço restante com rolagem própria. Validação: build final passou e git diff --check sem erros de whitespace. Sem novos testes ou inspeção visual em navegador para esta mudança CSS.

Data de nascimento, Sexo, PIS/PASEP e Documento profissional ocupam quatro colunas na mesma linha dos dados básicos. Nome fantasia e RG mantêm a linha anterior; Nome fantasia continua alinhado e com largura igual a Nome. Até 600 px os campos ficam em uma coluna. Validação: build final passou e git diff --check sem erros de whitespace; sem novos testes ou inspeção visual em navegador para este ajuste CSS.

Na seção Endereço e contatos, Endereço completo usa uma única linha de altura e ocupa toda a largura da grade. Telefone fixo, celular, WhatsApp e e-mail ficam juntos na linha seguinte; até 900 px passam para duas colunas e até 600 px para uma. O endereço mantém o limite de 2000 caracteres e seu conteúdo armazenado. Validação: npm run build passou, view:clear executado e git diff --check sem erros de whitespace; sem novos testes ou inspeção visual em navegador para este ajuste de apresentação.

Sócio ou representante segue o mesmo arranjo: nome com largura total na primeira linha; CPF, RG, celular, telefone fixo, WhatsApp e e-mail juntos na segunda, com mais espaço para e-mail. Até 1200 px são três colunas, até 900 px duas e até 600 px uma. Validação: npm run build passou, view:clear executado e git diff --check sem erros de whitespace; sem novos testes ou inspeção visual em navegador para este ajuste de apresentação.

A expressão ou representante foi retirada do título e dos rótulos da seção, agora intitulada Sócio. A condição de layout foi atualizada para preservar a disposição dos campos. Validação: npm run build passou, view:clear executado e git diff --check sem erros de whitespace; sem novos testes para esta alteração de rótulos.

O cadastro utiliza espaçamento vertical compacto: padding externo de 12 px, descrição com margem de 8 px, grupos com margem/padding reduzidos e grades com intervalo vertical de 6 px. Em telas largas com altura de até 800 px, os intervalos passam a 4 px e os controles têm altura compacta. Cabeçalho e campos permanecem acessíveis; rolagem automática continua como alternativa para telas estreitas, zoom e mensagens de erro. Validação: build final passou e git diff --check sem erros de whitespace. Sem inspeção visual em navegador; não foi confirmado encaixe sem rolagem em uma resolução específica.

A descrição introdutória do modal Novo fornecedor foi removida, incluindo seu parágrafo e a referência aria-describedby. O nome acessível do diálogo continua associado ao título. A remoção libera espaço vertical sem alterar os campos ou dados automáticos. Validação: view:clear executado e git diff --check sem erros de whitespace. O build do ajuste CSS de compactação anterior passou. Sem novos testes para esta alteração de texto.

A separação entre as seções foi aumentada em 6 px (margem superior de 16 px; 14 px no modo de altura compacta), mantendo os espaços internos dos campos e a proximidade do primeiro grupo ao Nome. Validação: build passou e git diff --check sem erros de whitespace; sem novos testes para este ajuste visual.

Correção do bloqueio imediato de datas: a máscara agora rejeita mês 13, dia 00 e combinações impossíveis já no beforeinput; em colagem ou input de fallback, restaura o último valor permitido. Anos incompletos permitem 29/02 até ser possível validar o ano completo; anos não bissextos são rejeitados quando completos. Datas recebidas por preenchimento automático passam pelo mesmo evento de validação. Validação: npm test passou (25 testes, incluindo digitação do mês 13, colagem de 12/13/2026, 31/04 e fevereiro bissexto), build final passou e git diff --check sem erros de whitespace. Sem alterações no backend nem repetição da suíte PHP; sem inspeção visual em navegador.

Verificação antes dos commits e push: composer check:architecture (3 testes), composer test:unit (57 testes), composer check com QUEUE_CONNECTION=database apenas no comando (Pint; 355 testes, 3175 asserções, 1 ignorado), npm test (25 testes), npm run build e git diff --check passaram. Comandos executados no Docker; testes PHP em SQLite e storage/cache isolados. Sem ensaio MySQL concorrente ou inspeção visual em navegador. O Git estava sincronizado com origin/main antes da criação dos commits.

### Campos conforme CPF/CNPJ — 17/10/2026

Ao reconhecer CPF válido, o formulário oculta Nome fantasia e o grupo Sócio. Com CNPJ válido, oculta Data de nascimento, Sexo, PIS/PASEP e Documento profissional. Documento vazio, incompleto ou inválido apresenta todos os campos. A regra aplica-se à digitação, saída do campo e valores restaurados; Novo limpa o formulário e restaura todos os campos. A ocultação preserva os valores digitados e não modifica o contrato HTTP ou a validação do servidor. A navegação por Tab já ignora campos invisíveis.

Validação: npm test passou (46 testes), incluindo alternância CPF/CNPJ, restauração ao apagar/Novo e preservação de valores; npm run build e git diff --check passaram. Não houve teste em navegador nem alteração no banco ou nas regras de persistência.

### Avisos de validação em janela própria — 17/10/2026

Após uma tentativa de cadastro com erros, uma janela nativa acessível exibe a orientação Confira os campos indicados para concluir o cadastro e as mensagens de validação, incluindo fornecedor já cadastrado para o CPF/CNPJ. O botão OK fecha o aviso; o formulário continua aberto com os dados preenchidos e as indicações nos campos. Mantidos o bloqueio de duplicidade, error bag e redirecionamento existentes.

Validação: PHPUnit SupplierRegistrationTest passou (10 testes, 177 asserções, SQLite em memória e storage temporário), incluindo renderização do aviso de duplicidade com abertura automática e botão OK; Pint, php artisan view:cache e git diff --check passaram. Sem alteração de banco, JavaScript ou assets; não houve teste em navegador nesta revisão.

### Campos ocultos dispensados no envio — 17/10/2026

Os controles ocultos conforme CPF/CNPJ ficam desabilitados: não participam do envio nem da validação nativa do navegador. O grupo Sócio usa o disabled do fieldset. Ao trocar o tipo, apagar o documento ou clicar Novo, os controles voltam a ser habilitados. Valores digitados permanecem disponíveis ao alternar o tipo, até Novo limpar o formulário. O servidor já aceita esses campos omitidos como opcionais; teste de integração confirma cadastro de PF e PJ com os respectivos campos ocultos ausentes e auditoria persistida.

Validação: SupplierRegistrationTest passou (11 testes, 193 asserções, SQLite em memória e storage temporário), npm test passou (46 testes), npm run build, Pint e git diff --check passaram. Não houve alteração de regras PHP ou banco, nem teste em navegador nesta revisão.

### Endereço obrigatório e aviso exclusivo — 17/10/2026

Endereço completo é obrigatório para PF e PJ no HTML e na validação inicial do servidor, junto de nome e documento. Campos específicos ocultos continuam opcionais. O aviso Confira os campos indicados para concluir o cadastro aparece em janela própria somente quando falta um obrigatório. O navegador marca os campos vazios e evita avisos repetidos na mesma tentativa; preenchê-los remove a indicação. Na resposta do servidor, a janela de obrigatórios é separada da janela dos demais erros. Duplicidade e formato inválido apresentam apenas seus próprios detalhes, sem a orientação de campo obrigatório.

Testes específicos: SupplierRegistrationTest e SupplierAssistanceTest passaram (15 testes, 285 asserções), com cobertura de endereço nulo/vazio/espaços para CPF/CNPJ, ausência de gravação/auditoria em falhas, atributo required e separação do aviso de duplicidade. npm test passou (47 testes), npm run build, Pint dos arquivos PHP alterados e php artisan view:cache passaram. Testes PHP usam SQLite em memória e storage temporário; não houve alteração de dados existentes ou teste em navegador nesta revisão.

Validação ampliada: composer check passou (Pint em 225 arquivos; 396 testes, 395 aprovados e 1 ignorado por exigir MySQL isolado; 3.727 asserções). git diff --check passou.

### Edição do fornecedor pelo resumo — 17/10/2026

Administradores veem Editar fornecedor ao lado de Usar no novo contrato. O botão abre o mesmo componente de cadastro em modo de edição, preenchido somente com nome, documento e campos ativos de SupplierFields. Dados pessoais completos não são projetados para usuários comuns. A consulta automática por documento fica desligada durante a edição para não substituir o fornecedor selecionado ou alterações digitadas. As máscaras e regras de campos ocultos continuam aplicadas.

PUT /suppliers/{supplier} é uma rota nova, com autenticação/CSRF existentes. UpdateSupplier revalida e bloqueia o ator antes do fornecedor, exige administrador atual, valida revisão, documento, endereço e detalhes, recusa documento usado por outro fornecedor e persiste atualização e ActivityEvent supplier_updated na mesma transação. A revisão HMAC detecta alterações desde a abertura, sem exigir migration; conflitos mantêm a revisão antiga no formulário até recarregar a página. Metadados da auditoria identificam fornecedor e revisões anterior/posterior, sem copiar dados pessoais para a resposta. Falha na auditoria reverte a gravação.

SupplierRegistrationInput centraliza a validação dos campos ativos, compartilhada com o cadastro existente. Campos omitidos são preservados na edição; null explícito limpa o campo. O payload legado é preservado e o cliente não pode alterar campos internos. Atualizar fornecedor não reescreve snapshots nem versões dos contratos associados. Salvar retorna ao resumo atualizado. Novo, no modo de edição, abre o cadastro vazio na lista, evitando limpar os dados e gravá-los acidentalmente sobre o fornecedor existente.

Validação específica: SupplierEditingTest passou (6 testes, 65 asserções), cobrindo preenchimento/projeção, gravação, preservação de legado e snapshots, campos omitidos/null, duplicidade, validação, revisão desatualizada, permissões e rollback por falha de auditoria. Testes anteriores de cadastro/consulta passaram (15 testes, 285 asserções); arquitetura passou (3 testes, 4 asserções); npm test passou (48 testes), npm run build e php artisan view:cache passaram. Edge headless com fixture Blade sem banco confirmou abertura/preenchimento, alternância CPF/CNPJ, envio PUT com revisão, preservação da edição sem consulta automática e fechamento da janela. Testes PHP usam SQLite em memória e storage temporário; nenhuma migration ou escrita no banco instalado foi executada.

Verificação final: composer check passou (Pint em 228 arquivos; 402 testes, 401 aprovados e 1 ignorado por exigir MySQL isolado; 3.792 asserções). composer check:static passou sem erros. git diff --check passou.
