# Cadastro de Dotação

## Preenchimento pela Ficha — 22/10/2026

Ao preencher Ficha e sair do campo, a consulta exata existente localiza o registro ativo autorizado. Agora o formulário de edição retornado pelo servidor substitui o formulário no próprio painel, preenchendo todos os campos, Fundo e Secretaria, e preservando a revisão, as ações de salvar/excluir e a dotação histórica. Este comportamento substitui a navegação de página descrita no histórico abaixo. A leitura não dispara a notificação de gravação nem fecha o painel de produtos. O foco segue para Órgão.

Se havia um Fundo selecionado e o registro encontrado pertence a outro Fundo, o formulário preenchido exibe o mesmo aviso do Cadastro de produtos: “A ficha pertence a outro Fundo ou Secretaria. Confira os dados exibidos.” O aviso é informativo e compara o Fundo selecionado antes do carregamento com o Fundo do registro autorizado; não altera permissões nem grava dados. OK fecha o aviso e leva o foco a Órgão, sem repetir a consulta. Fundo igual ou não selecionado não dispara o aviso. O teste JavaScript do carregamento cobre esses três cenários e o fechamento por OK.

Ficha não encontrada mantém os valores para novo cadastro. Duplicidades e falhas apresentam aviso; respostas antigas não substituem o painel após o usuário trocar a ficha. Os endpoints e seus contratos HTTP permanecem iguais: a consulta retorna estado/URL e o carregamento do formulário reautoriza o acesso. Nenhum registro é gravado pela consulta.

Validação executada: `node --test tests/JavaScript/*.test.mjs` (125 testes passaram); `docker compose exec -T app php vendor/bin/phpunit --filter=BudgetRegistrationTest --do-not-cache-result` (10 testes, 101 asserções, SQLite e storage isolados); `docker compose run --rm --no-deps node npm run build` e `git diff --check` passaram. Sem conferência visual autenticada no navegador.

O botão **Cadastro de Dotação**, na listagem de Dotação ou no aviso de ficha não encontrada em Cadastro de produtos, abre o formulário. No segundo caminho, uma nova aba preserva os painéis de contrato/produtos e sugere a ficha, Fundo e Secretaria. Essas sugestões não autorizam operações: todas as permissões e destinos são revalidados no servidor.

O formulário segue o modelo `Reference/Cad_Dotacao.jpg`: Ficha, Órgão, Gestão, Unidade, Função, Sub-Função, Programa, Projeto, Código, Fonte, Descrição e Dotação Orçamentária. Fundo/Secretaria definem o escopo do registro. A identificação interna não aparece como campo. A lista possui paginação e links nas fichas para abrir um cadastro existente.

O campo de busca separado e sua lupa foram removidos. Ao perder o foco de Ficha, uma consulta exata em `/catalogs/budgets/lookup` verifica se existe um único registro ativo e visível. Encontrando, abre o formulário de edição pela URL autorizada retornada; não encontrando, mantém o preenchimento atual para novo cadastro. Ficha já aberta não provoca navegação repetida. Duplicidades recebem aviso sem escolher arbitrariamente um registro. A consulta é privada, limitada por frequência e retorna somente estado/URL; a página de destino reautoriza o acesso. Respostas antigas são descartadas e Salvar aguarda o término da consulta.

Validação desse fluxo: `docker compose exec -T app php artisan test --filter=BudgetRegistrationTest --compact` passou (7 testes, 75 asserções); `node --test tests/JavaScript/budget-registration.test.mjs` passou (3 testes, incluindo consulta no blur, ficha nova, bloqueio de envio pendente e descarte de resposta antiga); Pint dos PHP alterados, build Docker e `git diff --check` passaram. Sem validação visual autenticada no navegador.

Secretaria fica habilitada somente para `MUNICIPIO DE NIQUELÂNDIA`, com comparação normalizada. Trocar para outro Fundo limpa e bloqueia a seleção na interface; o servidor recusa Secretaria não nula para outros Fundos. Em registros históricos de outros Fundos, omitir o campo preserva o texto salvo quando o Fundo não mudou.

O ícone de filtro ao lado de Secretaria restringe a lista ao Fundo municipal e à Secretaria selecionados; clicar novamente remove esse filtro. A atualização usa uma projeção HTML autorizada da lista, sem navegar ou perder valores ainda não salvos no formulário. `filter_fund_id` e `filter_department_id` são validados e reautorizados no servidor, mantendo os escopos atuais e ignorando excluídos. As abreviações legadas `SEC MUNICIPAL` e `SEC. MUNICIPAL` são aceitas para os nomes iniciados em `SECRETARIA MUNICIPAL`. O parâmetro HTTP de busca textual anterior permanece compatível, mas não é apresentado na interface.

Validação desse ajuste: `docker compose exec -T app php artisan test --filter=BudgetRegistrationTest --compact` passou (6 testes, 59 asserções); os dois testes de `tests/JavaScript/budget-registration.test.mjs`, Architecture (3 testes), o teste de acesso ao painel (1 teste, 11 asserções), Pint dos três PHP alterados, build Docker e `git diff --check` passaram. Sem conferência visual autenticada em navegador.

**Salvar** cria ou atualiza a linha em `tb_Dotacao`. **Novo** abre o formulário limpo. **Excluir** exige um registro aberto e uma confirmação em caixa própria; preenche `deleted_at`, preservando os dados e o histórico. A ficha excluída não pode ser reutilizada por este cadastro. Consultas do catálogo, lista e emissão de contratos ignoram linhas excluídas.

## Composição

`Gestão.Unidade.Função.Sub-Função.Programa.Projeto.Código(Fonte)Ficha`

