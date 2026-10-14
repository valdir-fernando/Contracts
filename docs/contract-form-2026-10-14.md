# Formulário de contratos — 14/10/2026

## Apresentação final

No cadastro e na edição, cabeçalho, Secretaria, Tipo de contrato e navegação permanecem fixos; somente os painéis têm rolagem própria. Os botões de seção deslocam apenas essa área, com foco no título sem mover a página. Salvar e Cancelar permanecem acessíveis; Anterior/Próxima seção foram retirados.

O cabeçalho não apresenta data, calendário, avatar, breadcrumb duplicado ou seletor de Fundo. Voltar fica acima do nome do Fundo, cuja fonte se adapta à largura disponível; título do contrato e fornecedor ficam à direita. Secretaria e Tipo de contrato ficam sem moldura, com os botões de documentos à direita, alinhados à base. A navegação não tem painel nem título Dados do contrato; Expandir Tudo exibe todas as seções e ocupa o espaço restante da linha.

Botões de navegação usam azul del rey/texto azul e, no hover/foco, azul claro/texto preto. Expandir Tudo usa dourado e, no hover/foco, vermelho claro/texto preto. Espaçamento entre painéis foi reduzido em 60%, e existe uma margem de 8px abaixo do cabeçalho.

Escopo/Tipo de veículo, Cargo de credenciamento/Local do serviço e Instalações têm altura de uma linha e ocupam uma coluna. Dados do veículo também tem uma linha. Endereços do fornecedor/fundo têm duas linhas e altura compacta, inclusive em aditivos. IPTU fica compacto junto de Endereço do imóvel, que ocupa o restante da linha; ambos têm altura de 44px e o espaçamento padrão dos campos. As opções de IPTU possuem títulos explicativos; o campo atualiza sua dica conforme a seleção. A apresentação de dicas em opções do select nativo depende do navegador.

## IPTU

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

## Identificação e licitação

O formulário de criação e edição não apresenta Processo do edital (`proc_edital`), Número do edital (`n_edital`), Data da licitação (`data_licit`) e Número da solicitação (`n_solicit`). As colunas, importação, visualização histórica e compatibilidade HTTP são preservadas; uma edição com campos omitidos não apaga os valores anteriores.

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
