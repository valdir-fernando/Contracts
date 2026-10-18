# Cadastro de Gestor do Fundo

A tabela física do cadastro é `tb_Gestor`. A migration `2026_10_18_000013_rename_contract_managers_to_tb_gestor` renomeia `contract_managers` sem copiar ou remover registros. IDs, índices, payloads, datas, exclusões lógicas, revisões e regra de assinatura são preservados. A estrutura Laravel permanece com `id`, `legacy_id`, `name`, chaves normalizadas e `raw_payload`; a renomeação não converte as colunas para o layout do dump legado. Se as duas tabelas já existirem, a migration recusa a operação para preservar ambas. As migrations históricas mantêm seus nomes originais; instalações devem executar `php artisan migrate --force` para aplicar toda a sequência. As rotas HTTP e o menu Gestor_Fundo permanecem iguais, e `tb_Gestor_Cont` continua sendo outro cadastro.

Validação da renomeação: `composer check` aprovou Pint e 503 testes (4.700 asserções; um teste MySQL ignorado por exigir schema descartável). Os 37 testes direcionados aprovaram migração, cadastro, assinatura, importação e cópia. Architecture (3 testes) e Unit (57 testes) passaram. A migration foi aplicada localmente e o hash SHA-256 de todos os registros ordenados foi idêntico antes/depois, preservando os oito cadastros. Testes específicos cobrem IDs, payload JSON original, datas, exclusões lógicas, sequência de IDs e colisão entre tabelas. O comando de cópia da Sara Kelly permaneceu idempotente após a renomeação. Foram executados `php artisan queue:restart` (sinal de reinício gracioso), `git diff --check` e consulta `/up` (HTTP 200). Não houve teste no banco instalado nem alteração em assets que exigisse novo build.

O identificador interno não é exibido como campo nem como coluna Código nas fichas/listas de Gestor, Fiscal e Fundo. IDs continuam preservados na persistência e nos vínculos. Validação desta alteração visual: 26 testes de cadastro/navegação aprovados, `npm run build` e `php artisan view:cache` concluídos; `git diff --check` sem erros.

CPF do gestor é obrigatório na criação e edição, com indicação visual, atributo HTML `required` e validação no servidor. Valores omitidos, vazios ou nulos são recusados sem alteração ou auditoria de sucesso; CPF informado continua sujeito à validação dos dígitos e duplicidade. Registros legados existentes não são regravados automaticamente. Validação: 18 testes de gestores/assinatura/navegação aprovados (134 asserções), Pint aprovado, templates compilados e suites Architecture/Unit aprovadas.

A ficha converte Nome do gestor para maiúsculas ao carregar e digitar, preservando a posição do cursor e a composição de caracteres. O servidor mantém a conversão antes de persistir. CPF recebe máscara e validação de obrigatoriedade/dígitos na interface, com mensagem acessível ao sair do campo ou salvar; Enter só avança ao RG quando válido. A validação nativa ou do submit abre a aba Dados do gestor para corrigir o CPF. O servidor também recusa caracteres estranhos ao formato do CPF em requisições diretas. Celular e WhatsApp usam as máscaras compartilhadas `(DD) NNNN-NNNN` e `(DD) NNNNN-NNNN`, inclusive para valores carregados e colados.

Validação da interação: `npm.cmd test` aprovou os 70 testes JavaScript, incluindo maiúsculas, composição, cursor, máscaras, CPF inválido/vazio e Enter. O comando `npm test` inicial foi bloqueado pela política de execução do PowerShell; o uso de `npm.cmd` resolveu sem alterar a política. Testes PHP de cadastro/assinatura, Pint, Architecture, build Vite e compilação dos templates passaram. Não houve migration, alteração em registros instalados ou ensaio visual em navegador.

As mensagens da ficha de gestor usam a caixa padrão de `system-message.js`, com título “Gestão Sistem informa:” e botão OK: CPF inválido/ausente, campos obrigatórios, email inválido e feedback de validação ou sucesso retornado pelo servidor. A validação nativa é interceptada para evitar balões do navegador. Mensagens de CPF não se repetem enquanto a mesma caixa está aberta; ao fechar, a aba do CPF é reaberta para correção. Os avisos de campo permanecem como indicação acessível. O feedback do servidor é embutido como JSON escapado e mostrado como texto. Validação: 73 testes JavaScript e 19 testes PHP de cadastro/assinatura/navegação aprovados, build e compilação de templates concluídos; nenhum ensaio visual de navegador nesta etapa.

O menu Cadastros → Gestor_Fundo abre a ficha administrativa em `/catalogs/list/manager-fund`, com abas Dados do gestor, Vínculo e decreto e Expandir Tudo, seguindo o cadastro de Fiscal. Novo limpa a ficha; Editar abre o registro selecionado; Salvar cria ou atualiza; Excluir exige confirmação e preserva o registro com `deleted_at`.

Campos da referência: Fundo, CPF, nome, RG/CIC, nascimento, sexo, endereço, celular, WhatsApp, email, cargo, cargo abreviado, decreto, data do decreto, matrícula e opção de assinatura dos contratos do Executivo. A ilustração de pessoa do legado não representa upload de foto e não foi transformada em campo de arquivo.

`Infrastructure/Managers/ManagerRegistration` revalida o administrador sob lock antes de bloquear os Fundos de origem/destino em ordem de ID e o registro. O lock compartilhado do Fundo serializa ativações feitas por administradores diferentes. Criação, edição e exclusão compartilham transação com auditoria. Revisão HMAC recusa formulários antigos. Campos omitidos e atributos legados desconhecidos são preservados; `null` explícito limpa campos válidos, preservando datas legadas não interpretáveis quando o formulário não informa substituição. CPF informado precisa ser válido; duplicidades ativas são recusadas. A lista não projeta CPF, contatos ou payload completo; a ficha administrativa utiliza projeção explícita e respostas privadas sem cache.

