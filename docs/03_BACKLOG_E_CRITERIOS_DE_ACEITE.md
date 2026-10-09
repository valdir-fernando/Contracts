# Backlog de Desenvolvimento, Histórias e Critérios de Aceite

**Projeto:** Sistema Web de Gestão de Contratos  
**Tecnologias:** Laravel 13, Livewire 4, Alpine.js, Tailwind CSS 4, Docker  
**Fonte da verdade legada:** SQL `u910323952_bdgestao_niq.sql`  
**Prioridade:** P0 = essencial para liberar o MVP; P1 = importante; P2 = evolução.

> **Autorização adotada como premissa provisória:** administrador pode tudo; usuário comum cria, visualiza e edita contratos apenas em fundos/secretarias a que foi vinculado; exclusão lógica de contratos e gestão de usuários são exclusivas de administrador até que a regra seja homologada.

## Etapa 0 — Preparação e qualidade do dado (P0)

| ID | Tarefa | Definição de pronto |
|---|---|---|
| BASE-01 | Criar repositório e Git ignore | `.env`, dumps SQL, arquivos de senha, `auth.json`, arquivos gerados e logs fora do Git |
| BASE-02 | Subir Laravel em Docker | App, Nginx, MariaDB, Redis, worker e mail de desenvolvimento funcionam |
| BASE-03 | Instalar Livewire e tema | Layout Blade; Alpine não duplicado; token CSS e componentes básicos |
| BASE-04 | Migrations e policies | Tabelas core, relações, `softDeletes`, FKs, controle por escopo |
| BASE-05 | Documento fonte→destino de dados | 80/80 campos da `TB_Contratos` mapeados para persistência e UI |
| BASE-06 | Processador do SQL | Importa staging isolado; valida encoding/datas/números sem rodar scripts externos do dump |
| BASE-07 | Tratamento de credenciais | Não migrar `Senha`; cadastrar usuários com reset; bloquear senhas antigas |
| BASE-08 | Relatório de qualidade | Contagens e erros por tabela, inclusive referências não conciliadas |

### Aceite da Etapa 0

- `docker compose up -d --build` inicializa os serviços **após configuração documentada**; app abre e conexão com o banco funciona.
- O PHP do container atende à versão mínima exigida pelo Laravel instalado.
- Nenhum dado pessoal ou senha legada foi commitado.
- A importação de testes reproduz 2.145 contratos, salvo os **explicitamente** rejeitados com motivo e contagem de divergência igual na auditoria.
- Executar o importador duas vezes **não duplica** contratos, fornecedores ou aditivos.
- Todas as chaves históricas `legacy_id` são preservadas; nenhuma associação ambígua é atribuída automaticamente.

## Etapa 1 — Autenticação e usuários (P0)

| História | Como... quero... para... | Cenários de aceite |
|---|---|---|
| AUTH-01 | Usuário, quero entrar com identificador e senha | Válido entra; inválido não entra; usuário inativo é impedido |
| AUTH-02 | Usuário, quero sair | Sessão invalidada; URL protegida redireciona ao login |
| AUTH-03 | Usuário, quero recuperar o acesso | Reset usa token/administrador; senha antiga nunca exibida |
| AUTH-04 | Administrador, quero cadastrar usuários | Username único; papel e escopos obrigatórios conforme regra; hash seguro |
| AUTH-05 | Administrador, quero listar usuários | Filtra por papel/status/órgão, pagina e ordena |
| AUTH-06 | Administrador, quero editar usuários | Alterações persistidas e auditadas; permissões atualizam imediatamente |
| AUTH-07 | Administrador, quero desativar/excluir logicamente usuários | Bloqueia login futuro e mantém histórico de autoria |
| AUTH-08 | Administrador, quero reativar usuário | Registro recuperado com papéis/escopos consistentes |
| AUTH-09 | Administrador, quero proteger governança | Último admin ativo não pode ser removido/desativado |

### Testes P0 (usuários)

1. `guest_cannot_access_contracts`
2. `admin_can_manage_users`
3. `regular_user_cannot_access_users_routes_or_livewire_actions`
4. `inactive_user_cannot_authenticate`
5. `user_password_is_hashed_and_never_returned_to_ui`
6. `last_active_admin_cannot_be_deactivated`
7. `user_scope_is_not_editable_by_regular_user`
8. `login_is_rate_limited`

