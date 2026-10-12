# Desenvolvimento do Contracts

Leia `README.md` e `docs/architecture.md` antes de alterar o código. Verifique o estado do Git e preserve mudanças existentes. Evolua o monólito Laravel de forma incremental; não substitua a stack nem reestruture módulos sem uma necessidade demonstrada.

## Fronteiras

- `app/Domain`: PHP puro e dependências de Domain. Sem Laravel, Eloquent, Carbon, facades, HTTP, container, helpers globais ou relógio implícito.
- `app/Application`: casos de uso, interfaces, identidade e falhas. Depende somente de Application, Domain e PHP nativo. Recebe interfaces por construtor; não chama `app()` nem modelos/facades.
- `app/Infrastructure`: implementação Laravel/Eloquent das interfaces, autorização, validação contextual, persistência, discos e tradução HTTP. Não importa controllers, Livewire ou providers.
- `app/Providers`: composição dos adaptadores. Não criar singleton com estado mutável de ator/contrato.
- Controllers, Livewire, CLI e serviços de compatibilidade são consumidores externos. Preserve suas assinaturas e os contratos HTTP existentes.

A suíte `Architecture` fiscaliza essas regras. Não introduza whitelist para esconder violações; mantenha o componente externo em um adaptador. Prefira interfaces específicas das necessidades do caso de uso, sem repositories genéricos ou DTOs que apenas dupliquem o model inteiro.

## Integridade e segurança

- Identidade vem do usuário autenticado. Perfil, autoria, versão da sessão e snapshots de órgãos do cliente não autorizam operações.
- Toda mutação protege ator, contrato atual, policy, validação contextual, versão, persistência e auditoria dentro da mesma transação. Ator é bloqueado antes do contrato; preserve a ordem especial de locks da governança do último administrador.
- Use `ContractAccess::lockForSave`/`lockForLifecycle`; não determine abilities a partir de parâmetros HTTP.
- Escopos de fundo/secretaria devem ser aplicados no backend, inclusive em relatórios e documentos. Reautorize respostas de conflito antes de apresentar dados atuais.
- Snapshots internos contêm campos pessoais completos. Não retorne models ou `ContractState::attributes()` diretamente em endpoints; use projeções explícitas e mascaramento/autorização por perfil, como a API interna existente.
- Persista alterações de contratos com auditoria inseparável. Não mova a trilha para evento assíncrono. `TransactionGuard` recusa mutações sem transação.
- Diferencie campos omitidos de `null`. Use `ContractMapper` para preservar casts, JSON original, datas, decimais e snapshots; não serialize novamente JSON já serializado.
- Dinheiro é string decimal exata. Não aplique efeitos financeiros ou de vigência a aditivos sem regra funcional estabelecida e testes.
- Preserve histórico e soft delete. Não remova tabelas, campos ou registros existentes. Não execute testes, migrations destrutivas ou restaurações em bancos reais.
- Documentos e exportações permanecem privados; autorização, integridade, expiração e invalidação devem ser verificadas no servidor. Renderização longa não pertence à transação de escrita.

## Validação

Adicione testes significativos para regras e mudanças de comportamento; use PHPUnit puro para Domain/Application e testes de integração para adaptadores e segurança. Não remova ou enfraqueça testes para ocultar falhas. Investigue a causa antes de modificar asserções.

Comandos: `composer check:architecture`, `composer test:unit`, `composer check`, `npm run build`. O ambiente Docker está documentado em `docs/architecture.md`. SQLite em memória e storage/cache temporários são os padrões seguros de teste; MySQL exige schema descartável e isolado.

Registre comandos efetivamente executados, resultados e limitações. Atualize a documentação quando contratos internos ou fronteiras mudarem. Não gere commits, publique ou envie mensagens externas sem instrução do usuário.
