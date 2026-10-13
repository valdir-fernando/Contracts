# Segurança, CI e confiabilidade — revisão de 12/10/2026

## Base e escopo

Base `57cdcec`, branch `main`, árvore limpa antes das alterações. Lidos AGENTS.md, README, arquitetura e auditorias recentes. Os relatórios anteriores descrevem snapshots; esta revisão não reclassifica como pendentes as correções já presentes de escopos, patches protegidos, sessões/versionamento, importações auditadas, hashes de exportações, claims de jobs e limpeza de abandonados.

Inspecionados rotas, autenticação, middleware, policies, usuários/administração, contratos e seus adaptadores, aditivos/financeiro/revisão, anexos, relatórios/API interna, Livewire, fila, configuração, Docker, backup e CI. Foram preservados stack, camadas, interfaces, rotas, regras financeiras/jurídicas, soft deletes e história. Nenhuma migration, restore ou escrita foi executada nos bancos/volumes operacionais. A aplicação não foi reiniciada ou publicada.

## Problemas confirmados e correções

| Problema confirmado | Evidência | Correção |
| --- | --- | --- |
| Login autentica antes do evento; falha deste mantém guard autenticado e `last_login_at` gravado | Novo teste provoca falha de `ActivityEvent::create`; implementação anterior retorna 500 e continua autenticada | Transação para autenticação/dados/evento, revalidação do usuário bloqueado e encerramento de sessão/cookies em erro. Inclui rollback do remember token criado durante a tentativa. |
| Falha de auditoria impede logout | Novo teste autentica, provoca falha no evento e demonstra que a implementação anterior continua autenticada | Encerramento em `finally`; limpeza local também ocorre se a rotação do remember token falhar. Erros não são engolidos nem apresentados como auditoria gravada. Regressão adicional cobre falha ao persistir a rotação do remember token; a sessão local termina mesmo nesse caso. |
| Erro ao gravar tentativa inválida evita incrementar limitador | Cinco tentativas com evento indisponível não produziam bloqueio na sexta | Incremento antes do evento. O teste confirma bloqueio mesmo com a falha persistente. |
| Restore de verificação executava SQL como root no servidor da aplicação | `verify-backup.sh` usava `docker compose exec db mysql -u root` com schema novo. SQL pode escolher outros schemas/comandos | Container MySQL independente, sem rede, mounts da instalação ou portas, com dados em tmpfs. Cleanup de container/diretório, inclusive em erro. Checksums e comparação de INSERTs preservados. |
| CI não verificava advisories nem tipos e não coletava cobertura | Workflow e manifests atuais examinados | Auditorias PHP/npm, PHPStan do núcleo e cobertura PCOV/Clover, mantendo Pint, Architecture, SQLite, MySQL e build. Permissões do workflow limitadas à leitura do conteúdo. |

Os três testes de autenticação falharam contra o código anterior exatamente nas condições acima. Depois da correção passaram. Nenhum teste existente foi removido ou enfraquecido. No novo teste de cabeçalhos, a primeira expectativa de Cache-Control omitia as diretivas adicionais do Livewire; ela foi corrigida para verificar as propriedades reais `private` e `no-store`, preservando a resposta mais restritiva.

## Controles verificados e preservados

- Isolamento por fundo/secretaria continua no backend com `visibleTo` e policies. API retorna projeções explícitas e mascaramento, não atributos internos completos. Catálogos/administração exigem perfil; fornecedores limitam contratos associados pelo escopo.
- Escritas de contratos usam ator antes do contrato, validação contextual e revisão atual, persistência e auditoria na mesma transação. A ordem especial do último administrador permanece. Os testes cobrem atualização concorrente/revogação simulada, conflitos e rollback; esta revisão não executou novo ensaio de concorrência entre processos reais.
- Documentos privados mantêm extensão + MIME real, limite de 10 MiB, nome gerado, vínculo ao contrato, policy revalidada, SHA-256 e auditoria. Sem endpoint público de storage. PDF desabilita PHP e recursos remotos. Escaneamento antimalware/quarentena depende de infraestrutura e política institucional, não foi presumido.
- Jobs mantêm claim condicional, token por tentativa, revalidação antes/depois da renderização e publicação auditada. Downloads verificam propriedade, permissões, expiração e integridade. Os testes existentes de duplicação, adulteração, revogação e abandono passaram nos dois bancos.
- Renderização longa permanece fora da transação de escrita. Captura e hash seguem em lotes; CSV/XLSX continuam protegidos contra fórmulas. Não foi introduzida cache que dispense reautorização ou geração automática de estados finais.
- Backup continua privado com checksums e retenção. Healthchecks de banco, worker/scheduler e heartbeat foram examinados; não se alterou timeout/retry, não se reiniciaram serviços e não se certificou monitoramento externo.

## CSP: alteração segura e limite explícito

Aplicada apenas a política compatível e efetiva:

```text
base-uri 'none'; object-src 'none'; frame-ancestors 'self'; form-action 'self'
```