## Etapa 2 — Gestão de contratos (P0)

| História | Necessidade | Aceite obrigatório |
|---|---|---|
| CONT-01 | Listagem | Paginação real; busca por número, processo, fornecedor e documento; filtros compostos |
| CONT-02 | Consulta por órgão | Fundo/secretaria de outro escopo não aparece nem por URL direta |
| CONT-03 | Criar contrato | Formulário com seções A–G; validação; transação; registro de criador |
| CONT-04 | Cadastro de campos específicos | Dados de imóvel, veículo, obra e credenciamento podem ser armazenados e reabertos |
| CONT-05 | Ver detalhe | Mostra dados gerais, partes, vigência, financeiro, complementares e origem legada |
| CONT-06 | Editar contrato | Mantém todos os campos não editados; grava diferença e autor em auditoria |
| CONT-07 | Evitar edição concorrente | Segunda alteração de registro desatualizado gera aviso/conflito |
| CONT-08 | Excluir contrato | Apenas com permissão; `deleted_at`, motivo e confirmação; vínculo histórico intacto |
| CONT-09 | Restaurar contrato | Apenas admin; dados/relacionamentos conservados; operação auditada |
| CONT-10 | Status contratual | Calcula situação temporal sem sobrepor estado jurídico/administrativo |
| CONT-11 | Pesquisa por exercício | Números `019/26` e similares tratados como strings; zeros preservados |
| CONT-12 | Histórico de aditivos | Mostra aditivos conciliados e indica vínculos pendentes/duvidosos |

### Aceite da Etapa 2

- O cadastro inclui pelo menos os campos mínimos definidos na especificação e expõe os **80 campos legados** nos grupos de visualização/edição (campos só de leitura justificados).
- A listagem não traz registros excluídos; a tela administrativa permite restaurar.
- Cada tentativa de editar/excluir sem permissão falha no servidor (HTTP 403 ou comportamento equivalente verificável).
- `VL_Total` e `VL_Acumulado` não são somados entre si para um único total.
- `Final_Vig_Atualiz='0000-00-00'` não quebra as telas nem aparece como data real.
- Uma busca por número devolve diferentes contratos homônimos do dump, sem colapsá-los em um único registro.
- Um contrato vinculado a vários aditivos mantém todos os eventos históricos.

### Casos adversos que precisam virar testes

| Caso | Entrada sintética de teste | Resultado esperado |
|---|---|---|
| Valor BR | `1.908,6300` | `1908.6300` internamente e `R$ 1.908,63` no display |
| Data zerada | `0000-00-00` | `NULL`, “não informada”, ocorrência de qualidade |
| Número repetido | mesmo `Contrato` em dois fundos | dois contratos separados |
| Fornecedor sem documento | CPF/CNPJ vazio | consulta do legado possível; nova criação exige regras do tipo |
| Campo por extenso discrepante | texto ≠ valor calculado | alerta, sem substituição automática |
| Vínculo de aditivo ambíguo | múltiplos contratos candidatos | pendência para revisão humana |
| Contrato sem fim confiável | sem data ou aditivo não conciliado | estado de vigência “indeterminado” ou “em revisão” |
| Duas edições concorrentes | abas/sessões com versões diferentes | bloqueio da segunda gravação sem revisão |
| Exclusão com histórico | contrato com aditivos/itens | soft delete sem apagar aditivos/itens |
| Escopo de usuário | usuário do Fundo A busca ID do Fundo B | nega consulta e exportação |

## Etapa 3 — Relatórios (P0)

| História | Necessidade | Aceite obrigatório |
|---|---|---|
| REL-01 | Relação geral | Filtros, colunas e totalização na tela e exportados |
| REL-02 | A vencer e vencidos | Data de referência explícita; dias restantes corretos; ignorar `NULL` como vencimento |
| REL-03 | Por fundo/secretaria | Totais por órgão e valores com semântica visível |
| REL-04 | Por fornecedor | Pesquisar por nome/documento; agrupar sem homônimos incorretos |
| REL-05 | Por modalidade/tipo | Agrupar variantes históricas mediante catálogo normalizado |
| REL-06 | Relatório de alterações | Data inicial/final, aditivos associados e pendências destacadas |
| REL-07 | Auditoria | Somente admin; ações com data/hora, ator e motivo |
| REL-08 | Qualidade de dados | Mostrar datas inválidas, strings financeiras inválidas e vínculos duvidosos |
| REL-09 | Exportação | PDF, XLSX e CSV; preservar acentuação, zeros e filtros de acesso |

