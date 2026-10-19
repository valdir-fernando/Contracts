# Importação local de usuários de referência

O banco Docker local (`mysql`, host `db`) tinha 1 usuário. A origem privada `database-example/u910323952_bdgestao_niq.sql` contém 17 registros de `tb_Usuario`; a aplicação usa `users`.

A execução padrão do ReferenceUsersSeeder recusou o login FERNANDO já existente e reverteu a transação. O modo explícito `run(null, true)` preserva cadastros com username coincidente, sem alterar senha, perfil, estado ou vínculos. O modo padrão mantém a recusa de conflitos. A importação registra auditoria de sistema para cada usuário criado e resolve IDs de Fundo/Secretaria apenas quando há correspondência única nos catálogos; correspondências ausentes ou ambíguas não autorizam acesso.

Resultado local: 16 registros importados, 17 usuários no total, 16 auditorias de importação, nenhum escopo sem identificação de Fundo ou Secretaria. Nenhuma senha foi exibida. Contas existentes foram preservadas. Reexecutar o modo de preservação não duplica usuários nem auditorias.

Validação: Pint; PHPUnit LoginTest e UserManagementTest com dados fictícios, SQLite em memória e storage temporário: 24 testes, 200 asserções aprovadas. A importação de dados foi executada apenas no ambiente local solicitado, sem migrations ou restauração do banco.
