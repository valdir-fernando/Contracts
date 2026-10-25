# Contrato de Credenciamento Saúde

O botão **Contrato** emite o PDF privado de contratos salvos do tipo **Credenciamento Saúde**, usando a cópia integral do Word fornecido em `resources/documents/contracts/health-accreditation.docx`. **Credenciamento** permanece um tipo distinto. A rota existente recebe a versão; o modelo é determinado pelo tipo persistido, sem seleção pelo cliente. O arquivo se chama `contrato-credenciamento-saude-{id}.pdf`.

Cargo e local vêm de `cargo_credenc` e `local_servico`. CPF, RG, documento profissional e endereço vêm dos snapshots salvos do fornecedor. Valor mensal/extenso vêm de `vl_parcial`/`ext_parcial`; valor total/extenso vêm de `valor_total`/`ext_total`. Campos ausentes usam a apresentação existente “Não informado”. CPF e RG respeitam o mascaramento por perfil.

O responsável segue a resolução de assinantes dos demais contratos. Seu decreto é consultado no cadastro de gestores somente quando Fundo, nome e CPF correspondem a um único registro ativo; ausências e ambiguidades não atribuem decreto de outra pessoa.

A tabela conserva sete colunas: ITEM, ESPECIFICAÇÃO, CATSER, UND, QTD, VL_Unit e VL_Total. ESPECIFICAÇÃO usa a descrição e CATSER usa o código salvo do produto, sem consulta ou classificação externa. Lançamentos originais são apresentados separadamente, com conferência decimal de quantidades, preços e totais. Dotações vêm das fichas desses produtos, com concordância singular/plural e soma conferida com o valor original do contrato. Produtos ausentes e divergências impedem emissão, conforme o fluxo dos modelos anteriores.

As cláusulas e referências fixas do Word foram preservadas, inclusive edital 001-2025, Resolução 003-2025, término em 31 de dezembro de 2025, orçamento em 25/02/2025 e foro de Formosa. Esses trechos não possuem marcadores no documento fornecido e não são recalculados a partir da vigência do cadastro.

Captura e revalidação ocorrem sob os locks e autorização existentes; renderização fica fora da transação. Alterações de fontes, permissões, versão ou modelo impedem entrega e auditoria de sucesso. Nenhuma migration ou alteração financeira foi introduzida.

Prévia sintética sem acesso ao banco: `docker compose exec -T app php scripts/preview-health-accreditation-contract.php`. Arquivos em `build/health-accreditation-contract-preview/contract.html` e `contract.pdf`.

## Validação em 24/10/2026

- `docker compose exec -T app php vendor/bin/phpunit --filter='SupplyContractPdfTest|SupplyContractTemplateTest|SupplyContractFiguresTest' --do-not-cache-result`: 125 testes, 1.747 asserções, todos passaram. Inclui emissão PDF real, campos profissionais, valores mensal/global, sete colunas, uma/várias dotações, mascaramento, permissão e 14 alterações durante a emissão do novo modelo.
- `docker compose exec -T app composer check:architecture`: 3 testes e 4 asserções passaram.
- `docker compose exec -T app composer test:unit`: 81 testes e 195 asserções passaram.
- Pint dos PHP alterados e do script, `npm.cmd run build`, `git diff --check`, geração da prévia e igualdade SHA-256 entre original e cópia passaram.
- A primeira execução de `docker compose exec -T app composer check` excedeu o limite de 300 segundos. Reexecução com `docker compose exec -T -e COMPOSER_PROCESS_TIMEOUT=0 app composer check` concluiu: Pint passou (347 arquivos); PHPUnit executou 790 testes e 8.152 asserções, com uma falha e um teste ignorado.
- A falha geral é `SelectedFundTest::test_editing_a_contract_preserves_its_existing_manager`, linha 147: `assertDontSee('Gestor do Fundo')` também encontra o novo rótulo do campo, introduzido por alteração já existente em `ContractFields.php` antes desta tarefa. O teste verifica toda a página, embora o nome histórico seja preservado. A alteração preexistente e o teste foram mantidos.
- Na primeira rodada focada, o novo teste de preservação textual não configurava a expectativa de `#TEXTO_DOTACAO`; a expectativa foi corrigida para conferir a frase efetivamente emitida, mantendo a verificação integral dos parágrafos. A rodada final focada passou.

Testes usam SQLite em memória e storage/cache temporários. Nenhuma migration ou operação no banco instalado. Prévia gerada sem consulta ao banco; sem conferência visual autenticada no navegador ou rasterização do PDF.
