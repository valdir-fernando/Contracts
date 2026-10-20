# Ordens de Serviço

A tabela atual é `tb_OS`, renomeada de `contract_service_orders` sem apagar dados. A origem legada foi importada com validação dos vínculos e proveniência: [procedimento e resultado](service-order-import-2026-10-20.md).

O botão **Emitir Ordem de Serviço**, ao lado de **Prazo da obra**, disponível para Serviços Diversos e Obras, no painel **5. Vigência**, abre a janela de Ordens de Serviço. O contrato precisa estar salvo. A edição de um aditivo salvo também oferece **Ordens de Serviço**, com esse aditivo selecionado inicialmente. A janela permite consultar as ordens e cadastrar novas, com Nº OS, Data OS, Início, Final, Período e observações opcionais. O vínculo pode ser o contrato original ou um aditivo ativo do mesmo contrato. Um contrato e cada um de seus aditivos podem ter várias ordens.

As ordens são documentais. Não recalculam valores nem vigência do contrato; o Período informado atualiza somente o campo textual Prazo da obra, com auditoria na mesma transação e não substituem `n_os`/`data_os` históricos nem o payload dos aditivos. Não há regra de unicidade de número imposta entre ordens. A lista é paginada em dez registros; números, datas, prazos, observações e identificação do aditivo são projeções explícitas, sem models ou snapshots pessoais na resposta. A exclusão lógica de um aditivo não apaga suas ordens anteriores; esse aditivo deixa de estar disponível para novos vínculos. Esta etapa oferece cadastro e consulta, sem edição ou exclusão de ordens.

## Escrita e segurança

`RegisterServiceOrder`, em Infrastructure, é o adaptador Laravel da operação. Usa `TransactionManager` e `ContractAccess::lockForSave`: bloqueia o ator antes do contrato, revalida atividade, sessão, policy e versão, valida os campos e bloqueia o aditivo dentro da mesma transação. O vínculo é consultado pelo contrato autorizado. Grava ordem, incremento da versão do contrato e `ContractAudit` com ação `service_order_created` de forma inseparável. Uma falha na auditoria reverte a ordem e a versão.

`GET /contracts/{contract}/service-orders` e `POST /contracts/{contract}/service-orders` exigem autenticação e permissão atual de atualização do contrato. O POST tem CSRF e limitação de frequência. As respostas JSON usam `private, no-store`. Conflitos retornam mensagem sem dados atuais do contrato.

A janela conserva os campos ainda não salvos do formulário principal. Somente uma gravação confirmada pode avançar a versão desse formulário, de N para N+1, quando ele ainda contém a versão N usada na requisição. Consultar a lista não atualiza versões. Conflitos mantêm os dados digitados e pedem recarregamento; falhas de comunicação pedem consultar a lista antes de tentar novamente, pois a gravação pode ter sido concluída no servidor. Dados retornados são inseridos na tabela com `textContent`.

## Instalação e validação

Migration aditiva, sem alterar contratos ou registros históricos:

```powershell
docker compose exec -T app php artisan migrate --path=database/migrations/2026_10_15_000002_create_contract_service_orders.php --force
```

Executado no MySQL local do serviço `db`. Somente essa migration foi aplicada; migrations anteriores pendentes não foram executadas. `view:clear` executado após a instalação.

Comandos efetivamente executados:

- PHPUnit filtrado em `ContractServiceOrdersTest`: 6 testes, 66 asserções, passou com SQLite em memória e storage/cache temporários.
- Pint nos arquivos PHP novos e no teste de contratos modificado: passou após formatação.
- `composer check:architecture`: 3 testes, passou.
- `composer test:unit`: 57 testes, passou.
- `composer check`: Pint e suíte completa passaram; 362 testes, 3251 asserções, 1 ignorado.
- `docker compose run --rm --no-deps node npm test`: 31 testes, passou.
- `docker compose run --rm --no-deps node npm run build`: passou.
- `git diff --check`: passou.

Ensaio com Playwright/Edge em modo headless usando o componente Blade real, contrato fictício e respostas HTTP simuladas: abertura, vínculo ao aditivo, cadastro, preservação do prazo do formulário principal, avanço de versão, texto sem interpretação de HTML, fechamento e Escape passaram em larguras de 1366 e 390 pixels. Não houve cadastro em banco real nesse ensaio nem teste de concorrência MySQL. Os testes de integração verificaram múltiplas ordens, escopos, sessão antiga, versão antiga, aditivos de outro contrato ou excluídos, rollback da auditoria e paginação.

## Painel e PDF — 20/10/2026

