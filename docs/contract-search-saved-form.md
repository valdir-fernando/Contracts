# Abrir contrato existente pela lupa

A lupa da ficha Novo Contrato continua consultando `/contracts/lookup`, limitada ao Fundo selecionado e aos contratos visíveis ao usuário. Selecionar um resultado abre a URL de edição do contrato autorizado, carregando os campos salvos nesse registro. As assinaturas dos endpoints e o formato da resposta de busca permanecem iguais.

Na edição, o cabeçalho identifica o Fundo pelo snapshot do contrato e o nome do contratado também vem do registro salvo. Essa apresentação não altera o Fundo da sessão, as permissões nem os escopos. Contratante, contratado, gestor e fiscal usam os snapshots do contrato, com o mascaramento por perfil já existente. Campos ausentes não são completados pelo cadastro atual durante a abertura.

O preenchimento automático de fiscal deixa de consultar o catálogo na abertura de contrato existente com a mesma Secretaria, inclusive quando `nome_fiscal` estiver vazio. Uma troca explícita de Secretaria continua consultando o fiscal autorizado para o novo contexto. A validação e as regras de persistência existentes foram mantidas; este ajuste não dispensa fiscal na gravação nem cria atribuição histórica a partir de uma consulta de tela.

O campo oculto `_contract_form_id` delimita a restauração dos valores de uma tentativa anterior: vazio para criação, ID para edição. `formValue` usa `old()` somente quando esse identificador corresponde ao formulário atual. Isso impede que uma tentativa em Novo Contrato ou em outro contrato substitua os campos salvos do resultado escolhido. O preenchimento de fornecedor após erro na criação também ignora valores de uma ficha de edição. Após erro de validação no mesmo contrato, os valores digitados continuam disponíveis para correção.

Esse identificador é apenas uma indicação de apresentação; não é identidade, autorização, revisão nem campo persistido do contrato. Policy, versão, snapshots protegidos e auditoria continuam sob as regras anteriores do servidor. Nenhuma migration ou atualização de dados é necessária.

## Validação

- `php artisan test --filter='ContractWritingTest|InspectorDefaultsTest|SupplierAssistanceTest' --stop-on-failure`, no container `app`: 48 testes, 756 asserções, passaram.
- `npm test`, no container `node`: 91 testes passaram, incluindo preservação de fiscal ausente sem consulta inicial e atualização após troca de Secretaria.
- `npm run build`, no container `node`: passou.

Os testes novos percorrem busca e edição, usam snapshots históricos diferentes dos dados atuais e verificam rejeição de valores antigos de criação/outra ficha, campos de imóvel/destinação, datas/tipo, cabeçalho do Fundo, preservação de nomes e fiscal ausente. Há teste da recuperação de valores após erro no mesmo contrato. Os testes existentes de escopos, dados pessoais e gravação foram mantidos.

As primeiras execuções identificaram dois problemas na compilação Blade (closure em `@php` inline e `@endif` adjacente a slot), corrigidos com bloco PHP e diretivas em linhas separadas. Uma alteração exploratória da validação de fiscal em edição foi descartada após os testes evidenciarem impacto em regras de gravação existentes; a correção final fica no carregamento/apresentação. Testes executados em SQLite em memória e storage/cache temporários; nenhum dado do banco instalado foi alterado.

Verificação completa: `composer check` passou no Pint (299 arquivos) e PHPUnit (618 testes, 6.092 asserções, sem falhas; um ignorado). `composer check:architecture`: 3 testes, 4 asserções, passou; `composer test:unit`: 77 testes, 169 asserções, passou. Após a proteção adicional para ignorar identificador de formulário em formato não escalar, os três testes específicos de abertura/restauração/fiscal foram repetidos com sucesso (3 testes, 67 asserções), incluindo marcador malformado. Pint dos dois PHP envolvidos passou. `php artisan view:clear` executado e `git diff --check` passou. Não houve ensaio manual autenticado em navegador.
