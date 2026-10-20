# Consulta pública de CNPJ

Os cadastros de Fornecedor e Fundo consultam o banco ao sair do campo de CNPJ válido. Quando não cadastrado, a consulta externa preenche somente campos vazios e editáveis. Quando cadastrado, os valores do banco são apresentados e a janela **Conferência do CNPJ** compara esses valores com a consulta. Em Fundo, abre primeiro a ficha existente, com sua revisão de edição, e então consulta.

Após preencher um novo cadastro, o aviso **Dados públicos preenchidos. Confira antes de salvar.** aparece na caixa padrão **Gestão Sistem informa:**, com botão **OK**. O componente de mensagem é compartilhado em `resources/js/system-message.js`, preservando a exportação existente usada pelas validações de documentos. Após esse ajuste, foram aprovados novamente os 66 testes JavaScript, os quatro cenários de navegador, o build e `git diff --check`.

**Manter cadastro** fecha a janela sem alterar os campos. **Usar dados consultados** aplica valores não vazios aos campos editáveis do formulário; a persistência continua dependendo de **Salvar**, com autorização, validação, revisão e auditoria existentes. Nomes de Fundos protegidos por vínculos permanecem preservados. A consulta não altera contratos ou snapshots.

Fonte inicial: [BrasilAPI, endpoint CNPJ](https://github.com/BrasilAPI/BrasilAPI/blob/main/pages/docs/doc/cnpj.json), que disponibiliza dados públicos cadastrais. Não é uma conexão direta em tempo real à Receita Federal; atualização e disponibilidade dependem da fonte. A janela identifica o provedor. A [consulta oficial via Serpro](https://www.gov.br/pt-br/servicos/obter-solucao-de-consulta-de-dados-do-cadastro-nacional-de-pessoas-juridicas-cnpj) depende de contratação e credenciais, não configuradas nesta implementação.

O adaptador `Infrastructure/Companies/CnpjLookup` consulta apenas uma URL HTTPS fixa, sem redirecionamentos, com timeout de conexão de 3 segundos e total de 8 segundos. O endpoint autenticado `POST /cnpj/lookup` exige administrador atual, valida os dígitos do CNPJ antes da chamada, limita a 20 consultas por minuto e revalida o ator após a resposta externa. Retorno privado sem cache. Não são retornados JSON bruto, CPF parcial ou atributos não utilizados. Para usuários autorizados ao cadastro de fornecedor, `partners` projeta somente nome e qualificação dos integrantes do QSA; usuários com acesso apenas a Fundo não recebem essa lista.

Campos: razão social, nome fantasia, endereço completo, telefones válidos e e-mail válido. Fundo usa razão social, endereço e telefone. Não se presume WhatsApp, abreviatura, código de gestão, contato ou representante a partir de outros dados. CPF continua usando apenas o banco. O escopo atual mantém o formato numérico de CNPJ já aceito pelo sistema.

Respostas de CNPJ diferente, documentos inválidos, falhas de rede, limite do provedor e JSON inválido não preenchem campos. Indisponibilidade externa permite continuar manualmente. Trocar o documento cancela/descarta respostas antigas e desfaz preenchimentos automáticos ainda não editados. Valores são apresentados como texto, inclusive na comparação. Nenhuma chamada externa ocorre dentro de transação de escrita.

## Validação executada

- PHPUnit direcionado: `docker compose exec -T app php vendor/bin/phpunit --filter 'CnpjLookupTest|FundRegistrationTest|SupplierRegistrationTest' --do-not-cache-result`: 24 testes, 380 asserções aprovados em SQLite em memória. A primeira execução encontrou nome incorreto de tabela no teste (`contract_suppliers`); o schema usa `suppliers`, corrigido após inspeção.
- `node --test tests/JavaScript/*.test.mjs`: 66 testes aprovados, incluindo resposta tardia, falha externa, campos manuais/protegidos e espera da consulta antes de salvar Fundo.
- `scripts/browser-cnpj-lookup.cjs` com Playwright/Edge: quatro cenários aprovados (Fornecedor e Fundo, novos e existentes), com APIs simuladas; preenchimento, máscaras, troca de documento, comparação, manter/aplicar e nome protegido. Sem gravações. O primeiro ensaio precisou declarar UTF-8 no HTML sintético para os seletores com acentos.
- `docker compose exec -T app composer check:architecture`: 3 testes, 4 asserções aprovados.
- `docker compose exec -T app composer check`: Pint aprovado em 255 arquivos; PHPUnit com 462 testes, 4.360 asserções e nenhuma falha. Um teste de concorrência foi ignorado por exigir MySQL descartável isolado.
- Pint dos arquivos PHP e rotas, `npm.cmd run build` e `git diff --check`: aprovados. A primeira checagem de estilo apontou importação da rota, corrigida pelo Pint.
- Consulta real de conectividade com o CNPJ público de exemplo da documentação (`19131243000197`): retorno do mesmo CNPJ com razão social e endereço; nenhum dado de cadastro local foi enviado.

Não exige migration, credencial ou dependência nova. Não houve alteração de banco de produção nem consulta externa em testes automatizados.

## Seleção do sócio para assinatura

Após consultar o CNPJ, a seção Sócio apresenta todos os nomes e qualificações fornecidos pelo QSA, sem escolher automaticamente nem presumir que todo administrador seja proprietário. O usuário indica o signatário e confere seus poderes. A seleção preenche Nome_Socio, limpa CPF/RG da pessoa anterior e mantém a gravação no fluxo autorizado e auditado existente. CPF/RG devem ser conferidos e informados manualmente; dados mascarados não são usados como documentos válidos. Sem QSA, o preenchimento manual continua disponível. Trocar CNPJ ou reiniciar o formulário oculta a lista anterior; respostas antigas não permitem selecionar nomes.

O nome escolhido é salvo no campo existente do fornecedor e segue a projeção atual para novos contratos. Contratos e documentos históricos preservam seus snapshots. A lista consultada é uma ajuda temporária de seleção; não cria um novo cadastro de sócios.

Validação: npm test passou (98 testes); php artisan test --filter='CnpjLookupTest|SupplierRegistrationTest' passou (16 testes, 283 asserções), em SQLite isolado; vendor/bin/pint --dirty passou. Build executado com npm run build. Sem ensaio manual autenticado em navegador.
