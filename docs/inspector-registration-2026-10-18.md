# Cadastro de fiscal de contrato

O menu **Cadastros → Fiscal** mantém `/catalogs/list/inspector` e passa a abrir uma ficha no padrão de Novo contrato: painéis compactos, abas Dados do fiscal/Vínculo e decreto, Expandir Tudo e barra Novo/Salvar. A lista paginada abaixo permite abrir um fiscal para edição. A tela é responsiva e exibe erros de validação com os dois painéis abertos.

Os campos da referência são CPF, RG/CIC, nascimento, sexo, nome, celular, WhatsApp, decreto, data do decreto, endereço, Fundo e Secretaria/Setor. Código é gerado pelo servidor. Contatos usam as chaves legadas `Celular` e `WhatsApp`; endereço usa `Endereco`, sem adicionar coluna à tabela importada. Somente a ficha selecionada projeta os campos pessoais permitidos; a listagem não inclui CPF, RG ou payload completo. Respostas são `private, no-store` e exigem administrador.

`POST /inspectors` e `PUT /inspectors/{inspector}` delegam a `Infrastructure/Inspectors/InspectorRegistration`. Na transação, bloqueiam/revalidam primeiro o ator, depois o fiscal, validam os campos e o contexto de Fundo/Secretaria, bloqueiam os órgãos e persistem junto ao evento de auditoria. A revisão HMAC detecta edição concorrente com 409 antes de qualquer escrita, sem retornar os dados atuais. CPF preenchido exige dígitos verificadores válidos. Secretaria é aceita somente para o Município de Niquelândia. Campos legados desconhecidos, identidade de importação e snapshots dos contratos são preservados. Datas legadas incompatíveis com o controle de data permanecem no payload se o campo continuar vazio. A auditoria registra os campos alterados e o vínculo anterior/novo, sem copiar dados pessoais ao evento.

O botão Excluir na barra de ações fica disponível ao editar um fiscal salvo. Uma janela identifica o fiscal, permite cancelar e exige confirmação explícita. A exclusão é lógica e preserva dados importados e históricos dos contratos.

## Instalação

Executar somente a migration específica:

```bash
php artisan migrate --path=database/migrations/2026_10_18_000001_allow_native_contract_inspectors.php --no-interaction
```

Ela torna `legacy_id` opcional para cadastros nativos, preservando IDs, unicidade dos valores legados e dados existentes. O rollback não volta a exigir esse campo, pois excluiria a possibilidade de preservar os registros nativos. Foi aplicada no ambiente Docker disponível.

## Validação

- `docker compose exec -T app php vendor/bin/phpunit --filter 'InspectorRegistrationTest|CatalogNavigationTest|InspectorDefaultsTest' --do-not-cache-result`: 12 testes, 143 asserções, sucesso.
- `docker compose exec -T app composer check:architecture`: 3 testes, 4 asserções, sucesso.
- `docker compose exec -T app composer test:unit`: 57 testes, 129 asserções, sucesso.
- `docker compose run --rm --no-deps node npm run build`: sucesso.
- `docker compose exec -T app composer check`: Pint aprovado em 247 arquivos; PHPUnit com 437 testes, 4.098 asserções e 1 teste ignorado, sem falhas.
- Após os ajustes finais de auditoria e aviso de datas legadas: Pint dos arquivos alterados aprovado; `phpunit --filter InspectorRegistrationTest` com 4 testes/31 asserções e `phpunit --filter 'InspectorRegistrationTest|CatalogNavigationTest'` com 6 testes/53 asserções, ambos sem falhas.

Os testes de integração usam SQLite em memória e storage/cache temporários. Cobrem criação nativa, edição, preservação do payload legado, conflito de revisão, CPF, contexto de secretaria, recusa a não administradores e rollback quando a auditoria falha. Validação visual interativa no navegador ainda não realizada.

## Compactação da ficha

O título de cadastro e edição passa a ser **Fiscal de Contratos**. O marcador `data-inspector-form` compartilha a regra de altura dos controles de Novo contrato, sem ativar os comportamentos JavaScript próprios dos contratos. Altura renderizada: 34,8 px no desktop e 39,6 px até 600 px de largura. Nome e endereço mantêm largura total; os demais campos usam quatro colunas acima de 1000 px, duas até 1000 px e uma até 600 px. Sexo fica junto dos contatos para reduzir espaços vazios. Títulos, painéis, barra de ações e lista têm espaçamentos menores, com estilos restritos à ficha de fiscais.