O topo apresenta Fundo, Fornecedor, Objeto e Contrato salvos. Início e Final são datas, e Final não pode anteceder Início. Período continua um texto explícito (por exemplo, 90 dias), sem cálculo automático ou mudança da vigência contratual. As datas são obrigatórias na janela; clientes antigos da API podem omitir ambas para preservar compatibilidade. Ordens antigas mantêm datas nulas. O Prazo da obra fica desabilitado na ficha; as rotas de gravação de contratos recusam alterações diretas desse campo. Ao salvar a OS, período, versão e auditoria são persistidos juntos; fechar a janela atualiza o resumo e prazo sem recarregar o restante do formulário.

O botão PDF da OS fica disponível após salvar. A lista oferece PDF de cada ordem. GET /contracts/{contract}/service-orders/{order}/pdf autoriza atualização do contrato, verifica vínculo da ordem, gera um PDF A4 com modelo específico para Obras ou Serviços e os dados salvos e devolve private, no-store. O adaptador ServiceOrderPdf captura projeções sob locks ator/contrato/ordem, renderiza fora da transação e reautoriza/revalida os dados antes da entrega e auditoria do hash. Não disponibiliza arquivos públicos nem inclui CPFs/RGs. O PDF usa os modelos elaborados do sistema para Obras e Serviços, com cabeçalho, quadros e assinaturas; veja [modelos e exemplos](service-order-models-2026-10-20.md).

Migration aditiva aplicada no MySQL local: php artisan migrate --path=database/migrations/2026_10_20_000001_add_service_order_execution_dates.php --force. Acrescenta somente starts_on e ends_on, anuláveis, preservando registros existentes.

Validação: npm test passou (105 testes); suites ContractServiceOrdersTest e ContractWritingTest passaram (45 testes, 744 asserções) antes da proteção adicional de Prazo da obra. composer check:architecture passou (3 testes, 4 asserções); composer test:unit passou (77 testes, 169 asserções); npm run build passou. Testes de integração usam SQLite e storage/cache isolados, com datas, PDF privado, escopos e auditoria. As primeiras execuções apontaram asserções de formato de data SQLite e payload de fixture antigo, corrigidas após conferir os casts e a compatibilidade. Sem ensaio manual autenticado em navegador.

Verificação posterior à proteção de Prazo da obra: 8 testes direcionados passaram (106 asserções). A suíte de OS foi repetida após o retorno explícito do resumo salvo: 8 testes, 88 asserções, passou, incluindo recusa do PDF após alteração durante a renderização. O resumo usa a OS confirmada pela gravação; ao reabrir o contrato, usa a última OS cadastrada por ID, mesmo quando sua Data OS é anterior à de outras ordens. O POST acrescenta order (number, issued_on e deadline) e pdf_url; o GET da lista acrescenta starts_on, ends_on e pdf_url. Clientes existentes mantêm os campos originais. Build e Pint repetidos com sucesso.

composer check concluído: Pint passou (303 arquivos); PHPUnit passou (630 testes, 6.203 asserções, 1 ignorado). O teste adicional de alteração durante a renderização do PDF foi executado separadamente e passou. Nenhum teste foi executado contra o MySQL instalado; apenas a migration aditiva foi aplicada nele.

## Janela compacta e lista do Fundo

A janela tem largura máxima de 1240px. O cabeçalho apresenta Nº Contrato, Fundo e Fornecedor na mesma linha; abaixo, Objeto ocupa toda a largura e três linhas de altura, com rolagem para textos maiores. Campos e tabela usam espaçamentos menores; em telas estreitas a grade se adapta.

A janela consulta GET /contracts/{contract}/service-orders?scope=current-fund-year. Esse modo lista todos os contratos visíveis ao ator no Fundo autorizado da sessão, filtrando o ano atual pela Data OS (issued_on) no relógio da aplicação. Não aceita Fundo ou ano arbitrários do cliente; sem Fundo selecionado, retorna 403. Ordena por ID decrescente (ordem de cadastro), para que a OS recém-salva apareça primeiro mesmo com Data OS anterior. Mantém paginação de dez registros. PDF e identificação do contrato usam o contrato de cada ordem; a seleção de aditivo para uma nova OS continua restrita ao contrato aberto. O modo sem scope preserva a listagem anterior por contrato.

Validação: ContractServiceOrdersTest passou (9 testes, 104 asserções), cobrindo ordens de outros contratos, outro Fundo, anos anterior/futuro, contrato excluído e Secretaria restrita. Os testes JavaScript de OS passaram (3 testes), Pint passou e npm run build passou. git diff --check passou. A montagem inicial do novo cenário de teste precisou corrigir a versão do model recém-criado e o vínculo many-to-many de AccessScope, após conferir schema/model; as regras de autorização foram preservadas. Sem ensaio visual autenticado em navegador.

A gravação já recarrega a primeira página da lista. Validação da ordenação por cadastro: dois testes de paginação/Fundo/ano passaram (36 asserções), incluindo uma nova OS com Data OS anterior às demais. Pint e git diff --check passaram. Não houve alteração de assets.
