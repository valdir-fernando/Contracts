# Usuários e permissões

O módulo `/users` permite listar, pesquisar, filtrar, cadastrar, editar, desativar, excluir logicamente, restaurar e redefinir senhas. É acessível somente a administradores ativos. A autorização é aplicada no servidor por middleware e Policy, inclusive para requisições diretas às ações.

O perfil `Administrador` tem acesso global aos órgãos e gerencia contas. O perfil `Usuario` deve ter ao menos um vínculo de fundo/secretaria. Cada combinação é registrada em `access_scopes`, com vínculos múltiplos na tabela `access_scope_user`. A migration converte os fundos/secretarias das 17 contas já importadas em vínculos relacionais, preservando as credenciais e os registros existentes. Administradores mantêm os vínculos históricos, mas possuem acesso global. Essa matriz segue a premissa do backlog; o controle de contratos será integrado às Policies do módulo quando ele for implementado.

O método `User::canAccessScope()` permite verificar o vínculo de um usuário ativo. Ele não substitui a Policy do futuro contrato, que também deverá verificar a ação solicitada. Perfis e vínculos não têm endpoints de edição acessíveis a usuários comuns.

Nome de usuário é normalizado para maiúsculas e deve ser único, inclusive entre contas excluídas. Novas senhas e redefinições exigem confirmação, pelo menos 12 caracteres, letras e números; são armazenadas em hash e não são exibidas nas telas nem na auditoria. Recuperação de acesso nesta etapa é realizada por um administrador, sem envio de e-mail. As senhas já importadas são preservadas conforme a decisão de implantação local.

O último administrador ativo não pode ser desativado, excluído nem convertido em usuário comum. A verificação ocorre em transação com bloqueio dos administradores ativos. Exclusão exige digitar o usuário e informar um motivo; restauração reativa a conta e mantém seus vínculos. As alterações invalidam as outras sessões e tokens de lembrar acesso da conta afetada. Uma sessão ativa também é encerrada ao detectar desativação. A sessão do administrador que altera sua própria conta é mantida, com suas novas permissões.

`user_audits` registra ator, conta, ação, data, motivo de exclusão e valores anteriores/posteriores do cadastro e dos vínculos. Senhas, hashes e tokens não são incluídos. A tela de edição mostra os 20 eventos mais recentes; todos os registros ficam preservados no banco. Alterações diretas no banco ou via Tinker não passam pela auditoria do módulo.

O visual usa Blade e CSS no padrão do Contracts: azul Del Rey, bordas discretas e componentes responsivos. Livewire ainda não está instalado; os formulários usam submissão convencional com validação no servidor.

```powershell
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php vendor/bin/phpunit
npm.cmd run build
```

Os testes usam banco SQLite em memória. Cobrem autenticação, autorização administrativa, escopos, senhas, invalidação de sessões, proteção do último administrador, confirmação de exclusão, restauração, auditoria e reexecução da importação sem reativar contas excluídas.
