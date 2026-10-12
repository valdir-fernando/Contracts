# Auditoria de verificação — 12/10/2026

> Snapshot dos problemas antes das correções. Os sete pontos foram tratados na [etapa de correção e validação](audit-corrections-2026-10-12.md); as reproduções abaixo descrevem a árvore anterior.

## Resultado e escopo

Foram confirmados sete achados, sendo seis no código/comportamento e um no ambiente local. Os primeiros dois têm prioridade alta. A suíte existente passa, mas não cobre os cenários adversariais reproduzidos abaixo.

Inspeção da árvore de trabalho atual, incluindo alterações locais preexistentes. Leituras: README, arquitetura, regras do repositório, auditorias anteriores, rotas, policies, middleware, casos de uso, mapper, validação, serviços de aditivos/financeiro/revisão, relatórios/jobs, documentos, administração de usuários, importadores, configuração, Docker, backup e testes relacionados. Esta é uma inspeção direcionada, não uma certificação completa nem uma análise de cada linha do projeto.

Não foram corrigidos os comportamentos neste trabalho. Nenhuma migration, restauração, reinicialização de serviços, commit ou publicação foi executada. Os testes usam SQLite em memória e storage/cache temporários, com dados fictícios. Alterações existentes foram preservadas. O build atualizou somente os assets gerados em `public/build`, ignorados pelo Git.

## A1 — Alta: campos protegidos podem ser apagados com valores vazios

**Locais:** `app/Infrastructure/Contracts/LaravelContractInputValidator.php:43` e `:96`; `app/Services/AmendmentWriter.php:47` e `:82`; `app/Services/FinancialRecordWriter.php:43` e `:73`.

A regra `prohibited` aceita campos vazios, inclusive `null`. Ela não transforma esses campos em uma lista de atributos graváveis. Os loops posteriores reaplicam os valores validados sem excluir todos os campos protegidos.

**Reproduções confirmadas:**

- Usuário comum com acesso ao contrato envia `version=1`, motivo válido, `cpf_gestor=null` e `fornecedor_documento_snapshot=null`. A escrita conclui, incrementa a versão e apaga ambos os documentos, apesar de serem protegidos para esse ator.
- Na criação de aditivo por usuário comum, `fields.CPF_Gestor=null` e `fields.Contrato=null` sobrescrevem os snapshots obtidos do contrato.
- Na criação de ficha, `fields.Fundo=null` e `fields.CPF_CNPJ=null` sobrescrevem os campos somente leitura do snapshot.

Não foi demonstrada leitura de dados pessoais ou acesso a contratos fora do escopo por esses payloads; o problema confirmado é alteração não permitida de campos e perda de integridade dos snapshots.

**Correção:** construir uma lista explícita de campos graváveis conforme ator e contexto, excluindo protegidos/derivados/somente leitura antes de aplicar qualquer patch. Se a presença desses campos deve ser rejeitada mesmo vazia, validar presença proibida explicitamente. Preservar a distinção entre omissão e `null` nos campos realmente editáveis.

**Critério de aceitação:** cobrir omissão, `null`, string vazia e array vazio em contratos, aditivos e financeiro; campos protegidos existentes e snapshots iniciais devem permanecer intactos. Conferir também chamadas diretas aos serviços.

A afirmação em `docs/architecture.md` de que campos pessoais protegidos não entram no patch gravável precisa ser ajustada ou se tornar verdadeira com a correção. Não usar essa documentação como evidência de que o controle já funciona.

## A2 — Alta: importação histórica modifica contratos sem versão e auditoria

**Local:** `app/Services/Legacy/HistoryImporter.php:79`.

A importação de aditivos executa um update de `vigencia_em_revisao=true` nos contratos candidatos sem incrementar `version` ou registrar `ContractAudit`. Os registros de importação preservam a origem, mas não substituem a auditoria da mutação do contrato. Isso também deixa formulários antigos com uma versão aparentemente atual após a alteração.

**Reprodução:** importar um aditivo sintético que corresponde a fornecedor/fundo/número do contrato. O contrato passa de sem revisão para em revisão; sua versão permanece igual e nenhuma auditoria de contrato é criada. Com relógio fixo e atualização/importação no mesmo segundo, `ReportExport::accessHash` permanece igual: o hash observa versão e timestamp, e nenhum dos dois distingue essa mudança nesse cenário.