Ela bloqueia alteração da URL base, objetos embutidos, enquadramento por terceiros e submissões de formulários para outra origem. Mantém os cabeçalhos existentes e não adiciona `*`, `unsafe-eval` ou permissões de scripts externos. **É parcial: não restringe fontes de scripts/estilos nem oferece a mitigação de XSS de uma CSP completa.**

O bundle instalado usa o avaliador Alpine padrão, e o formulário contém callbacks, `$nextTick`, `$dispatch` e chamadas Math em expressões. O layout tem script inline; o dashboard usa estilos inline para gráficos. Uma CSP completa requer `livewire.csp_safe=true`, adaptação/teste de expressões, nonce por resposta em Vite/Livewire/scripts, escolha para estilos dinâmicos e endpoints de desenvolvimento WebSocket. O Livewire 4 oferece esse build, mas mudar o avaliador de toda a aplicação e seus templates ultrapassa o endurecimento sem regressões demonstradas desta revisão.

Proposta para etapa própria: validar o build CSP com todos os controles e roundtrips Livewire, adicionar nonces imprevisíveis e política de scripts sem `unsafe-eval`/`unsafe-inline`, observar uma política Report-Only com coleta privada e retenção definida, depois impor a política validada. Vite de desenvolvimento deve ter origens de script/conexão explícitas separadas das de produção; não ampliar a política de produção para acomodá-lo. [Livewire CSP](https://livewire.laravel.com/docs/4.x/csp), [Alpine CSP](https://www.alpinejs.dev/advanced/csp).

O teste de navegador usa o middleware como fonte da política e o bundle Livewire instalado, em páginas sintéticas sem banco. Confere etapas Alpine, submissão na mesma origem e bloqueios de base/objetos/formulário externo. Não é homologação de todos os fluxos reais ou do modo CSP-safe. Cabeçalhos também foram testados em login, dashboard, JSON e resposta 403.

## MFA administrativo: viabilidade e decisões pendentes

MFA é viável em uma etapa específica, mantendo o guard/session e protegendo o desafio antes de liberar rotas/Livewire. Esta revisão não adiciona login alternativo, segredo TOTP, dependência de autenticação ou mudança de sessão incompatível.

A instituição deve decidir método (TOTP ou WebAuthn), obrigatoriedade por perfil, matrícula e confirmação, recuperação/perda de dispositivo, contas de emergência e responsáveis pelo reset. A implementação deverá guardar segredos criptografados, recovery codes com hash, limitar tentativas, auditar matrícula/reset e revogar sessões/remember tokens ao mudar fatores. Reset administrativo de senha não deve automaticamente dispensar MFA. Recuperação e governança do último administrador precisam de testes próprios. Não há evidência de política institucional que permita inferir esses comportamentos.

## CI, dependências e análise estática

- `composer audit --locked`: todas as dependências, incluindo desenvolvimento; advisory ou pacote abandonado relevante ao retorno padrão do Composer falha o passo. Sem ignores ou lista de exceções.
- `npm audit --audit-level=high`: inclui ferramentas de build, único tipo de dependência npm no projeto. Alta/crítica falham; baixa/moderada são exibidas no JSON completo no log e artefato e exigem triagem. Erro de ferramenta/rede também falha. Sem `--omit=dev`, `audit fix`, atualizações indiscriminadas ou supressões.
- Adicionado somente PHPStan dev, lock em **2.3.1**; nenhum pacote existente foi atualizado/removido. Node lockfile permanece igual. `composer validate --strict` passou.
- `composer check:static`: PHPStan nível 5, PHP alvo 8.4, todos os 18 arquivos Domain/Application. Sem bootstrap Laravel, Larastan, baseline, exclusões ou ignoreErrors. Execução sem erros. Probe externo temporário com argumento de tipo errado e método inexistente produziu dois erros/exit 1, demonstrando que o controle detecta regressões; não foi tratado como falha do código real.
- Não se afirma análise estática completa de Eloquent/controllers: seus tipos dinâmicos exigem avaliação própria de extensão compatível. Arquitetura e integração continuam cobrindo os adaptadores.
- Cobertura de linhas PCOV 1.0.12, fonte `app/`, Clover configurado para publicação no CI, sem limiar. SQLite mediu **91,96% (2.300/2.501 linhas)**, **83,92% dos métodos**. PCOV não mede branch/path coverage; porcentagem não certifica qualidade ou segurança. [PHPStan](https://phpstan.org/user-guide/getting-started), [PHPUnit 12](https://docs.phpunit.de/en/12.5/installation.html), [PCOV PHP 8.4](https://pecl.php.net/package/pcov/1.0.12).

## Comandos executados e resultados

Host sem PHP/Composer/Node; comandos efetivamente executados em Docker. Testes usam storage/cache únicos de `tests/bootstrap.php`, SQLite em memória e MySQL 8.4 em tmpfs, rede interna exclusiva, sem portas ou volumes operacionais.

| Comando | Resultado |
| --- | --- |
| `composer check:architecture` | 3 testes, 4 asserções; passou |
| `composer test:unit` | 51 testes, 97 asserções; passou |
| `composer check` | Pint 174 arquivos; SQLite 264 testes, 2.045 asserções; passou |
| `composer check:static` | Sem erros |
| `composer audit --locked` | Nenhum advisory |
| `composer validate --strict` | Manifest e lock válidos |
| `npm run build` | Passou, Vite 7.3.7 / Node 22 |
| `npm audit --package-lock-only --audit-level=high` | Zero vulnerabilidades |
| `vendor/bin/phpunit -c /tmp/phpunit.xml --do-not-cache-result`, MySQL | 264 testes, 2.045 asserções; passou, banco `contracts_security_test` |
| `composer test:coverage` na imagem PCOV | 264 testes, 2.045 asserções; Clover gerado, resultados acima |
| `python3 -m unittest discover -s tests/Operations -v` | 3 testes; isolamento, cleanup após falha e traversal rejeitado |
| `bash -n scripts/backup.sh scripts/verify-backup.sh` | Passou |
| `verify-backup.sh` com arquivo sintético externo ao repo | Passou; quatro tabelas vazias, storage/configuração fictícios, container removido |
| `node scripts/browser-security-headers.cjs` em Playwright 1.58.2 | Passou; controles Alpine e bloqueios CSP verificados |
| `git diff --check` | Passou |

No primeiro ensaio Playwright, o clique no formulário proibido esperava o fim de uma navegação que o CSP bloqueou. O teste passou a não aguardar navegação nesse clique; verifica explicitamente a violação e a permanência na página. Não se alterou o controle de segurança para acomodar o teste.

Reprodução dos comandos PHP sem executar o entrypoint operacional:

```bash
docker compose run --rm --no-deps --entrypoint composer app check:architecture
docker compose run --rm --no-deps --entrypoint composer app test:unit
docker compose run --rm --no-deps --entrypoint composer app check
docker compose run --rm --no-deps --entrypoint composer app check:static
docker compose run --rm --no-deps --entrypoint composer app audit --locked
docker compose run --rm --no-deps --entrypoint sh node -c 'npm ci && npm run build && npm audit --audit-level=high'
```

Cobertura local, após preparar a imagem e o vendor de desenvolvimento:

```bash
docker compose build app
docker build -f docker/php/coverage.Dockerfile -t contracts-coverage .
docker run --rm --network none -v "$PWD:/var/www/html" \
  -v contracts_php_vendor:/var/www/html/vendor:ro \
  --entrypoint composer contracts-coverage test:coverage
```

Reprodução do teste CSP de navegador, sem acesso a dados:

```bash
docker cp contracts-app-1:/var/www/html/vendor/livewire/livewire/dist/livewire.js /tmp/contracts-livewire.js
docker run --rm \
  -v "$PWD/scripts/browser-security-headers.cjs:/tmp/check/check.cjs:ro" \
  -v "$PWD/app/Http/Middleware/SecurityHeaders.php:/tmp/security-headers.php:ro" \
  -v /tmp/contracts-livewire.js:/tmp/livewire.js:ro \
  mcr.microsoft.com/playwright:v1.58.2-noble \
  bash -c 'cd /tmp/check && npm install --no-audit --no-fund playwright@1.58.2 && node check.cjs'
```

O nome do volume corresponde ao projeto Compose padrão `contracts`. A extensão existe apenas na imagem de testes. Relatório em `build/coverage/clover.xml` é ignorado pelo Git. O CI usa setup-php/PCOV e executa as mesmas suítes, mas não houve execução remota no GitHub nesta revisão.

## Arquivos alterados e limites restantes

Alterados `LoginController`, `SecurityHeaders`, testes `LoginTest`/`SecurityHeadersTest`, verificador de backup e testes Python operacionais, script Playwright CSP, workflow CI, composer manifest/lock, `phpstan.neon`, `phpunit.xml`, imagem de cobertura e `.gitignore`. Atualizados README, arquitetura e operação; este relatório registra a revisão.

Não foram executados backup/restore de dados reais, ensaio de recuperação externo, teste ofensivo externo, carga de produção, migration/deploy ou push. Checksums verificam integridade do conteúdo fornecido, não autenticidade contra alguém capaz de substituir o arquivo e seus checksums. Backup de banco e storage ocorre em instantes distintos: consistência operacional entre ambos exige janela/quiescência ou estratégia institucional. Backup externo, retenção, criptografia do arquivo, RPO/RTO e restauração da chave em infraestrutura nova continuam decisões operacionais.

Quando o banco não permite rotacionar o remember token no logout, a sessão/cookie local é encerrada e o erro é reportado; a invalidação persistente desse token não pode ser garantida durante a indisponibilidade. Downloads revalidam antes da resposta; bytes já enviados não são cancelados após revogação. Hash permanece linear nos contratos visíveis; planilhas/PDFs ainda consomem memória proporcional ao trabalho. Requisições distintas podem criar exportações distintas; a claim impede duplicar a geração da mesma exportação. Deduplicação global exige identidade de pedido/regra de reaproveitamento segura, não foi presumida. MFA e CSP completa permanecem etapas expressas acima. Nenhuma evidência permite declarar produção homologada ou auditoria de segurança exaustiva.
