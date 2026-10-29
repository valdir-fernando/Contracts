# Gestor de Contratos

A barra Novo/Salvar inclui o botão com ícone **Decreto**, à esquerda. Como ainda não foi definido um modelo pelo usuário, o botão informa essa pendência na caixa padrão do sistema e não submete o cadastro nem emite um decreto sem conteúdo definido. A geração do documento permanece pendente de modelo/texto.

O menu Cadastros apresenta Fiscal_Contrato → Gestor_Contrato → Gestor_Fundo. Gestor_Contrato abre `/catalogs/list/contract-steward`, com título Gestor de Contratos e ficha derivada de Fiscal de Contratos: dados pessoais, contatos, situação, endereço, Fundo, Secretaria/Setor e decreto. Mantém abas, listagem com Editar, Novo, Salvar e Excluir com confirmação. Identificadores internos não são exibidos. Nome usa maiúsculas, CPF possui validação e máscara, e Celular/WhatsApp possuem máscaras com DDD. Datas seguem a exibição brasileira e persistência ISO do sistema.

O cadastro usa `contract_stewards` como projeção interna para a ficha e `tb_Gestor_Cont` para os campos equivalentes ao legado Fiscal, mantidos na mesma transação por `Infrastructure/ContractStewards/StewardLegacyTable`. `ContractStewardRegistration` cria, edita e exclui logicamente os dois registros com auditoria inseparável. `tb_Gestor` continua representando Gestor do Fundo. Uma cópia explicitamente solicitada pode criar um Gestor de Contratos a partir do cadastro do Fundo, sem compartilhar o registro nem alterar a origem. Não muda snapshots de contratos/aditivos nem participa da escolha de signatário do Executivo. Não há associação automática desse novo papel aos contratos sem regra funcional adicional. A antiga URL de compatibilidade `/catalogs/list/manager` permanece preservada; o novo menu aponta para a rota específica de Gestor de Contratos.

As rotas POST `/contract-stewards`, PUT/DELETE `/contract-stewards/{steward}` exigem administrador autenticado e revalidado sob lock. Ator, registro, Fundo/Secretaria, revisão HMAC, validação contextual, persistência e auditoria compartilham transação. Eventos `contract_steward_created`, `contract_steward_updated` e `contract_steward_deleted` identificam o cadastro próprio. A exclusão é lógica; campos omitidos, atributos legados desconhecidos e datas legadas não interpretáveis mantêm o comportamento do Fiscal. CPF é opcional como na ficha original, mas precisa ser válido quando informado. Secretaria é admitida somente para o Município. Fundo vinculado ao novo cadastro não pode ser excluído.

## Exclusividade de gestores por Fundo e Secretaria

Para MUNICIPIO DE NIQUELÂNDIA, a opção antes chamada Sem secretaria é Todas as Secretarias. O valor interno continua nulo (chave normalizada vazia), inclusive para cadastros anteriores como o da Sara Kelly: não há regravação automática de dados. Um gestor nessa condição impede outro gestor em qualquer Secretaria do Município. Quando houver gestores por Secretaria, não pode ser criado nem alterado outro para Todas as Secretarias. Cada Secretaria admite somente um registro não excluído. A troca de vínculo ou exclusão lógica libera a ocupação anterior. Editar o próprio registro na mesma Secretaria é permitido; omitir Secretaria na edição preserva o vínculo, enquanto null explícito solicita Todas as Secretarias.

Nos outros Fundos, Secretaria fica desabilitada e aparece como Não se aplica; o servidor recusa Secretaria recebida por requisição direta. Cada Fundo admite um único Gestor de Contratos, independentemente das vagas existentes no Município. Situação Inativo não libera a vaga: conforme a regra de cadastro, é necessário excluir ou mudar o vínculo.