**Correção:** proteger as alterações com locks apropriados e ordem consistente; definir a identidade de sistema/importação explicitamente; gravar a transição e a auditoria na mesma transação; incrementar versão quando houver mudança relevante. Preservar soft delete, origem bruta, idempotência e ausência de efeitos financeiros automáticos.

**Critério de aceitação:** versão e auditoria da transição, rollback conjunto, conflito de edição após importação e invalidação de exportação mesmo com relógio fixo no mesmo segundo. Validar concorrência posteriormente em MySQL descartável; SQLite não comprova o comportamento dos locks.

## A3 — Média: formulário antigo de usuário restaura permissões substituídas

**Local:** `app/Http/Controllers/UserController.php:128` e `:157`.

O fluxo bloqueia o usuário atual e protege o último administrador, porém não compara uma versão do alvo com a versão vista no formulário. Incrementar `session_version` após a escrita não detecta que o payload foi baseado em dados antigos.

**Reprodução:** dois administradores diferentes. O segundo troca o escopo A do usuário pelo escopo B. O primeiro envia o formulário antigo, alterando somente o nome na sua intenção, mas incluindo o escopo A original. Ambas as requisições concluem e A é restaurado silenciosamente.

O problema exige um administrador autorizado; não é um bypass de autorização por usuário comum. É perda de atualização com consequência sobre permissões.

**Correção:** transportar e comparar uma revisão do usuário alvo dentro da transação, recusando edição desatualizada com conflito reautorizado. Uma revisão é controle de concorrência, não fonte de autoridade. Preservar a ordem especial dos locks de administradores.

**Critério de aceitação:** dois administradores com formulários antigos não podem sobrescrever escopos/perfil/atividade sem reconhecer o estado atualizado; manter testes de último administrador e rollback.

## A4 — Média: exportação alterada em disco continua disponível para download

**Locais:** `app/Http/Controllers/ReportController.php:82`; `app/Jobs/GenerateReport.php`.

O download compara autorização, hash do estado de acesso, status, expiração e existência do arquivo. Não há digest persistido dos bytes gerados nem comparação no download. O `access_hash` é um fingerprint de acesso/contratos, não do conteúdo do arquivo.

**Reprodução:** criar uma exportação pronta válida com arquivo privado; substituir seus bytes mantendo o caminho. O endpoint retorna 200 e entrega o conteúdo substituído. Anexos já têm uma verificação SHA-256, mas exportações não têm o mesmo controle.

Não foi demonstrada capacidade de um usuário remoto de escrever no disco. O cenário cobre corrupção, substituição operacional e falha do controle de integridade exigido pelo repositório.

**Correção:** persistir SHA-256/tamanho após geração bem-sucedida e verificá-los antes da entrega, mantendo autorização e expiração. Tratar exportações antigas sem digest de modo explícito, preservando seus metadados. Não publicar arquivo como pronto antes de concluir sua gravação.

**Critério de aceitação:** alteração/truncamento/substituição do arquivo bloqueia download; arquivo intacto continua disponível; falha não registra entrega bem-sucedida.

## A5 — Média: exportações abandonadas ficam em processamento indefinidamente

**Locais:** `app/Jobs/GenerateReport.php:28`; `app/Console/Commands/PruneReportExports.php:16`.

A claim impede duplicação, mas uma nova entrega retorna imediatamente quando o status é `processing`. Se o processo morrer sem executar `failed()`, não há recuperação. A limpeza só marca como expiradas exportações que têm `path`; durante geração o caminho ainda não é persistido.

**Reprodução:** exportação em `processing`, sem caminho, expirada há um dia; executar `contracts:prune-exports`. O status permanece `processing`. O mesmo filtro também deixa metadados pendentes expirados sem uma transição explícita.

**Correção:** expirar metadados vencidos independentemente da existência de arquivo; estabelecer uma política de detecção/retomada ou falha de processamento abandonado com lease/identificador de tentativa. Preservar a claim e impedir que um processo antigo publique o resultado de uma tentativa substituída.

**Critério de aceitação:** morte abrupta, entrega duplicada, expiração sem arquivo e tentativa antiga terminando após recuperação. Este risco já constava da auditoria anterior e permanece aberto.

## A6 — Baixa: senha vazia na edição de usuário produz erro 500

**Local:** `app/Http/Controllers/UserController.php:89` e `:163`.

