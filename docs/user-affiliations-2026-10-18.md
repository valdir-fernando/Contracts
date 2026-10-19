# Fundo e Secretaria no cadastro de usuários

O formulário usa os catálogos `contract_funds` (ativos) e `contract_departments`, sem depender de um escopo previamente cadastrado. Município de Niquelândia não aparece como opção de Fundo; escolher uma Secretaria resolve o Município pelo nome normalizado no servidor e exige um único cadastro municipal ativo.

`Infrastructure/Security/UserAffiliation` cria/reutiliza o escopo específico e sincroniza as permissões dentro da transação administrativa existente, após autorização e locks do ator/usuário. Preserva a ordem especial dos administradores. Fundos são bloqueados em ordem de ID antes das Secretarias. Versão da sessão e auditoria continuam transacionais.

A aplicação grava em `users`, não na tabela legada `tb_Usuario`. Para um vínculo, `users.fund` recebe o nome do Fundo e `users.department` recebe a Secretaria ou null. O formulário usa lista suspensa de seleção única, com limite de um vínculo validado no servidor. No cadastro de Novo usuário, Situação e Fundo / secretaria são obrigatórios para todos os perfis; Usuário / Apelido identifica o login. Dados existentes não são alterados até uma gravação explícita. Esses campos informativos não autorizam acesso. Vínculos antigos indisponíveis no catálogo permanecem visíveis e somente podem ser mantidos para seu usuário atual. O contrato HTTP anterior com `scope_ids` permanece compatível.

Validação executada em SQLite em memória e armazenamento temporário: testes UserAffiliationTest, AdministrativeMutationSecurityTest e AuditCorrectionsTest; suíte Unit; suíte Architecture; Pint nos arquivos PHP alterados. Templates compilados com `php artisan view:cache`. Sem migrations ou alteração de dados existentes.
