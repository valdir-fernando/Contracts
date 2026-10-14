# Preenchimento de fornecedor em novos contratos — 13/10/2026

Ao sair do campo CPF/CNPJ na seção de fornecedor, o formulário aplica a máscara e busca um cadastro acessível com documento válido. Nome, RG, documento profissional, PIS/PASEP, endereço, sócio/representante e CPF/RG do sócio vêm do catálogo `suppliers`, cujo payload original é importado de `tb_Fornecedor`. A opção **Selecionar fornecedor cadastrado → Usar no novo contrato** usa a mesma projeção; documentos já selecionados recebem máscara na abertura.

RG, PIS/PASEP e CPF/RG do sócio são sugeridos apenas a administradores. Usuários comuns continuam acessando somente fornecedores com CNPJ vinculados a contratos visíveis e recebem os demais campos permitidos. Não são retornados payloads completos, contatos, dados de nascimento, pensão ou outros atributos da origem. CPF inválido do sócio não é sugerido, preservando o dado original no catálogo.

`POST /suppliers/lookup` revalida o ator, autoriza criação, aplica a visibilidade do catálogo e recebe o documento no corpo, com CSRF e limitação de frequência. Respostas usam `private, no-store`. Correspondências ausentes/inacessíveis recebem 404; múltiplas correspondências acessíveis recebem 409 e exigem seleção explícita. Entradas inválidas recebem 422.

O navegador descarta respostas antigas, limpa sugestões do fornecedor anterior ao trocar o documento e preserva alterações manuais feitas durante a consulta. Uma gravação aguarda a busca pendente terminar. Campos sugeridos ainda passam pela validação, autorização, persistência e auditoria normais. Contratos existentes não recebem preenchimento automático na edição. Nenhuma migration ou alteração do catálogo local foi necessária.

## Validação executada

- `docker compose exec -T app php vendor/bin/phpunit --filter='SupplierAutofillTest|SupplierAssistanceTest|ContractWritingTest|TableNavigationTest|SelectedFundTest' --do-not-cache-result`: validação inicial com 33 testes e 341 asserções aprovados.
- Pint nos cinco arquivos PHP alterados: aprovado após formatação.
- `docker compose exec -T -e QUEUE_CONNECTION=database app composer check`: Pint aprovado em 182 arquivos; versão final com 294 testes e 2.339 asserções aprovados, incluindo Architecture e Unit. Fila explicitada para evitar a configuração `sync` do `.env` local no teste existente de escala.
- `npm.cmd test` no host e `docker compose run --rm --no-deps node npm test` no Node 22: seis testes JavaScript aprovados em ambos.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `node --check` no módulo de assistência e no script de smoke: aprovado.
- `git diff --check`: sem erros.

Os testes verificam CPF/CNPJ, igualdade das projeções por busca/seleção, campos pessoais e escopos, documentos inválidos/ambíguos, reautorização de perfil, old input, preenchimento e gravação auditada, máscaras iniciais, respostas fora de ordem e edições durante a consulta. `npm test` foi incluído na CI. O smoke de navegador agora espera o preenchimento antes de salvar, mas não foi executado nesta etapa.

PHPUnit utilizou SQLite em memória e armazenamento/cache temporários. Não houve ensaio real de navegador, testes MySQL, commits ou publicação externa.
