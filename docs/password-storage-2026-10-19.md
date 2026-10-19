# Armazenamento de senhas

O model `User` aplica o cast Laravel `hashed` à senha antes da persistência. Cadastro, importação de referência, criação por CLI, alteração na própria conta e redefinição administrativa usam esse model. A autenticação verifica o hash, sem recuperar a senha original. O atributo também fica oculto na serialização do usuário e não integra as projeções de auditoria de contas.

O administrador do banco pode consultar o hash, mas não existe operação de descriptografia para obter a senha. Senhas fracas ainda podem ser descobertas por tentativas; o hash não impede que alguém com acesso de escrita ao banco substitua credenciais. Arquivos SQL legados e backups anteriores têm proteção e retenção próprias: o cast não modifica esses arquivos.

Validação em 19/10/2026: `docker compose exec -T app php artisan test --filter='PasswordStorageTest|UserManagementTest|SelectedFundTest'` passou com 23 testes e 194 asserções, usando SQLite em memória. Inclui consulta direta ao valor persistido, hashes distintos para senhas iguais, troca de senha, serialização, criação, redefinição e login. Nenhum banco instalado ou backup foi auditado ou alterado nesta verificação.