`StewardAssignments` aplica a exclusividade tanto na criação/edição HTTP quanto na cópia por CLI. As mutações revalidam/bloqueiam o ator, bloqueiam Fundos de origem/destino em ordem de ID e depois o registro. Leituras de ocupação usam locks correntes dentro da transação e excluem o próprio registro em edições. O lock compartilhado do Fundo serializa tentativas de administradores distintos. Persistência em `contract_stewards`, sincronização da `tb_Gestor_Cont` e auditoria permanecem inseparáveis. Dados históricos conflitantes não são excluídos nem corrigidos silenciosamente. O teste de ambiguidades da busca continua cobrindo registros inconsistentes preexistentes, embora novos cadastros duplicados sejam recusados.

Mensagens de conflito de cadastro aparecem na caixa padrão do sistema ao reabrir o formulário, com os campos preservados para correção. Essa regra pertence somente a Gestor de Contratos; Fiscal e Gestor do Fundo não recebem essa restrição.

Validação da exclusividade: `composer check` aprovou Pint e 509 testes (4.765 asserções, um teste MySQL ignorado por exigir schema descartável). Architecture (3 testes), Unit (57 testes), os 27 testes direcionados (245 asserções) e `npm.cmd test` (80 testes JavaScript) passaram. `npm run build`, `php artisan view:cache` e `git diff --check` foram concluídos; `/up` retornou HTTP 200. Os casos novos verificam bloqueios nos dois sentidos de Todas as Secretarias, um gestor por Secretaria/Fundo, exclusão e transferência liberando vagas, omissão versus null na edição, registro Inativo, outro administrador e cópia por CLI. Não houve migration ou alteração manual dos registros instalados. Não foi realizado ensaio visual em navegador nem teste real de concorrência MySQL; os testes PHP utilizaram SQLite em memória e storage/cache isolados.

POST `/contract-stewards/lookup` valida CPF, revalida administrador e procura somente em `contract_stewards`; devolve apenas existência e URL da ficha. Não retorna CPF, RG, contatos ou payload pessoal. Excluídos são ignorados e duplicidades recebem 409. A interface aguarda a consulta antes de salvar, descarta respostas antigas e apresenta aviso na caixa padrão do sistema. A ficha projeta explicitamente os campos permitidos e a lista omite os dados pessoais; respostas são privadas sem cache.

Instalação local: `php artisan migrate --force` aplica a migration aditiva `2026_10_18_000011_create_contract_stewards`. Nenhum registro existente é alterado. A reversão preserva a nova tabela e seu histórico.

## Tabela tb_Gestor_Cont e cópia do cadastro de origem

A migration aditiva `2026_10_18_000012_create_gestor_cont_table` cria a tabela e preenche os registros correspondentes aos Gestores de Contratos já cadastrados. Mantém os 14 campos de `tb_Fiscal`, trocando `ID_Fiscal` por `ID_Gestor_Cont` e os sufixos de CPF/RG/Nome para Gestor: `ID_Gestor_Cont`, `CPF_Gestor`, `RG_Gestor`, `Data_Nasc`, `Sexo`, `Nome_Gestor`, `Email`, `Fundo`, `Secretaria`, `Decreto`, `Data_Decreto`, `Celular`, `WhatsApp` e `Cod_Prodata`. Acrescenta vínculo único com `contract_stewards`, timestamps e exclusão lógica. Campos desconhecidos admitem null, sem valores inventados. A reversão preserva a tabela.

O comando `php artisan contracts:copy-contract-steward "NOME COMPLETO" --fund="NOME DO FUNDO"` utiliza o registro importado de `tb_Gestor` mantido em `tb_Gestor`. Nome e Fundo devem identificar registros únicos e ativos. A cópia valida CPF, mapeia `Fone_Celular` para `Celular`, converte datas reconhecidas para ISO e preserva endereço nos dados internos. Não copia Cargo, Assinatura ou Matrícula para campos de outro significado; `Cod_Prodata` ausente continua null. O vínculo criado abrange o Fundo informado, sem Secretaria presumida.

