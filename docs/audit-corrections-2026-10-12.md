# Correções da auditoria de verificação — 12/10/2026

## Resultado

Os sete [achados](audit-findings-2026-10-12.md) foram tratados incrementalmente, preservando as alterações locais anteriores, stack, histórico e regras financeiras. Foram adicionados 22 casos de regressão em `tests/Feature/AuditCorrectionsTest.php`; a suíte final tem 248 testes e 1.901 asserções, aprovada em SQLite e MySQL 8.4 descartável.

| Achado | Implementação e evidência |
| --- | --- |
| A1 — campos protegidos | Lista explícita de campos graváveis no validador de contratos; filtros de derivados/pessoais/somente leitura nos patches de aditivos e financeiro. Testes de criação/edição com `null`, string vazia e array vazio preservam snapshots; campo permitido continua distinguindo omissão de limpeza por `null`. |
| A2 — importação histórica | Candidatos bloqueados em ordem de ID; versão incrementada e auditoria de sistema vinculada à execução. `updated_by` não aponta falsamente ao último humano. Testes confirmam mudança de hash com relógio fixo, conflito de formulário antigo, idempotência e rollback quando a auditoria falha. |
| A3 — formulário antigo de usuário | Revisão obrigatória `version` comparada com `session_version` atual do alvo depois de locks/policy; conflito 409. Todos os formulários de mutação administrativa incluem a revisão. Teste com dois administradores preserva os escopos atualizados e evita uma segunda auditoria; versão ausente é recusada e não autoriza usuário comum. |
| A4 — arquivo substituído | SHA-256 e tamanho persistidos somente na publicação; download verifica ambos. Arquivo substituído por outro de mesmo tamanho recebe 409 e não registra download. Exportação antiga sem digest recebe 410 e a tela oferece nova solicitação. |
| A5 — geração abandonada | Claim com token e início; publicação/falha condicionadas à propriedade da tentativa. Callback utiliza token serializado original. Limpeza expira registros sem caminho e encerra abandono depois de 960s. Testes verificam estados antigos, processos ativos, publicação/falha tardias, callback reconstruído e preservação da nova tentativa. |
| A6 — senha vazia | Edição cadastral usa lista explícita de atributos, excluindo senha. Entradas vazias não produzem 500 nem mudam credenciais; redefinição permanece na operação dedicada. |
| A7 — indisponibilidade local | Banco, PHP, Nginx, worker e scheduler retomados. Heartbeats/healthchecks e comando operacional detectam ausência de atividade e espera da fila. `pcntl` habilita timeout; DNS dinâmico do Nginx resolve backend após recriação do PHP. Login e `/ready` respondem 200. |

## Schema, compatibilidade e instalação local

Duas migrations incrementais:

- `2026_10_12_000001_secure_report_export_files_and_processing`: adiciona digest, tamanho e identidade/início da geração; preserva exportações existentes.
- `2026_10_12_000002_record_system_import_contract_audits`: permite ator de sistema explícito e adiciona FK restritiva para execução de importação. Auditorias humanas mantêm seus atores; rollback de código não remove esses metadados.

Rotas e assinaturas públicas foram mantidas. O controle de concorrência acrescenta o campo obrigatório `version` aos payloads de mutações de usuário, com valor visto no formulário (revisão do alvo, não permissão). Clientes/formulários antigos precisam recarregar/adaptar esse controle. Domain/Application não receberam dependências do framework.

Antes de aplicar as migrations locais, foi gerado `backups/contracts-20261012T221151Z-28128.tar.gz`, restrito pelo script de backup. Worker e scheduler foram pausados durante a atualização. As migrations foram aplicadas explicitamente por caminho, após passar no MySQL isolado. Contagens antes/depois: 2.154 contratos, 82 auditorias de contratos, dois usuários e três exportações; todas iguais. Depois, os serviços foram recriados com a nova imagem, a limpeza de exportações foi executada e os metadados/histórico foram preservados.

