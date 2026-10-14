# Painéis de número e fornecedor

Novo Contrato, edição e detalhes apresentam o título à direita, na mesma linha do seletor e do botão Alterar Fundo, acompanhado do nome do fornecedor logo abaixo. Ambos usam fonte grande, negrito e o azul Del Rey do sistema (`--nav`). Sem fornecedor preenchido, aparece **Não informado**. O cabeçalho acompanha a rolagem e permanece fora das seções controladas pelas abas. Em telas de até 650px, os controles e o título ficam em linhas separadas, mantendo o título à direita e evitando sobreposição.

Antes de salvar, o título é **Novo contrato**, sem número reservado. Os campos de numeração continuam indicando **Gerado ao salvar**. O fluxo HTTP redireciona para os detalhes após a gravação; uma mensagem de sessão identifica esse primeiro acesso e mostra **Novo contrato 001/26**, usando o número efetivamente salvo. Consultas posteriores mostram **Contrato 001/26** e a edição mostra **Editar contrato 001/26**. A mensagem é somente apresentação e não autoriza operações.

A orientação de preenchimento fica antes de **Órgão e situação administrativa**, com espaço reduzido. Em Novo Contrato, **Selecionar fornecedor cadastrado** fica na seção **2. Fornecedor e representante**, ao lado do rótulo **CPF/CNPJ do fornecedor**, separado por `|`. O link mantém a rota de fornecedores e acompanha a visibilidade da seção. Em janelas estreitas, o rótulo e o link podem quebrar linha sem sobreposição. A edição conserva o comportamento anterior, sem esse link.

O link **Voltar** fica no cabeçalho, diretamente abaixo do nome do Fundo. O espaço entre cabeçalho, título/nome do fornecedor, orientações, ícones e primeiro painel foi reduzido no editor, mantendo os campos e os controles de seleção utilizáveis em janelas menores.

O nome do fornecedor acompanha o formulário, inclusive seleção de cadastro, busca assíncrona, alterações manuais e limpeza dos preenchimentos anteriores. A atualização usa `textContent`, sem interpretar HTML digitado. O resumo não apresenta documentos nem endereço. Os detalhes usam o nome persistido no snapshot do contrato. O editor identifica explicitamente o formulário do contrato, para não confundi-lo com o formulário de troca de fundo agora adjacente ao resumo.

A mudança não altera migrations, numeração, auditoria, autorizações ou o destino do redirecionamento HTTP.

Validação executada nos contêineres locais:

- `php vendor/bin/phpunit --filter='ContractWritingTest|SupplierAutofillTest|ContractBrowsingTest' --do-not-cache-result`: 26 testes e 373 asserções aprovados em SQLite em memória.
- `npm test`: 7 testes aprovados, incluindo resumo, atualização pela busca e limpeza ao trocar documento.
- `php vendor/bin/pint --test tests/Feature/ContractWritingTest.php`: aprovado.
- `npm run build`: aprovado.
- `git diff --check`: aprovado.

Verificação após simplificação/reposicionamento: `php vendor/bin/phpunit --filter='ContractWritingTest|FundSwitchingTest' --do-not-cache-result` aprovado (20 testes, 210 asserções), `npm test` aprovado (7 testes), Pint do teste PHP aprovado e `npm run build` aprovado.

Verificação da integração ao título e reposicionamento da orientação: `php vendor/bin/phpunit --filter='ContractWritingTest|ContractBrowsingTest|SupplierAutofillTest' --do-not-cache-result` aprovado (26 testes, 375 asserções); checagem adicional do texto do título na criação/edição aprovada. Pint dos arquivos PHP modificados e build aprovados. O script de smoke foi atualizado para reconhecer o título após criação; o fluxo completo de navegador não foi executado.

Verificação do alinhamento à direita junto a Alterar Fundo: `php vendor/bin/phpunit --filter='ContractWritingTest|FundSwitchingTest' --do-not-cache-result` aprovado (21 testes, 231 asserções); Pint do teste modificado, `npm run build` e `git diff --check` aprovados. O resumo continua vinculado ao formulário do contrato, separado do formulário da sessão.

Verificação da compactação e do link Voltar abaixo do Fundo: mesmo filtro PHPUnit aprovado (21 testes, 232 asserções); Pint, build e `git diff --check` aprovados. Sem ensaio visual em navegador.

Verificação da seleção de fornecedor ao lado do CPF/CNPJ: `php vendor/bin/phpunit --filter='ContractWritingTest|SupplierAutofillTest' --do-not-cache-result` aprovado (19 testes, 223 asserções); Pint do teste modificado, build e `git diff --check` aprovados. Sem ensaio visual em navegador.

Não foi realizado ensaio visual em navegador nesta etapa. Nenhum cadastro foi feito no banco real para validar a interface.
