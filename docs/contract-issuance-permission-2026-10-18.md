# Autorização de emissão de contratos

**Cadastros → Usuários → Novo/Editar → Autorizações de cadastros e emissão** inclui **Emissão de contratos**. Administrador e Administrador Master possuem acesso automático; podem conceder ou revogar a autorização de usuários comuns conforme a hierarquia existente.

A autorização controla criação de novos contratos e geração de documentos individuais (resumo PDF, extrato e publicação). Não torna disponíveis modelos ainda não implementados. Não altera a consulta, a edição de contratos existentes, os relatórios gerais ou o escopo de Fundo/Secretaria. A autorização de Fornecedor não implica emissão. Dados pessoais continuam mascarados para usuários comuns, mesmo autorizados a emitir.

A chave `contract-issuance` é persistida no JSON de autorizações existente. Não há nova migration ou concessão automática a usuários comuns. `User::DELEGATABLE_PERMISSIONS` reúne tipos de cadastro e emissão, mantendo `CATALOGS` específico de cadastros. `ContractPolicy::create` exige permissão e vínculo válido; `issue` exige permissão e visibilidade do contrato. O adaptador de persistência continua aplicando a policy dentro de `ContractAccess::lockForSave`.

Extrato e publicação revalidam emissão após lock do ator/contrato na captura e antes da entrega, com renderização fora da transação. O resumo PDF também revalida ator, emissão e versão após renderizar e audita na transação final, removendo o arquivo se falhar. A revogação das autorizações continua auditada com incremento de versão da sessão.

Seleção/consulta de fornecedores permanece separada da autorização para criar contratos. A consulta de sugestões de Fundo permanece restrita ao Fundo autorizado no servidor. Botões de criação e emissão respeitam a nova permissão.

Validações: ContractIssuancePermissionTest (sem autorização, concessão/revogação por ambos os perfis administrativos, chamadas HTTP e adaptador direto, escopo e separação de cadastros); SupplyContractExtractTest (revogação durante a renderização); suítes anteriores de escrita, fornecedores, assinatura e mascaramento. Fixtures desses cenários recebem autorização explícita para continuar verificando suas regras originais; testes novos verificam a negação por padrão. SQLite em memória e storage temporário.

Resultado final: composer check aprovado (Pint e 545 testes, 5.103 asserções, nenhuma falha, 1 teste ignorado); Architecture (3 testes) e Unit (57 testes) aprovados; npm run build e php artisan view:cache concluídos. A validação de numeração mantém suas asserções originais, com autorização explícita no cenário que deve alcançar a validação do contrato. Nenhum banco real foi usado pela suíte e nenhuma permissão foi concedida automaticamente.
