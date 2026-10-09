# Documentação — Sistema Web de Gestão de Contratos

**Versão:** 1.0 — 09/10/2026  
**Propósito:** especificação para desenvolver a aplicação, não é o código do sistema.

## Stack definida

Laravel 13 + Livewire 4 + Alpine.js (integrado ao Livewire) + Tailwind CSS 4 + componentes Blade/Flux UI com linguagem visual inspirada em shadcn/ui + MariaDB + Docker.

O shadcn/ui oficial para Laravel pressupõe React/Inertia; **esta documentação mantém Livewire como camada de UI, sem React**.

## Arquivos neste pacote

1. **`01_ESPECIFICACAO_FUNCIONAL_E_TECNICA.md`** — objetivos, arquitetura, CRUD de contratos/usuários, permissões, fluxos, relatórios, segurança, migração e critérios de qualidade.
2. **`02_DICIONARIO_DE_DADOS_SQL.md`** — inventário literal de 46 tabelas, 546 campos e registros; mapeamento **completo dos 80 campos** de `TB_Contratos`; relações lógicas possíveis e problemas de migração.
3. **`03_BACKLOG_E_CRITERIOS_DE_ACEITE.md`** — histórias, tarefas e testes por etapa (P0/P1/P2), com definição de pronto.
4. **`04_GUIA_ARQUITETURA_DOCKER_E_UI.md`** — arquitetura, estrutura Laravel, setup, serviços Docker, design system e diagrama ER conceitual.

## Base factual

O dump fornecido `u910323952_bdgestao_niq.sql` foi examinado localmente. Inventário: 46 tabelas, 27.190 registros; `TB_Contratos` 2.145; `tb_Aditivos` 985; `tb_Produtos_Contratos` 4.553; `tb_Fornecedor` 1.175; `tb_Usuario` 17. Os dados originais **não estão incluídos neste pacote** por conterem informações pessoais e credenciais legadas.

## Antes de programar

- Ler primeiro o arquivo 01 (requisitos), depois 02 (semântica real do SQL), 03 (ordem/aceite) e 04 (implementação).
- Tratar as regras identificadas como **[PROPOSTO]** e **[PENDENTE]** conforme indicação do documento 01.
- **Não simplificar `TB_Contratos`** a uma tabela com 10 campos: todos os 80 devem ser preservados e recuperáveis, inclusive os específicos de imóvel, veículo, obras, garantias, distratos e publicação.
- Não reutilizar credenciais legadas. Não anexar o dump SQL a repositório público nem a issues compartilhadas.
- Testar migração idempotente, datas `0000-00-00`, moeda em formato brasileiro e identificadores repetidos.
- Restringir usuário comum por fundo/secretaria e aplicar políticas no backend, inclusive Livewire/exportações.

## Prompt inicial para agente de desenvolvimento/Codex CLI

> Leia integralmente os arquivos README.md e 01 a 04 desta pasta. Implemente o sistema web de gestão de contratos usando Laravel 13, Livewire 4, Alpine integrado, Tailwind 4, visual inspirado em shadcn/ui por Blade/Flux UI e Docker Compose. Comece pela Etapa 0 de `03_BACKLOG_E_CRITERIOS_DE_ACEITE.md`. Para cada etapa, entregue migrations, modelos, serviços, componentes Livewire, Policies, testes e notas das alterações. Preserve a cobertura dos 80 campos de `TB_Contratos`; não carregue senhas legadas nem copie o SQL original para o Git. Não invente relacionamentos, saldos ou regras jurídicas: marque ambiguidades para conciliação. Execute os testes e confirme os critérios de aceite antes de avançar.

## Estado atual

**Entregue:** documentação técnica e funcional, aplicação Laravel em Docker, login, gestão de usuários e permissões, diagnóstico de desempenho e importador dos 80 campos dos contratos legados com testes.

Notas das entregas: [login](05_LOGIN_IMPLEMENTADO.md), [desempenho](06_DESEMPENHO_DOCKER_WINDOWS.md), [usuários e permissões](07_USUARIOS_E_PERMISSOES.md) e [importação de contratos](08_IMPORTACAO_CONTRATOS.md).

**Pendente:** telas e CRUD de contratos, conciliação de aditivos, relatórios e demais etapas do backlog. O ambiente atual usa MySQL e formulários Blade; Livewire ainda não foi instalado. Deploy não foi realizado.
