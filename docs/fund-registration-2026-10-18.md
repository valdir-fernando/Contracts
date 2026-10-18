# Cadastro de Fundo

O menu **Cadastros → Fundo** abre `/funds`, uma ficha compacta no padrão dos formulários de contratos/fiscais. Usa os oito campos da referência `Cadastro_Fundo.jpg`: código interno somente leitura, CNPJ, nome do Fundo, endereço completo, abreviatura, código de gestão, telefone e nome do contato. Endereço inicia com uma linha e cresce/reduz conforme o texto. Alturas dos controles acompanham Novo contrato; a grade adapta-se ao celular e a lista usa rolagem horizontal sem quebrar documentos e telefones.

O catálogo existente `contract_funds` é reutilizado. No ambiente disponível já havia oito Fundos importados; nenhum novo Fundo real foi criado. Os detalhes permanecem em JSON com as chaves originais de `tb_Fundo`: `End_Fundo`, `Abreviatuda` (grafia legada), `Codigo`, `Telefone` e `Nome`. `Fundo` e `CNPJ_Fundo` acompanham as colunas normalizadas. Campos omitidos preservam os valores anteriores e campos explicitamente vazios podem ser limpos; dados desconhecidos e `legacy_id` permanecem intactos.

## Interface e gravação

- Ações Novo, Salvar e Excluir; lista paginada abre cada cadastro para edição. CNPJ possui máscara e validação de dígitos verificadores, telefone usa a máscara com DDD e o nome aparece em maiúsculas na ficha. A rota antiga de cadastro mantém a capitalização recebida, preservando seu contrato HTTP.
- Dados Online abre a [consulta oficial da Receita Federal](https://www.gov.br/pt-br/servicos/consultar-cadastro-nacional-de-pessoas-juridicas) em outra aba. É a opção padrão apresentada durante o desenvolvimento; não há preenchimento por API ou envio automático do CNPJ a terceiros.
- `GET /funds`, `POST /funds`, `PUT /funds/{fund}` e `DELETE /funds/{fund}` exigem administrador revalidado. A resposta da ficha é `private, no-store`, e os registros da lista são projetados sem payloads completos.
- `Infrastructure/Funds/FundRegistration` bloqueia ator antes de Fundo, valida revisão HMAC, contexto e duplicidades e grava cadastro e auditoria juntos. CPF não é aceito no lugar de CNPJ. Novos CNPJs/nomes duplicados são recusados. Falhas de auditoria revertem criação, alteração e exclusão.
- Edição preserva ID, contratos, scopes, numeração e snapshots históricos. Nome de Fundo importado ou já vinculado permanece somente leitura: mudar sua identidade textual quebraria associações com gestores/fiscais e outras origens legadas. Detalhes cadastrais continuam editáveis.
- Exclusão lógica exige confirmação e revisão atual e é recusada quando existem contratos (inclusive excluídos ou snapshots associados), permissões, numeração, gestores ou fiscais vinculados. O botão fica desabilitado para esses Fundos. Não há restauração pela interface nesta etapa.

O link Novo fundo de Órgãos e permissões abre a ficha completa. As rotas antigas `/catalogs/fund/{id}` e `POST /catalogs` com `kind=fund` são preservadas; a criação antiga passa pelo mesmo adaptador. A página de detalhes oferece Editar Fundo. A criação de escopo agora também bloqueia/revalida o Fundo na transação.

## Exclusões e instalação

Migration aditiva aplicada no ambiente disponível:

```bash
docker compose exec -T app php artisan migrate --path=database/migrations/2026_10_18_000003_add_fund_soft_delete.php --no-interaction
```

Adiciona somente `deleted_at`, sem remover registros. O rollback conserva a coluna e o histórico. Login, opções de Fundo, contexto da sessão, criação de escopos, cadastro de fiscais, catálogos/numeração de contratos e importadores ignoram Fundos excluídos e revalidam disponibilidade sob lock nas escritas. O carregamento da origem de um registro existente continua preservado, sem ressuscitar registros excluídos. Sessões cujo Fundo se torne indisponível seguem o encerramento já existente no middleware.

## Validação

- `composer check:architecture` no container: 3 testes/4 asserções aprovados.
- `node --test tests/JavaScript/*.test.mjs`: 58 testes aprovados, incluindo máscara de CNPJ com zeros iniciais e digitação parcial.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `docker compose exec -T app composer check`: Pint aprovado em 252 arquivos; 455 testes PHP/4.286 asserções sem falhas e um teste ignorado por exigir MySQL isolado.
- Após os ajustes finais dos importadores e a inclusão da regressão de auditoria de edição/exclusão, `phpunit --filter 'FundRegistrationTest|SelectedFundTest|FundSwitchingTest|ContractNumberingTest|ContractImportTest|LoginTest'`: 53 testes/448 asserções aprovados. Pint dos arquivos envolvidos e `git diff --check` aprovados.
- `scripts/browser-fund-registration.cjs`, com Playwright/Edge headless e HTML sintético renderizado em SQLite em memória: conferência nas larguras 1440, 768, 390 e 320 px, alturas, máscaras, CNPJ inválido com caixa padrão, nome, endereço expansível, Enter, exclusão/cancelamento e link oficial. Submissões são bloqueadas; nenhum dado real é gravado. Capturas ficam em diretório temporário.

A primeira execução da compatibilidade falhou por mudar a capitalização do nome na rota antiga; a gravação foi corrigida para preservar o comportamento existente. Na conferência móvel, o endereço inicial já ocupava duas linhas: a asserção foi corrigida para comparar a redução com a altura mínima de um controle, em vez da altura do conteúdo inicial. Não houve enfraquecimento das verificações de expansão ou navegação.

Os testes PHP usam SQLite em memória e storage/cache temporários. O teste de navegador lê `CONTRACTS_FUND_FIXTURE` (HTML fictício), aceita `CONTRACTS_BROWSER_EXECUTABLE` e `CONTRACTS_URL` e grava capturas somente se `CONTRACTS_SCREENSHOT_DIR` estiver definido. Não houve publicação, commit ou acesso ao banco remoto.

## Preenchimento pelo CNPJ já cadastrado

A ficha agora consulta `POST /funds/lookup` ao sair de um CNPJ válido, inclusive ao pressionar Enter para avançar. Uma correspondência abre a ficha existente, com endereço, abreviatura, código, telefone e contato; o formulário passa a editar o ID/revisão existentes em vez de criar uma cópia. CNPJ original de uma ficha já aberta não provoca nova navegação.

O endpoint exige administrador revalidado, autenticação, CSRF e limitação de 60 requisições/minuto. Recebe `{cnpj}` no corpo, valida quatorze dígitos/verificadores e retorna somente `{found, edit_url}` com `private, no-store`. Busca por `document_key` e também pelo documento sem máscara/espaços, cobre origem importada e ignora exclusões lógicas. Ambiguidade recebe 409 com aviso para selecionar pela lista, sem retornar dados pessoais.

O cliente cancela/descarta respostas de documentos anteriores e aguarda consulta pendente antes de salvar. Só retoma o envio se o documento não foi encontrado; cliques repetidos não repetem a gravação. Falhas/ambiguidades impedem retomada automática e permitem tentar novamente. A busca não faz gravações nem usa Dados Online/serviços externos.

Validação: 62 testes JavaScript aprovados, incluindo quatro de lookup; 21 testes PHP/211 asserções (`FundRegistrationTest|CatalogNavigationTest|SelectedFundTest`), Pint dos dois arquivos PHP, arquitetura (3 testes/4 asserções), build e `git diff --check` aprovados. No navegador, `scripts/browser-fund-registration.cjs` com `CONTRACTS_FUND_NEW_FIXTURE` confirmou digitação/Enter no CNPJ em uma ficha vazia, POST com CSRF e abertura de todos os campos no formulário PUT existente. Os demais cenários de layout/validação/exclusão passaram em 1440, 768, 390 e 320 px. As fixtures são sintéticas; foi necessário incluir o script Livewire em cada HTML separado, pois a segunda renderização no mesmo processo omitia esse asset. Após corrigir a montagem das fixtures, o cenário passou sem erros JavaScript.

Uma consulta somente leitura ao banco disponível confirmou oito Fundos, oito CNPJs válidos e oito correspondências únicas pela nova condição de busca. Nenhum dado real foi alterado. Nenhuma migration necessária.
