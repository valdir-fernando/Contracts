# Modelos de OS para Obras e Serviços

O botão PDF da OS seleciona o modelo pelo tipo persistido do contrato: Obras usa `works`; Serviços Diversos, Serviço e Serviços usam `services`. Os demais tipos mantêm um modelo genérico. Parâmetros do cliente não escolhem arquivos ou modelos.

Os modelos Blade ficam em `resources/views/documents/service-orders/`. Compartilham um layout A4 com cabeçalho do Fundo, CNPJ, endereço e administração; identificação destacada da OS; quadro de contrato/processo/licitação/contratada; objeto; local de execução, quando informado; quadro de Início/Final/Período; orientações específicas; fiscal; observações e assinaturas. Obras trata de execução conforme projetos e especificações; Serviços trata da prestação e das entregas previstas no contrato. O texto não cria multas, índices, valores ou prorrogações.

O responsável do contratante segue `ManagerSignatory`, inclusive a regra municipal existente. A ciência da contratada usa o sócio salvo no snapshot do contrato e, na ausência dele, o nome do fornecedor. Não presume escolha de sócio pela API nem altera documentos históricos. CPF/CNPJ do fornecedor respeita o mascaramento por perfil; não se imprimem CPFs/RGs de gestores, sócios ou fiscais.

`ServiceOrderTemplate` aceita apenas as três chaves fixas e calcula SHA-256 do layout mais o modelo específico. `ServiceOrderPdf` captura tipo/modelo, projeções, administração/revisão, vínculo ao aditivo e assinatura dentro da transação de leitura autorizada. Renderiza fora dela e recaptura/reautoriza antes da entrega/auditoria, recusando alterações. A auditoria registra modelo, hash do modelo, revisão da administração, versão do contrato e hash do PDF. A rota e o cache privado existentes permanecem.

## Exemplos para conferência

`docker compose exec --user www-data -T app php scripts/preview-service-order-models.php` gera dois exemplos com dados fictícios em `storage/app/private/previews/service-orders/works.pdf` e `services.pdf`, além do HTML correspondente. O script não consulta nem grava contratos ou OS. As cópias locais dos PDFs foram obtidas com `docker compose cp`. Não são arquivos públicos nem entram no Git.

Os PDFs foram rasterizados e inspecionados visualmente. A versão inicial de duas páginas foi compactada para manter as assinaturas na mesma página nos exemplos. Ambos terminaram com uma página A4, sem cortes. Objetos e observações longos podem exigir mais páginas; o layout mantém a paginação e o bloco de assinaturas unido. Para a inspeção local, foram instaladas ferramentas temporárias em `.codex/pdf-preview` (ignorado pelo Git), sem alterar dependências do projeto. A execução inicial de npm via PowerShell foi recusada pela política de scripts; o mesmo comando foi executado com npm.cmd.

## Validação executada

- `php artisan test --filter='ServiceOrderTemplateTest|ContractServiceOrdersTest'`, no container app: 13 testes, 152 asserções, passaram em SQLite isolado. Incluem tipos persistidos, tentativa de seleção por parâmetro, escape de HTML, caminhos arbitrários, mascaramento de CPF, autorização, vínculos e alteração durante renderização.
- `composer check:architecture`: 3 testes, 4 asserções, passaram.
- `vendor/bin/pint --dirty`: passou.
- `git diff --check`: passou.
- Geração dos dois PDFs de exemplo com Dompdf: passou; uma página em cada exemplo.

Nenhuma migration ou alteração de dados existentes. A suíte completa e o build de assets não foram repetidos: esta alteração atua somente no PDF do servidor. Não houve ensaio manual autenticado da interface.

## Ajustes de cabeçalho e assinatura

A logomarca da Prefeitura foi extraída sem alterações de word/media/image1.png do modelo institucional resources/documents/contracts/supply.docx e preservada em resources/documents/branding/prefeitura.png. Ela aparece no lado direito do cabeçalho, incorporada em base64 sem acesso externo. O hash auditado do modelo inclui também os bytes dessa imagem.

A caixa lateral do número foi removida: o título passa a exibir, por exemplo, ORDEM DE SERVIÇO DE OBRA Nº 004/26. Cada assinatura tem 22mm de área livre acima da linha para aplicação posterior da assinatura eletrônica. Esse espaço não gera nem aplica assinaturas digitais. Os dois PDFs de exemplo foram regenerados e inspecionados visualmente; mantêm uma página com logo, título e assinaturas sem cortes. As margens e os espaçamentos foram ajustados para acomodar a área maior.

Validação posterior: ServiceOrderTemplateTest e ContractServiceOrdersTest passaram (13 testes, 172 asserções); Pint e git diff --check passaram. Sem alterações de banco ou assets.

Espaço adicional solicitado: área de assinatura ampliada de 22mm para 31mm (aproximadamente duas linhas extras). Margens verticais e espaçamento entre seções ajustados para preservar uma página nos dois exemplos, sem diminuir a fonte. PDFs regenerados, rasterizados e conferidos; git diff --check passou. Sem mudanças funcionais ou de dados.

## Correção do cache de views

Foi identificado no log o erro touch(): Utime failed: Operation not permitted, dentro do BladeCompiler. As prévias executadas como root haviam criado views compiladas pertencentes a root; o PHP-FPM, executado como www-data, não conseguia atualizar o timestamp desses arquivos. Foi aplicado chown -R www-data:www-data storage bootstrap/cache (mesma propriedade prevista no entrypoint), seguido de php artisan view:clear com www-data. O gerador de prévias agora recusa execução como root antes de inicializar Laravel, indicando o comando com --user www-data.

Validação operacional: as duas prévias foram geradas duas vezes seguidas como www-data, com sucesso, uma página por modelo. Nenhum arquivo compilado de view permaneceu como root. Pint do script e git diff --check passaram. Nenhum contrato ou OS foi alterado por essa correção.

Teste de emissão e templates repetido como www-data com --do-not-cache-result: 3 testes, 71 asserções, passaram. A primeira execução sem essa opção apontou falta de permissão apenas no cache de resultados do PHPUnit na montagem Windows; a opção evita esse arquivo sem alterar permissões do repositório.