### Aceite da Etapa 3

- Somatória do relatório na tela é igual à dos dados exportados para os mesmos filtros e a mesma data de referência.
- Todos os relatórios indicam **o indicador financeiro** que está sendo somado.
- Exportar com consulta fabricada/URL de outro órgão não contorna a Policy.
- PDF tem cabeçalho, identificador do relatório, filtros, total e paginação; XLSX/CSV têm colunas estáveis.
- Exportação não inclui senhas, tokens, segredos ou dados pessoais não autorizados.
- Testar 0, 1, 100 e todos os contratos do dump (ambiente de homologação controlado).

## Etapa 4 — Operação, segurança e lançamento (P0)

| ID | Atividade | Pronto quando... |
|---|---|---|
| OPS-01 | Limites de sessão e cookies seguros | HTTPS e políticas de sessão testados |
| OPS-02 | Registro de auditoria | CRUD e exportações sensíveis deixam evento com ator |
| OPS-03 | Backup | Rotina com retenção; restauração integral ensaiada |
| OPS-04 | Healthcheck | App, banco e filas monitoráveis |
| OPS-05 | Deploy de produção | Image versionada, segredos fora do Git e migração segura |
| OPS-06 | Revisão de privacidade | Roles, escopo, acesso aos anexos e prazo de retenção aprovados |
| OPS-07 | Manual curto de operação | Administrador e usuário conseguem executar fluxos documentados |
| OPS-08 | Homologação com a área | Amostra de contratos históricos confrontada com fontes oficiais |

## Etapa 5 — Evoluções (P1/P2)

**P1:** anexação privada de PDF e outros documentos; calendário de vencimentos; alertas configuráveis; CRUD de aditivos, com campos de `tb_Aditivos`; cadastro assistido de fornecedores; ficha de dotação e empenhos; máscaras e validações específicas por tipo; pesquisa avançada.

**P2:** relatórios personalizáveis, dashboard gráfico detalhado, assinatura eletrônica, API interna controlada, importação incremental periódica e eventual integração com sistemas públicos. Nenhuma integração deve ser presumida como contratada nesta fase.

## Ordem de implementação para uma equipe ou Codex CLI

1. **Fundação e dados:** repositório, Docker, migrations, design system, parser de normalização, testes.
2. **Autenticação e autorização:** Login/roles/escopos/Policies; testes de penetração de permissão.
3. **Cadastro completo:** contrato + detalhes e snapshots + formulários dinâmicos; smoke tests.
4. **Consultas e administração:** listagem, filtros, visão detalhada, usuários e auditoria.
5. **Relatórios:** tela e exportações; consistência de valores/vigências.
6. **ETL completo:** importação idempotente, reconciliações, relatório de exceções e homologação.
7. **Produção:** observabilidade, backup/restauração, segurança e documentação de operação.

> Se o desenvolvimento for feito por agente de código, exigir que **cada etapa gere código + migração + teste + nota de alteração** e que nenhuma fase substitua a documentação dos 80 campos por um formulário reduzido.

## Checklist final de aceite do MVP

- [ ] Autenticação de administrador e usuário comum funcionando.
- [ ] Administrador consegue criar/visualizar/editar/desativar/reativar usuários.
- [ ] Usuário comum é isolado por escopo institucional.
- [ ] Cadastro e edição dos 80 campos cobertos no modelo/telas.
- [ ] Lista e detalhe de contratos com filtros, busca e paginação.
- [ ] Exclusão lógica com motivo e restauração de contratos.
- [ ] Aditivos e itens históricos não desaparecem; vínculos ambíguos visíveis.
- [ ] Relatórios gerais, por vigência, órgão, fornecedor e tipo exportáveis.
- [ ] Valores BR e datas zeradas tratados sem erros ou perda de origem.
- [ ] Sem reutilização de senhas do dump; sem segredo versionado.
- [ ] Auditoria, backups e restauração documentados e testados.
- [ ] Testes unitários, Feature e Livewire aprovados em CI.
- [ ] Homologação da área responsável e aceite das decisões pendentes.
