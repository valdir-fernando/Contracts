# Contrato de Obras

O botão **Contrato** emite o PDF privado dos contratos salvos do tipo **Obras**, usando a cópia `resources/documents/contracts/works.docx` do modelo `Reference/Modelos_Doc/Contratos/Modelo_Obras.docx`. A rota permanece `GET /contracts/{contract}/document?version=...`, com nome `contrato-obras-{id}.pdf`; o tipo persistido determina o modelo, sem seleção por parâmetros HTTP.

O documento conserva as cláusulas do Word. Número, processo, modalidade, licitação, objeto, vigência, datas, valor inicial e extenso vêm do contrato salvo. O prazo de execução vem de `prazo_obra`, preenchido pelo fluxo existente de Ordens de Serviço. Assinantes, snapshots, mascaramento por perfil e administração seguem os demais contratos.

`#EMPREITADA` recebe o valor salvo em **Tipo da Obra** (`tipo_obra`), com o nome completo do regime selecionado. Quando ausente, aparece **Não informado**.

O título e a empreitada aparecem em linhas consecutivas sem margem adicional entre eles. O mesmo vale para Processo Administrativo e modalidade da licitação.

A tabela apresenta Item, Serviços, UND, QTD, VL_Unit e VL_Total, com os lançamentos ativos do contrato original, sem agrupar itens iguais. As dotações são identificadas pelas fichas desses produtos na `tb_Dotacao`, com valores somados por ficha. A concordância usa o número de linhas de dotação efetivamente emitidas: uma resulta em **na dotação abaixo discriminada:**; várias resultam em **nas dotações abaixo discriminadas:**. O sinal de dois-pontos já presente no modelo aparece uma única vez.

Quando Obras não possui produtos originais ativos, o documento mantém `#OBJETO` preenchido pelo campo Objeto e omite a tabela inteira, sem mensagem substituta nem linhas vazias equivalentes à tabela. Não acrescenta o texto de contratação de empresa especializada. Como as dotações deste fluxo derivam das fichas dos produtos, nesse caso a cláusula apresenta a mensagem existente de dotação/valor não informados, sem atribuir valores a fichas por suposição. O valor contratual continua vindo do contrato salvo.

Havendo produtos, ficha sem dotação, dotações conflitantes e divergências de valores continuam impedindo a emissão. Os demais tipos continuam exigindo produtos. O fluxo preserva autorização, versão, captura transacional, renderização fora da transação, revalidação das fontes e auditoria antes da entrega. Nenhuma migration ou alteração financeira foi introduzida.

Prévia com dados fictícios, sem acesso ao banco: `docker compose exec -T app php scripts/preview-works-contract.php`, gerando `build/works-contract-preview/contract.pdf`.

## Validação em 23/10/2026

- Após a regra de Obras sem produtos: Pint dos três PHP alterados e testes focados passaram (4 testes, 67 asserções), incluindo PDF sem tabela, Objeto com caracteres especiais, preservação das assinaturas e exigência de produtos nos outros modelos.

- `docker compose exec -T app php vendor/bin/phpunit --filter='SupplyContractPdfTest|SupplyContractTemplateTest|SupplyContractFiguresTest' --do-not-cache-result`: 106 testes e 1.475 asserções passaram, incluindo uma/várias dotações, preservação dos parágrafos do Word, PDF real e 14 cenários de alteração durante a emissão de Obras.
- `docker compose exec -T app composer check:architecture`: 3 testes e 4 asserções passaram.
- `docker compose exec -T app composer test:unit`: 81 testes e 195 asserções passaram.
- Pint dos arquivos PHP alterados, `npm.cmd run build`, `git diff --check` e geração da prévia sintética passaram.
- A primeira execução de `docker compose exec -T app composer check` identificou uma asserção desatualizada em `ContractWritingTest`: esperava `8.Produtos`, enquanto a interface já exibia `7. Produtos`. Na preparação dos commits, a asserção foi atualizada para conferir os rótulos atuais completos, incluindo `5. Vigência/OS/Garantia`, preservando a conferência de ordem da navegação.

Os testes usam SQLite em memória e storage/cache isolados. Não houve mudança no banco instalado. A prévia PDF foi gerada; não houve conferência visual no navegador ou rasterização local, pois os utilitários de PDF não estavam disponíveis no container.