A execução é transacional, com locks do Fundo e da origem, `ImportRun` e evento `contract_steward_imported`. A identidade de CLI é explicitamente de sistema (`actor_id=null`), com motivo e ID de execução na auditoria, sem atribuir autoria a um administrador arbitrário. Repetir o comando para a mesma pessoa/Fundo não duplica dados nem regrava a ficha. Origens ambíguas, CPF inválido ou destino divergente/excluído são recusados.

Por solicitação do usuário, a cópia de SARA KELLY DA COSTA SOUSA para MUNICIPIO DE NIQUELÂNDIA foi executada no ambiente local. A origem importada de `tb_Gestor` permanece inalterada. Não houve regravação em massa dos contratos existentes nem mudança no signatário do Executivo. No momento dessa cópia, os dados de origem estavam em `contract_managers`; a migration posterior `2026_10_18_000013_rename_contract_managers_to_tb_gestor` renomeou essa tabela física para `tb_Gestor`. O cadastro de Gestor de Contratos permanece separado em `contract_stewards`/`tb_Gestor_Cont`.

Validação da tabela e cópia: `composer check` aprovou Pint e 500 testes (4.682 asserções; um teste MySQL ignorado por exigir schema isolado). Após acrescentar casos de origem ausente/ambígua, foram aprovados os 21 testes direcionados de cópia/cadastro (180 asserções). Architecture (3 testes) e Unit (57 testes) passaram. A migration e o comando de cópia foram executados no ambiente local; repetir o comando confirmou que não há duplicação. A conferência dos campos de identidade, datas, email, decreto e contatos retornou correspondência com a origem, sem imprimir dados pessoais. Auditoria e cadastro ativo foram confirmados; `/up` retornou HTTP 200 e `git diff --check` não encontrou erros. Não foram executados testes no banco da instalação; somente a migration aditiva, o cadastro solicitado e leituras de conferência. Não houve build adicional por não existir mudança em assets nesta etapa.

Testes específicos cobrem criação, edição, exclusão, revisão desatualizada, preservação de atributos, validação de CPF/contexto/datas, administrador revogado, rollback de auditoria, busca sem exposição pessoal, ordem do menu, separação de Fiscal/Gestor do Fundo e proteção do Fundo vinculado. Testes JavaScript verificam máscara/validação e busca assíncrona, incluindo descarte de respostas fora de ordem.

Comandos executados no Docker, salvo os testes JavaScript locais:

- PHPUnit direcionado a Gestor de Contratos, Fundo, assinatura do Executivo e Fiscal: 46 testes aprovados, 407 asserções.
- `npm.cmd test`: 80 testes JavaScript aprovados.
- `composer check:architecture`: 3 testes aprovados; `composer test:unit`: 57 testes aprovados.
- `composer check`: Pint aprovado; 494 testes, 4.638 asserções, sem falhas; um teste de concorrência MySQL ignorado por exigir schema descartável explícito.
- `npm run build`: concluído; `php artisan view:cache`: concluído.
- `php artisan migrate --force`: migration aditiva aplicada no ambiente local, preservando os demais cadastros.
- `php artisan route:list --path=contract-stewards`: quatro rotas de gravação/busca registradas; `/up`: HTTP 200; `git diff --check`: sem erros.

Os testes PHP usam SQLite em memória e armazenamento/cache temporários. Não houve teste no banco instalado, publicação externa ou validação visual em navegador. Concorrência real em MySQL depende de schema descartável explícito.

### Altura dos campos — 29/10/2026

O formulário Gestor de Contratos compartilha as regras de altura de Novo contrato para inputs, seletores, endereço de uma linha e botão de calendário. A altura padrão é 34,8px, com o mesmo ajuste responsivo de 39,6px em telas de até 600px. O endereço mantém a expansão automática existente para conteúdos maiores.

Validação: `docker compose run --rm --no-deps node npm run build` concluído e `git diff --check` sem erros. Alteração somente em CSS; sem nova execução de PHPUnit ou conferência visual autenticada no navegador.