A operação mantém um registro histórico de job falho de 10/10/2026. Ele não foi removido nem reexecutado. O comando operacional confirma atividade de worker/scheduler e não detecta espera excessiva da fila; não confunde essa falha histórica com uma falha atual.

## Verificações executadas

| Comando / ensaio | Resultado |
| --- | --- |
| PHPUnit focado: correções, usuários, segurança administrativa, histórico e exportações | 52 testes, 335 asserções, passou na segunda execução. |
| `docker compose run --rm --no-deps --entrypoint composer app check` final | Pint: 170 arquivos aprovados; SQLite: 248 testes, 1.901 asserções, aprovado. |
| PHPUnit final com `/tmp/contracts-corrections-phpunit.xml` em container `contracts-app:latest` | MySQL 8.4 descartável: 248 testes, 1.901 asserções, aprovado. Rede interna própria, sem porta publicada, dados em tmpfs; não acessou a instalação. |
| `composer check:architecture` | Três testes, quatro asserções, aprovado. |
| `composer test:unit` | 51 testes, 97 asserções, aprovado. |
| `composer validate --strict --no-check-publish` | Aprovado. |
| `composer audit --locked --no-interaction` | Nenhum advisory reportado. |
| `npm run build && npm audit --package-lock-only`, container Node 22 | Build Vite 7.3.7 aprovado; zero vulnerabilidades reportadas. |
| Worker daemon real com geração sintética de 3s e timeout de 1s | Processo encerrado com código 1; callback reconstruído marca somente sua exportação como falha, limpa claim e não publica caminho/evento de geração. MySQL/storage isolados. |
| `docker compose build app`; verificação de `extension_loaded('pcntl')` | Imagem reconstruída; extensão ativa. |
| Compose local e produção usando `.env.production.example` temporariamente para validação | Configurações válidas; nenhuma configuração/infraestrutura de produção foi criada. |
| `nginx -t`, reload e consultas HTTP locais | Configuração válida; `/ready` e `/login` com sucesso após a correção de resolução do backend. |
| `view:cache` na instalação local | Templates compilados com sucesso. |
| `contracts:check-operations`, healthchecks e logs | Banco/fila acessíveis, worker/scheduler com atividade recente e containers saudáveis. |
| Migrations locais por caminho e `migrate:status` | Duas migrations aplicadas; contagens preservadas; nenhuma migration pendente. |
| `git diff --check` | Aprovado. |

Durante a implementação, a primeira suíte focada revelou que `contract_audits.actor_id` era obrigatório: foi introduzida a migration de identidade de sistema/proveniência, sem atribuir importações a um usuário arbitrário. Uma verificação de Pint falhou no arquivo novo de testes; o formato foi corrigido. O primeiro ensaio de timeout usava `queue:work --once`, que não instala o handler de timeout do daemon; o código do Worker foi examinado e o ensaio repetido corretamente com `--stop-when-empty --max-jobs=1`. Uma resposta 502 após recriar o PHP foi investigada nos logs do Nginx e levou à correção do DNS do backend.

As asserções existentes não foram removidas ou enfraquecidas. Os testes de usuário receberam a revisão exigida pelo novo controle; as regressões adicionais demonstram recusa de revisão antiga/ausente. Os containers/rede temporários de validação foram removidos ao final.

## Limites mantidos

Não houve commit, publicação externa, importação de dados reais, migration destrutiva, teste no banco instalado, restauração de backup ou aplicação automática de efeitos financeiros/jurídicos. As migrations e comandos operacionais locais descritos acima são a atualização da instalação, não execução da suíte sobre seus dados.

Não foi feito novo ensaio concorrente de importação em processos separados nem carga de produção ou navegação autenticada em navegador. O MySQL confirma integração/schema; os testes de tentativas sobrepostas exercitam interleavings controlados, e o timeout usa um processo real. Retenção institucional, imutabilidade da auditoria, homologação administrativa e destino externo de backup permanecem decisões operacionais próprias.