Aproximação adicional do cabeçalho: margem inferior do cabeçalho de 8 px somente na tela de fiscais (antes 32 px no desktop), e margem abaixo do título de 8 px (antes 12 px). Build de assets e `git diff --check` executados novamente com sucesso para esse ajuste de CSS.

Validações desta alteração:

- `docker compose exec -T app php vendor/bin/phpunit --filter 'InspectorRegistrationTest|CatalogNavigationTest' --do-not-cache-result`: 6 testes, 52 asserções, sem falhas.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `git diff --check`: aprovado.
- Edge headless com Playwright e HTML renderizado em SQLite em memória, com dados fictícios: larguras 1440, 768, 390 e 320 px; alturas dos 12 controles visíveis iguais à referência de Novo contrato, quatro/duas/uma coluna conforme o breakpoint, sem transbordamento horizontal nem erros JavaScript. Capturas geradas em diretório temporário e conferência visual da versão desktop. A interação com abas foi verificada; não houve gravação no banco instalado.

## Máscaras, endereço e linha de órgãos

CPF usa máscara progressiva `000.000.000-00`; Celular e WhatsApp reutilizam a máscara de telefone com DDD para dez/onze dígitos. O módulo da ficha aplica as máscaras ao carregar, digitar e colar, usando `attachMask`. A validação de CPF no servidor permanece ativa.

Endereço inicia com uma linha e ajusta sua altura ao conteúdo, inclusive ao reduzir texto, mudar a largura ou reabrir a aba. Fundo e Secretaria ficam juntos em uma linha exclusiva de duas colunas, abaixo do decreto e da data, e empilham em celulares.

Validação: build aprovado; `npm test` com 49 testes aprovados antes da inclusão do novo cenário de CPF; `node --test tests/JavaScript/input-masks.test.mjs` com 5 testes aprovados após essa inclusão. Os testes PHP de cadastro/navegação passaram com 6 testes e 52 asserções. Edge headless com fixture SQLite em memória, nas larguras 1440, 768, 390 e 320 px, confirmou máscaras, expansão/redução do endereço, troca de abas, linha exclusiva de órgãos, altura dos controles e ausência de transbordamento ou erros JavaScript. `git diff --check` aprovado.

Nome do fiscal: a ficha converte o conteúdo para maiúsculas ao abrir, digitar e colar, preservando acentos. O adaptador também converte antes da validação e da gravação, garantindo `name` e `raw_payload.Nome_Fiscal` em maiúsculas mesmo sem JavaScript. Cadastros importados são normalizados na próxima gravação da ficha, sem regravação em massa. Validação: testes de cadastro/navegação com 6 testes e 56 asserções (incluindo criação e edição com acentos), Pint dos dois arquivos PHP alterados, build e `git diff --check` aprovados.

## Situação do fiscal

O seletor **Situação** oferece **Ativo/Inativo** após WhatsApp, ocupando a quarta coluna da mesma linha no desktop (Sexo, Celular, WhatsApp, Situação). Em telas menores segue a grade responsiva. A escolha é validada e gravada em `raw_payload.Situacao` com a auditoria habitual, sem migration. Novos registros assumem Ativo; registros legados sem a chave continuam ativos e recebem o valor ao serem salvos. Omissão do campo na edição preserva a escolha anterior.

`InspectorCatalog` considera somente Ativo ou ausência da chave para preenchimento automático. Inativar não altera snapshots dos contratos já gravados; a regra de preservação do histórico permanece. Reativar volta a permitir o preenchimento, sujeito às regras de escopo e unicidade existentes.

Validação: `docker compose exec -T app php vendor/bin/phpunit --filter 'InspectorRegistrationTest|InspectorDefaultsTest|CatalogNavigationTest' --do-not-cache-result` passou com 14 testes/164 asserções; Pint dos quatro arquivos PHP alterados e build aprovados. Os testes incluem estado padrão, persistência/omissão, validação, recusa do inativo em contratos novos, reativação e preservação do contrato histórico. Não houve nova conferência visual em navegador nesta alteração.

## Exclusão lógica

`DELETE /inspectors/{inspector}` exige administrador atual, revisão HMAC e `confirmation` aceita. O adaptador bloqueia ator e fiscal, valida revisão/confirmação e grava `deleted_at` junto ao evento `inspector_deleted` na mesma transação. Falha na auditoria reverte a exclusão. Fiscal excluído retorna 404 para edição/alteração/nova exclusão e sai da listagem e da resolução automática. Importações preservam a linha existente e não desfazem a exclusão; snapshots dos contratos não são alterados. Nesta etapa não há ação de restauração na interface.

