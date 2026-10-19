# Edição da própria conta

Para Administradores comuns e usuários comuns, o seletor de perfil permanece desabilitado e apresenta somente o perfil atual, sem oferecer Master. Enviar `user_type=Administrador Master` diretamente a `PUT /account` é recusado com validação 422, sem alterar perfil, versão ou auditoria.

Minha conta oferece nome, Usuário / Apelido, e-mail, senha, foto e consulta de perfil/situação/vínculos. Apenas Administradores Master podem editar perfil, situação e Fundo/Secretaria, sujeitos à policy existente. Não há parâmetro de usuário-alvo: a identidade vem da sessão e IDs enviados são recusados. Administradores comuns e usuários comuns continuam impedidos de alterar suas próprias permissões.

AccountController mantém senha atual obrigatória e revalida seu hash sob lock. Operações administrativas bloqueiam administradores ativos em ordem de ID antes do ator, preservam o último Administrador Master, exigem versão e auditam dados e escopos na mesma transação. O formulário envia versão também para alterações pessoais. Mudança de login ou permissões invalida outras sessões; desativar a própria conta encerra a sessão atual. Dados pessoais da auditoria permanecem no servidor; senhas não entram no snapshot.

Os endpoints anteriores continuam disponíveis. Requisições pessoais antigas sem versão/username permanecem compatíveis; mudanças administrativas exigem versão. Fotos seguem o fluxo privado existente.

Validação executada: OwnAccountEditingTest (3 testes, 26 asserções), ContractCompletionTest e AuditSecurityRegressionTest (execução conjunta inicial: 22 testes, 131 asserções); composer check:architecture; Pint nos arquivos PHP alterados; php artisan view:cache; npm run build pelo serviço node. Testes com SQLite em memória e storage temporário. Sem migrations ou mudanças diretas nos dados.
