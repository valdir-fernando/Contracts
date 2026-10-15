# Dados Contratante e fiscal automático — 15/10/2026

## Comportamento

O painel Dados Contratante é somente para consulta. Fundo, CNPJ, endereço e gestor são derivados dos cadastros autorizados na criação; não são gravados a partir dos controles HTTP. Na edição, os snapshots existentes desses campos permanecem intactos, inclusive se o cliente enviar valores válidos ou nulos. A validação anterior de documentos e campos protegidos continua ativa. Os snapshots de órgãos continuam sendo resolvidos pelo caso de uso ao alterar a Secretaria.

Fiscal, decreto, CPF e RG são resolvidos de `contract_inspectors`, importado de `tb_Fiscal`:

- Fora do Município de Niquelândia: correspondência pelo nome normalizado de `Fundo`.
- Município de Niquelândia: correspondência pelo Fundo e pelo nome normalizado da Secretaria selecionada.
- Sem Secretaria, sem correspondência ou com vínculos ambíguos: nenhum fiscal é escolhido arbitrariamente. O painel informa a ausência de um fiscal único.
- Fiscal é obrigatório no cadastro e na edição. Sem um nome autorizado resolvido no servidor ou um fiscal histórico preenchido preservado no mesmo contexto, a gravação é recusada com erro em `nome_fiscal`. Valores forjados no HTTP não atendem à exigência; contratos históricos sem fiscal precisam receber um cadastro correspondente antes de salvar. A falha não altera revisão, contador ou auditoria.
- A criação e a troca de Fundo/Secretaria recalculam os dados no servidor. Um contrato sem fiscal também pode receber o cadastro atual ao salvar. Um fiscal histórico preenchido permanece preservado enquanto o contexto não muda.
- Na edição com Fundo da sessão diferente do contrato, a assistência de interface fica desativada para não exibir o fiscal de outro contexto.

As alterações de fiscal integram a mesma transação, revisão e auditoria da alteração contratual. A resolução ocorre depois do lock do ator/contrato; os fiscais encontrados são bloqueados na gravação. Valores enviados pelo cliente não definem fiscal, gestor ou dados do Fundo. A compatibilidade de serviços internos e importação histórica permanece intacta.

## Consulta autorizada

`GET /contracts/inspector-defaults` recebe `department_id` opcional e `contract_id` na edição. O Fundo vem exclusivamente da sessão. A rota revalida ator, policy, Fundo, contrato e escopo de Secretaria, rejeitando contratos de outro Fundo ou Secretarias inacessíveis.

A resposta usa `Cache-Control: private, no-store` e projeta somente `nome_fiscal` e `decreto_fiscal`; CPF válido e RG são disponibilizados exclusivamente a administradores. A persistência interna mantém os documentos completos do catálogo. Contatos, data de nascimento e payload completo nunca são devolvidos.

Na interface, a consulta roda na abertura de um novo cadastro, para contratos sem fiscal e ao trocar a Secretaria. Respostas antigas são descartadas e consultas anteriores canceladas. Os textos são escritos com `textContent`. Dados pessoais não autorizados permanecem restritos, inclusive durante a troca de Secretaria. Salvar espera a consulta em andamento; a gravação sempre resolve o catálogo novamente, mesmo se a prévia falhar.

## Instalação e importação

```powershell
docker compose exec -T app php artisan migrate --path=database/migrations/2026_10_15_000001_create_contract_inspectors.php --force
docker compose exec -T app php artisan contracts:import-inspectors
```

O comando aceita um caminho opcional para o dump. O leitor extrai dados literais de `tb_Fiscal`, sem executar o SQL da origem. A importação mantém `ImportRun` e `LegacyRecord`, é idempotente e preserva cadastros existentes em caso de conflito. Registros sem nome são preservados na trilha como rejeitados, sem impedir os válidos; identificadores inválidos interrompem e revertem a transação. A importação completa de contratos também inclui o catálogo de fiscais.

Executado neste ambiente: migration adicional aplicada; 15 registros lidos, 14 importados, 1 rejeitado por ausência de nome. Nenhum contrato foi regravado pela importação e a origem não foi alterada.

## Validação

- Atualização de Fiscal obrigatório: `docker compose exec -T -e QUEUE_CONNECTION=database app composer check` passou com 343 testes, 2964 asserções e 1 ignorado. Os cenários existentes de gravação válida agora cadastram explicitamente fiscais fictícios; os testes de ausência/ambiguidade verificam a recusa, sem alteração de contrato, revisão, contador ou auditoria. `npm run build` e `git diff --check` passaram após a padronização do fundo dos campos editáveis em #808080.
- `docker compose exec -T -e QUEUE_CONNECTION=database app composer check`: Pint e suíte completa passaram (342 testes, 2943 asserções, 1 ignorado). A primeira execução identificou expectativas antigas sobre os defaults do fornecedor; foram atualizadas para incluir os novos campos, mantendo a comparação exata e as verificações de privacidade.
- Testes de integração de fiscais: 5 testes, 69 asserções; passaram em SQLite isolado.
- Testes de fiscais e fornecedores: 16 testes, 219 asserções; passaram. Expectativas do cadastro de fornecedor foram atualizadas para os novos defaults de fiscal, mantendo as verificações de autorização e privacidade.
- `composer check:architecture`: 3 testes; passou.
- `composer test:unit`: 57 testes; passou.
- `npm test`: 15 testes; passou, incluindo respostas fora de ordem, preservação histórica, consulta por Fundo e tratamento de ausência/falha.
- `npm run build`: passou.
- `git diff --check`: passou.

O navegador não foi ensaiado nesta etapa; Playwright não está disponível no Node local. A concorrência MySQL depende do schema descartável dedicado e não foi executada. Os testes não acessaram o banco instalado.