Migration aditiva aplicada: `docker compose exec -T app php artisan migrate --path=database/migrations/2026_10_18_000002_add_inspector_soft_delete.php --no-interaction`. Adiciona somente `deleted_at`, sem remover registros; rollback preserva o histórico.

Validação: `docker compose exec -T app php vendor/bin/phpunit --filter 'InspectorRegistrationTest|InspectorDefaultsTest|CatalogNavigationTest' --do-not-cache-result`: 17 testes, 188 asserções, sucesso. `composer check:architecture`: 3 testes/4 asserções, sucesso. Pint dos seis arquivos PHP envolvidos e `git diff --check` aprovados. Testes SQLite em memória verificam confirmação, revisão antiga, autorização, preservação do payload, recusa de edição após exclusão, rollback de auditoria e histórico dos contratos. Sem alteração de assets e sem nova validação visual nesta etapa.

## Validação imediata do CPF

Ao sair do CPF preenchido ou completar onze dígitos, a ficha verifica tamanho, sequência repetida e ambos os dígitos verificadores, reutilizando `validSupplierDocument` com restrição a onze dígitos. Mostra erro acessível no campo e bloqueia submissão inválida; se a aba estiver oculta, abre Dados do fiscal e devolve o foco ao CPF. Corrigir o CPF limpa a mensagem. Campo vazio mantém o comportamento opcional existente. A validação obrigatória no servidor para valores preenchidos permanece independente do JavaScript. Nenhuma consulta a serviços externos é feita.

Validação: `node --test tests/JavaScript/*.test.mjs` com 52 testes aprovados (dois novos cenários de CPF); PHPUnit `--filter InspectorRegistrationTest` com 7 testes/63 asserções aprovados; build e `git diff --check` aprovados. Os cenários cobrem CPF válido, repetido, incompleto, dígito incorreto, CNPJ, correção e campo vazio. Não houve nova validação no navegador nesta alteração.

Aviso de CPF em caixa própria: a ficha reutiliza o diálogo padrão de fornecedores (`Gestão Sistem informa:`, botão OK e estilos `catalog-modal supplier-document-warning`). CPF inválido ao sair do campo ou salvar abre a caixa, mantendo a mensagem acessível no campo e o bloqueio da gravação. O mesmo valor não reabre o aviso repetidamente sem edição; ao fechar a caixa, Dados do fiscal é aberto e o foco retorna ao CPF. Testes JavaScript: 52 aprovados, incluindo mensagem, ausência de avisos duplicados e CPF válido/vazio; build e `git diff --check` aprovados. Sem nova conferência visual nesta alteração.

## Enter no CPF

Enter no campo CPF valida apenas o CPF, impede submissão implícita da ficha e, estando válido, avança o foco ao RG. CPF inválido mantém o aviso padrão. Isso evita que a correção do CPF seguida de Enter envie Nome/Fundo ainda vazios e apresente erros gerais sem relação com o documento. A gravação pelo botão Salvar continua com as validações existentes. O alerta redundante da ficha foi removido; erros retornados pelo servidor continuam no resumo geral com os detalhes e nos campos correspondentes.

Regressão reproduzida em teste: CPF inválido → Enter → aviso → correção → Enter → foco no RG sem envio. `node --test tests/JavaScript/*.test.mjs`: 53 testes aprovados. PHPUnit `--filter 'InspectorRegistrationTest|CatalogNavigationTest'`: 9 testes/84 asserções aprovados. Build e `git diff --check` aprovados. Sem nova execução de navegador nesta alteração.

## Busca de fiscal pelo CPF

Ao sair de um CPF válido (incluindo Enter para avançar ao RG), a ficha consulta `POST /inspectors/lookup`. Havendo um único fiscal, abre a própria ficha existente em modo de edição, carregando todos os campos pelo fluxo autorizado habitual. Assim, Salvar/Excluir continuam ligados ao ID e à revisão do registro correto, sem preencher uma cópia destinada à criação.

A rota exige usuário atual revalidado por LockActor, perfil administrador, autenticação, CSRF e limite de 60 consultas/minuto. Recebe `{cpf}` no corpo e retorna exclusivamente `{found, edit_url}`, com `private, no-store`; não retorna payload, CPF ou demais dados pessoais. A comparação usa a extração JSON do grammar da conexão e remove máscara/espaços, compatível com os dados importados e os cadastros nativos. Inclui inativos, exclui exclusões lógicas. CPF inválido recebe 422; múltiplas correspondências recebem 409 e exigem escolha na lista, sem selecionar arbitrariamente.