## Assinatura do Executivo

O controle fica sempre visível na seção Vínculo e decreto. A edição é habilitada quando o Fundo selecionado corresponde ao nome normalizado `MUNICIPIO DE NIQUELANDIA` e não existe gestor marcado, ou quando a ficha pertence ao próprio gestor já marcado, permitindo desativá-lo. Nas outras fichas, aparece desabilitado enquanto houver um ativo. Registros excluídos não bloqueiam a edição. A disponibilidade é recalculada ao abrir a ficha; a validação transacional no servidor continua impedindo segunda ativação, inclusive com página antiga ou requisição direta. O servidor recusa ativação para outros Fundos e zera a opção ao transferir o gestor para outro Fundo. Para substituir, desmarque o atual e marque o novo.

Validação da visibilidade/disponibilidade: 19 testes de cadastro, assinatura e navegação aprovados (153 asserções); Pint, compilação dos templates e `git diff --check` concluídos. Não houve alteração de schema ou dados instalados.

`ManagerSignatory` resolve nome, CPF, RG e cargo no cadastro do Fundo autorizado. Para o Município, utiliza o único registro ativo com `Assinatura=1`; sem nenhum marcado, utiliza o único cadastro com cargo Prefeito, Prefeito Municipal, Prefeita ou Prefeita Municipal. Gestores excluídos são ignorados. Sem Prefeito identificável ou com duplicidade de responsáveis no legado, a emissão é bloqueada com mensagem de revisão, sem escolher uma pessoa arbitrariamente. Na sugestão de novo contrato, a ausência de cadastro do Prefeito deixa os campos vazios e permite abrir a ficha; não há invenção de dados nem bloqueio retroativo de cadastros históricos incompletos.

Novos contratos usam a seleção na sugestão e na persistência transacional. Novos aditivos usam o responsável atual, inclusive quando o cliente envia outro nome; os snapshots dos contratos e aditivos existentes não são regravados. Extratos de Fornecimento e Publicações usam o responsável vigente na emissão e revalidam sua projeção antes da entrega; mudanças durante a renderização recebem conflito 409 e não publicam auditoria de emissão bem-sucedida. CPF continua mascarado para perfis sem acesso pessoal. Um fingerprint HMAC do responsável integra a auditoria da emissão.

O PDF de resumo informativo continua mostrando os dados históricos do contrato. Atas e instrumentos integrais de contratos/aditivos ainda não têm emissores próprios neste repositório; ao serem implementados, devem consumir `ManagerSignatory`, após autorização do documento e sob transação, com a mesma revalidação posterior à renderização. Não foram criados modelos documentais sem referência funcional.

Instalação: `php artisan migrate --force` aplica a migration aditiva `2026_10_18_000010_enable_manager_registration`, tornando `legacy_id` opcional e adicionando exclusão lógica. Não há remoção de dados. A consulta automática dos dados do gestor para novos contratos ignora excluídos e continua recusando vínculos ambíguos. Snapshots de contratos existentes e importadores são preservados.

Testes: `ManagerRegistrationTest` verifica criação, projeção privada, edição de legado, omissão versus null, conflito de revisão, confirmação de exclusão, resolução automática após exclusão, CPF, Fundo excluído, permissões, ator revogado e rollback por falha de auditoria. PHPUnit usa SQLite em memória e storage/cache temporários.

Validação executada no Docker:

- `composer check`: Pint aprovado; 467 testes, 4.415 asserções, um teste ignorado (concorrência MySQL, não disponível no SQLite).
- `composer check:architecture`: 3 testes aprovados.
- `composer test:unit`: 57 testes aprovados.
- PHPUnit direcionado a cadastro de gestores, navegação e Fundo selecionado: 17 testes aprovados. Após o ajuste de identificação da rota no menu, cadastro e navegação foram repetidos: 6 testes aprovados.
- `npm run build`: concluído.
- `php artisan migrate --force` e `php artisan view:cache`: concluídos no ambiente local.
- `/up`: HTTP 200; `git diff --check`: sem erros de whitespace.

Limites: não foi realizado ensaio visual em navegador nem teste de concorrência em MySQL descartável. Nenhum teste foi executado no banco da instalação e não houve publicação externa.

Validação da regra de assinatura do Executivo:

- `composer check`: Pint aprovado; 478 testes, 4.463 asserções, um teste de concorrência MySQL ignorado por exigir schema descartável explícito.
- `composer check:architecture`: 3 testes aprovados; `composer test:unit`: 57 testes aprovados.
- PHPUnit direcionado a escrita de contratos, fiscais e assinatura: 53 testes aprovados após corrigir o bloqueio indevido de fichas com cadastro do Prefeito ausente. Não foram alteradas as asserções dessas suites para ocultar a regressão.
- `ExecutiveSignatoryTest` cobre seleção e retorno ao Prefeito, segunda ativação por outro administrador, omissão do campo, transferência a outro Fundo, exclusão, ambiguidades legadas, ausência de Prefeito, novo aditivo com nome enviado pelo cliente, ambos os templates, mascaramento de CPF e mudanças durante renderização.
- `npm run build`, `php artisan view:cache` e `git diff --check`: concluídos; `/up`: HTTP 200.
- Não houve migration adicional nem alteração dos dados instalados nesta etapa. Validação visual em navegador e concorrência real em MySQL permanecem fora deste ensaio.
