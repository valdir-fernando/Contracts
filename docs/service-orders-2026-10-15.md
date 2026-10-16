# Ordens de Serviço

O ícone de adição dentro de **Prazo da obra**, no painel **5. Vigência**, abre a janela de Ordens de Serviço. O contrato precisa estar salvo. A edição de um aditivo salvo também oferece **Ordens de Serviço**, com esse aditivo selecionado inicialmente. A janela permite consultar as ordens e cadastrar novas, com número, data, prazo e observações opcionais. O vínculo pode ser o contrato original ou um aditivo ativo do mesmo contrato. Um contrato e cada um de seus aditivos podem ter várias ordens.

As ordens são documentais. Não recalculam valores, vigência ou prazo do contrato e não substituem `n_os`/`data_os` históricos nem o payload dos aditivos. Não há regra de unicidade de número imposta entre ordens. A lista é paginada em dez registros; números, datas, prazos, observações e identificação do aditivo são projeções explícitas, sem models ou snapshots pessoais na resposta. A exclusão lógica de um aditivo não apaga suas ordens anteriores; esse aditivo deixa de estar disponível para novos vínculos. Esta etapa oferece cadastro e consulta, sem edição ou exclusão de ordens.

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
