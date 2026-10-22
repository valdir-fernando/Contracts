# Data real do cadastro de contratos

Ao cadastrar um contrato novo pelo fluxo de `ContractWriter`/`SaveContract`, `data_cont_verd` recebe a data atual no fuso `America/Sao_Paulo`. O servidor consulta `https://timeapi.io/api/Time/current/zone` com o parâmetro fixo `timeZone=America/Sao_Paulo`, sem transmitir informações de usuários, contratos ou fornecedores.

Se houver timeout, falha de conexão, erro HTTP, redirecionamento ou resposta inválida, utiliza automaticamente a data do sistema do servidor/PHP no mesmo fuso, **sem aviso ao usuário**. No ambiente Docker local, esse relógio acompanha o ambiente do computador; na hospedagem, é o relógio do servidor. Não há leitura de data enviada pelo navegador.

## Fronteiras e integridade

`Application/Contracts/Ports/ContractCreationDate` fornece a data como texto ISO. `Infrastructure/Contracts/OnlineContractCreationDate` implementa a consulta HTTPS com conexão limitada a dois segundos, timeout total de cinco segundos, sem redirecionamentos, sem cache da data e com validação de fuso, campos inteiros e data válida. O adapter nunca faz HTTP dentro de uma transação de banco: se um consumidor externo já abriu uma transação (como o comando de demonstração), usa o mesmo fallback local silencioso, preservando a compatibilidade e a atomicidade desse consumidor.

`SaveContract` resolve a data uma única vez antes de abrir a transação de criação, incluindo eventuais repetições transacionais. Ator, contrato, permissões, validação, persistência, versão e auditoria continuam protegidos pelo fluxo transacional existente. A data atribuída integra a mesma gravação e auditoria do contrato.

Valores de `data_cont_verd` recebidos do cliente são ignorados. Edições não consultam a fonte e não alteram a data já salva, inclusive quando o valor histórico é nulo. Contratos existentes e importações históricas mantêm seus dados. A mudança não altera `data_contrato`, vigência, numeração, exercício ou outros relógios do sistema.

## Validação

Testes de Domain/Application usam uma porta falsa, sem Laravel ou HTTP. Testes de integração usuais recebem uma implementação de teste explícita em `Tests/TestCase`; não dependem da disponibilidade da API. Testes específicos do adapter usam `Http::fake`, sem gravação em banco, cobrindo data online independente do relógio local, UTC próximo à meia-noite, falhas e respostas inválidas, além do fallback sem HTTP dentro de transação externa. Testes de persistência em SQLite isolado verificam proteção contra manipulação, auditoria e preservação na edição.

A primeira montagem dos testes com `DatabaseMigrations` encontrou um erro no rollback de uma migration histórica do SQLite. A cobertura foi separada corretamente: adapter HTTP sem migrations e persistência com o isolamento `RefreshDatabase` já usado no projeto. Não foram alteradas migrations históricas nem enfraquecidas as verificações da data.

Uma consulta de leitura no ambiente local retornou `2026-10-22`, no fuso `America/Sao_Paulo`, com nível de transação zero. Nenhum contrato instalado foi criado ou alterado para testar o fluxo.

Comandos efetivamente executados:

- `docker compose exec -T app composer check`: Pint em 343 arquivos e suíte completa com 741 testes, 7.372 asserções e 1 ignorado; passou.
- `docker compose exec -T app composer test:unit`: 81 testes, 195 asserções; passou.
- `docker compose exec -T app composer check:architecture`: 3 testes, 4 asserções; passou.
- `docker compose exec -T app composer check:static`: sem erros.
- Validação final após compatibilizar transações externas: `docker compose exec -T app php vendor/bin/phpunit --filter='OnlineContractCreationDateTest|ContractUseCasesTest|test_creation_date_is_trusted' --do-not-cache-result` (22 testes, 64 asserções); passou.
- Pint dos PHP alterados, `git diff --check` e saúde HTTP `/up` (200): passaram.

Sem alteração de assets ou migration necessária. Sem conferência visual autenticada no navegador. A troca online/local não adiciona aviso, erro ou mensagem específica ao formulário; a confirmação normal de gravação continua existente.