A interface cancela/descarta consultas antigas, evita nova consulta do CPF original ao editar e mantém o preenchimento quando não encontra cadastro. Salvar aguarda a busca pendente e só prossegue automaticamente quando o CPF não foi encontrado; cliques repetidos enquanto aguarda não repetem o envio. Em falha/ambiguidade, não navega nem retoma a gravação, e permite tentar novamente. A consulta não grava dados nem altera contratos.

Validação executada: suíte JavaScript com 57 testes aprovados; após proteção contra cliques repetidos, os quatro testes de lookup foram repetidos e passaram. PHPUnit `--filter 'InspectorRegistrationTest|InspectorDefaultsTest|CatalogNavigationTest'`: 19 testes/214 asserções aprovados. Pint dos dois arquivos PHP alterados, arquitetura (3 testes/4 asserções), build e `git diff --check` aprovados. Edge headless com `scripts/browser-enter-navigation.cjs`: busca simulada via POST com CSRF abre a ficha existente, sem submissão implícita; demais cenários de teclado continuam aprovados. Testes PHP usam SQLite em memória; navegador usa respostas fictícias, sem modificar o banco instalado. Nenhuma migration necessária.

## Carga do catálogo legado no ambiente disponível

Constatada ausência de `tb_Fiscal` como tabela no banco conectado e zero linhas em `contract_inspectors`; o dump local contém a origem. Executado `docker compose exec -T app php artisan contracts:import-inspectors`: 15 registros de origem, 14 importados, zero sobrescritos e um rejeitado por nome vazio (preservado na trilha de importação). Consulta agregada posterior confirmou 14 fiscais disponíveis: um com CPF válido e 13 com CPF vazio; a consulta por CPF encontrou o registro válido. Não foram exibidos documentos pessoais nem alterados contratos. Fiscais sem CPF continuam disponíveis pela lista e precisam ter esse campo preenchido para serem encontrados pelo documento.

## Datas brasileiras importadas

A ficha agora reconhece datas legadas `DD/MM/AAAA` além de `AAAA-MM-DD`, com validação estrita de dias/meses/ano e anos bissextos, tanto em nascimento quanto em data do decreto. Por exemplo, `30/12/1971` preenche o campo normalmente em vez de gerar o aviso de valor legado. O aviso permanece para datas impossíveis ou formatos não reconhecidos.

A ficha exibe datas brasileiras e envia datas ISO (`AAAA-MM-DD`). Abrir a ficha não regrava dados. Conforme a regra de armazenamento confirmada para a Hostinger, datas preenchidas são gravadas em ISO ao salvar, incluindo datas brasileiras importadas cujo dia permaneceu igual. A origem original continua na trilha de importação. Limpar uma data brasileira reconhecida é respeitado; campos omitidos permanecem intactos. Dados históricos inválidos ainda são preservados quando nenhum valor substituto é informado. O normalizador global de contratos não foi alterado.

Validação inicial: Pint dos três arquivos PHP alterados e `git diff --check` aprovados; PHPUnit `--filter 'InspectorRegistrationTest|InspectorDefaultsTest|CatalogNavigationTest'` com 21 testes/229 asserções aprovados. Para a regra posterior da Hostinger, o cenário de gravação foi atualizado para exigir ISO no payload; detalhes e validações abaixo. Não há migration.

Regra de datas para Hostinger: `30/12/1971` aparece na tela, enquanto o envio e a gravação usam `1971-12-30`. Servidor exige `date_format:Y-m-d`, rejeita datas brasileiras enviadas diretamente e não depende de interpretação regional do MySQL. Verificação: PHPUnit `--filter 'InspectorRegistrationTest|InspectorDefaultsTest|BrazilianLocalizationTest|SupplierRegistrationTest'`: 36 testes/489 asserções aprovados; suíte JavaScript: 57 testes aprovados; Pint dos dois arquivos PHP alterados e `git diff --check` aprovados. Edge headless verificou exibição brasileira, ano bissexto, data ambígua `01/02/2026` enviada como `2026-02-01`, campo vazio e preservação da aparência após construir FormData. Nenhuma alteração no banco remoto ou regravação em massa de dados históricos.