Exemplo: `02.0217.04.122.7008.8040.319094(100)20250029`. Órgão é salvo separadamente e não entra na composição nova. A imagem possui Órgão/Gestão iguais; a inspeção somente de leitura do banco encontrou também Órgão `11` e Gestão `08` com dotação iniciando em `08`. Foi adotado Gestão como primeiro segmento para os novos códigos, conforme essa evidência; existem outros formatos históricos. Os 1.733 registros locais foram preservados. Abrir ou editar apenas descrição/órgão não recompõe o texto histórico: apenas mudanças dos componentes da concatenação provocam recomposição.

Os componentes são textos numéricos; zeros à esquerda são preservados. Não há preenchimento automático com zeros nem conversão para número. O campo Dotação Orçamentária é somente leitura, atualizado ao digitar os componentes. `Domain/Contracts/BudgetCode` recalcula o mesmo texto no servidor quando os componentes mudam, ignorando uma eventual `Dotacao` enviada pelo cliente.

## Segurança e persistência

`Infrastructure/Budgets/BudgetRegistration` revalida o ator sob lock e exige o catálogo `budget`. Aplica escopo na leitura do registro e no destino, valida Fundo/Secretaria existentes, controla revisão HMAC do registro inteiro e impede fichas duplicadas. Não retorna dados atuais em um conflito. Cada criação/alteração/exclusão grava `ActivityEvent` inseparável da transação. Falha na auditoria reverte a mutação. O lock de cache `contracts-budget-registration` serializa o cadastro novo e o endpoint contextual de compatibilidade preexistente.

O formulário usa POST/PUT/DELETE com CSRF; o GET nunca grava. Alterações não modificam snapshots, contratos, valores ou saldos. Depois de salvar, uma notificação entre abas provoca nova consulta autorizada da ficha corrente no Cadastro de produtos, sem transferir dados do registro pela notificação. O aviso antigo de ficha ausente é fechado se a nova consulta encontrar o registro.

## Instalação e validação

A migration aditiva `2026_10_21_000001_add_soft_delete_to_budget_catalog.php` acrescenta somente `deleted_at` anulável. Foi aplicada isoladamente no ambiente local com `docker compose exec -T app php artisan migrate --path=database/migrations/2026_10_21_000001_add_soft_delete_to_budget_catalog.php --force`, sem remover dados ou aplicar outras migrations pendentes.

Testes de integração usam SQLite em memória e storage/cache isolados; não criam ou excluem dotações no banco instalado. Cobrem composição no servidor, preço/código forjado, zeros, criação/alteração, revisão antiga, confirmação/exclusão lógica, duplicidade, permissão, escopo e rollback de auditoria. Testes PHP puros e JavaScript verificam a composição do modelo; JavaScript também verifica atualização da ficha entre abas. A renderização autenticada em navegador ainda não foi conferida visualmente.

Comandos executados em 21/10/2026:

- `docker compose exec -T app composer check`: Pint em 330 arquivos e PHPUnit com 688 testes, 6.858 asserções e 1 ignorado; passou. A execução começou antes do teste adicional de preservação do formato histórico e do ajuste do primeiro segmento para Gestão.
- `docker compose exec -T app php artisan test --filter='BudgetCodeTest|BudgetRegistrationTest' --compact`: validação posterior dos ajustes, 6 testes e 45 asserções; passou.
- `docker compose exec -T app composer check:architecture`: 3 testes, 4 asserções; passou.
- `docker compose exec -T app composer test:unit`: 78 testes, 171 asserções; passou antes do acréscimo da asserção específica de Gestão.
- `npm.cmd test`: 113 testes JavaScript; passou. Os testes da composição/integração foram repetidos após os ajustes, sem falhas.
- Pint dos PHP alterados, `docker compose run --rm --no-deps node npm run build`, limpeza de views e `git diff --check` passaram.

Conferência somente de leitura confirmou 1.733 registros ativos após a migration. Não houve recálculo em massa, cadastro de demonstração ou exclusão no banco instalado.

## Acesso direto pelo menu

Cadastros → Dotação abre diretamente `/catalogs/budgets/create`, com formulário e lista na mesma tela. A tela intermediária foi retirada do fluxo, assim como o link de retorno a ela. A URL antiga `/catalogs/list/budget` redireciona ao cadastro após verificar a permissão de acesso.

Validação: `docker compose exec -T app php vendor/bin/phpunit --filter=BudgetRegistrationTest --do-not-cache-result` passou (9 testes, 92 asserções, SQLite em memória e storage isolado). Pint dos dois PHP alterados e `git diff --check` passaram; cache de views limpo com `php artisan view:clear`. Sem conferência visual autenticada no navegador.

## Cadastro sobre o painel de produtos — 21/10/2026

O botão Cadastrar nova dotação, na mensagem de ficha não encontrada, abre um dialog com iframe da rota autenticada existente. A navegação permanece no painel e preserva o formulário de produtos. Requisições com Sec-Fetch-Dest igual a iframe usam um layout compacto sem menu; esse cabeçalho altera apenas a apresentação, mantendo autorização, escopos, CSRF, validação, auditoria e transações existentes. Ao carregar a confirmação de gravação, o painel fecha e o cadastro de produtos refaz a consulta autorizada da ficha e atualiza sua lista. Fechar sem salvar preserva os dados de produtos e retorna o foco para Ficha. A seta decorativa aplicada pelo CSS no campo Ficha foi removida. Este fluxo substitui a abertura em nova guia descrita anteriormente.

Validação: 15 testes JavaScript passaram (incluindo abertura, fechamento sem salvar e atualização após salvar); BudgetRegistrationTest e ContractProductsTest passaram com 48 testes e 541 asserções em SQLite em memória. Pint do teste PHP alterado, build Docker, limpeza de views e git diff --check passaram. Sem conferência visual autenticada no navegador.