É outra ocorrência da aplicação de valores aceitos por `prohibited`. `password=''` chega como `null` após o middleware e permanece no array usado em `fill`. A gravação tenta colocar `NULL` em `users.password`, violando a constraint.

**Reprodução HTTP:** edição administrativa válida com `password=''` retorna 500. A transação reverte e preserva senha/versão de sessão; não foi confirmada troca por senha vazia no endpoint.

**Correção:** lista explícita dos atributos editáveis, excluindo senha nessa operação; preservar o endpoint dedicado de redefinição e suas regras. Retornar validação apropriada se a presença do campo deve ser rejeitada.

**Critério de aceitação:** senha omitida, vazia ou nula não causa 500 nem muda credenciais na edição cadastral.

## A7 — Média operacional: ambiente local indisponível e worker reiniciando

**Evidência:** `docker compose ps -a` mostra `db`, `app` e `web` parados; worker em `Restarting`; scheduler ativo. `docker compose logs --tail 25 worker` mostra repetidamente `Worker STOPPED Lost connection`.

A indisponibilidade do banco é consistente com os logs, mas não foi investigado por que os serviços foram parados. Isso pode ser uma parada deliberada; não é prova de defeito na aplicação. O healthcheck/dependência do Compose não mantém automaticamente o banco ativo quando um worker já iniciado reinicia.

**Correção operacional quando o ambiente deve estar ativo:** recuperar o conjunto de serviços, confirmar saúde do banco/conexão do worker, verificar fila e jobs abandonados. Definir monitoramento que detecte ausência de processamento, não apenas existência do container. Nenhum serviço foi reiniciado nesta auditoria.

## Verificações e evidências

| Comando executado | Resultado |
| --- | --- |
| `docker compose run --rm --no-deps --entrypoint composer app check` | Na inspeção inicial desta sessão, antes desta auditoria: Pint aprovado em 165 arquivos; 226 testes e 1.775 asserções aprovados. Não houve alteração de código entre essa execução e as reproduções. |
| `docker compose run --rm --no-deps --entrypoint php -v /tmp/ContractsAuditProbeTest.php:/tmp/ContractsAuditProbeTest.php:ro app vendor/bin/phpunit -c phpunit.xml /tmp/ContractsAuditProbeTest.php --do-not-cache-result` | Oito reproduções, 23 asserções, aprovadas na execução final. Elas afirmam os comportamentos vulneráveis observados; não são testes de aprovação das futuras correções. |
| `docker compose run --rm --no-deps --entrypoint composer app audit --locked --no-interaction` | Nenhum advisory reportado. |
| `docker compose run --rm --no-deps --entrypoint sh node -c 'npm run build && npm audit --package-lock-only'` | Build aprovado com Vite 7.3.7; zero vulnerabilidades reportadas pelo npm. |
| `docker compose ps -a` e `docker compose logs --tail 25 worker` | Estado e logs descritos em A7. |
| `git diff --check` | Aprovado. |

O arquivo de reprodução permanece em `/tmp/ContractsAuditProbeTest.php` para inspeção nesta máquina, fora da suíte/repositório. Antes de corrigir, converter os cenários em testes permanentes que exijam o comportamento seguro.

Durante a elaboração das sondas, uma hipótese de senha vazia gravada falhou: a exceção foi investigada e confirmou `NOT NULL` com rollback; a sonda final verifica esse resultado. A primeira sonda de importação comparou a versão ainda não recarregada do model recém-criado (`null`) com o default persistido (`1`); a leitura foi corrigida com `refresh()` antes da comparação. Nenhuma asserção da suíte existente foi alterada.

Não foram executados nesta auditoria: nova suíte MySQL, ensaio concorrente em banco, navegador, carga representativa, restauração de backup ou validação com dump real. Auditorias de dependências sem advisories não comprovam ausência de falhas no código. As regras financeiras/jurídicas pendentes e a migração gradual dos demais módulos permanecem decisões funcionais/arquiteturais, não foram classificadas como defeitos por si só.

## Ordem de correção

1. Campos protegidos/snapshots e atributo senha (A1/A6).
2. Importação histórica com versão, locks e auditoria (A2).
3. Concorrência na administração de usuários e integridade das exportações (A3/A4).
4. Expiração/recuperação dos jobs e observabilidade operacional (A5/A7).
