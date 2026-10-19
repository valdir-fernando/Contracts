# Hierarquia de usuários

Perfis persistidos em `users.user_type`:

| Perfil | Gestão de contas |
| --- | --- |
| Administrador Master | Cria, edita, redefine senhas, exclui logicamente e restaura contas de todos os perfis |
| Administrador | Essas operações apenas sobre contas do perfil Usuario; não pode promover Usuários |
| Usuario | Não gerencia contas; mantém edição dos próprios dados pessoais por Minha conta |

Master herda as permissões administrativas existentes de contratos, relatórios e cadastros. `User::isAdmin()` reconhece ambos os perfis administrativos; `isMaster()` distingue a governança de contas. As policies verificam o alvo e o controller revalida ator, alvo e perfil resultante na transação. Campos de perfil/situação/vínculos da própria conta são alteráveis apenas por Master; nome, login, e-mail, senha e foto continuam disponíveis ao próprio usuário autenticado.

`UserGovernance` bloqueia administradores e Masters ativos em ordem de ID antes de bloquear ator e alvo. Impede remover/desativar/rebaixar o último Master ativo, mesmo quando existem administradores comuns. Preserva também a proteção do último administrador em instalações antigas ainda sem Master. Todas as mutações mantêm versão e auditoria inseparáveis, sem apagar histórico ou contas.

Promoção local solicitada: `php artisan contracts:promote-master FERNANDO`. O comando preserva senha e vínculos, registra auditoria de sistema e invalida sessões anteriores. É idempotente e recusa contas inexistentes, excluídas ou inativas. O provisionamento anterior `contracts:create-admin` continua criando Administrador; a promoção é explícita. Não há migration destrutiva ou alteração de stack.

Validação: testes de hierarquia, gestão de usuários e própria conta; Pint; composer check (suíte completa); templates compilados. Testes usam SQLite em memória e storage temporário. A promoção foi executada no banco Docker local, sem redefinir senhas.

Resultado final de composer check: Pint aprovado; 530 testes, 4.955 asserções, nenhuma falha e 1 teste ignorado. composer check:architecture (3 testes) e composer test:unit (57 testes) também aprovados; npm run build concluído. A única falha inicial era uma expectativa do título antigo do painel, atualizada para o texto com exercício solicitado anteriormente, preservando as verificações financeiras.
