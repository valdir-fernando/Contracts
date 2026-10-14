# Ícones para emissão de documentos

Novo Contrato e edição apresentam os ícones Contrato, Extrato, Publicação e Distrato à direita, imediatamente acima de Órgão e situação administrativa. Os controles têm rótulo, mensagem no hover/foco, efeito de elevação/cor e feedback ao clicar, inclusive em dispositivos sem mouse. Preferência por movimento reduzido remove a animação.

As mensagens de hover/foco aparecem abaixo dos respectivos ícones; a mensagem ao clicar também permanece abaixo do grupo. A linha de orientações/ícones usa espaço reduzido para aproximar os controles do título e nome do fornecedor.

Os botões são `type="button"`, fora do formulário de gravação, e não salvam contratos nem aplicam distratos. Antes da gravação, explicam que o contrato precisa ser salvo. Depois, informam que a emissão depende do modelo do documento. O estado indisponível é identificado por `aria-disabled`; os botões continuam focalizáveis para explicar esse estado.

Ainda não existem modelos ou endpoints de emissão desses quatro documentos. O resumo cadastral PDF existente continua com seu nome e sua rota; ele não foi apresentado como instrumento contratual, extrato oficial, publicação ou distrato. A integração da emissão depende de definir os modelos e suas regras; não foram inventados textos ou efeitos jurídicos.

Validação: `php vendor/bin/phpunit --filter=ContractWritingTest --do-not-cache-result` aprovado (13 testes, 145 asserções) em SQLite em memória; `php vendor/bin/pint --test tests/Feature/ContractWritingTest.php` aprovado; `npm run build` aprovado; `git diff --check` aprovado. Não houve testes visuais em navegador, mudança de banco ou emissão real nesta etapa.
