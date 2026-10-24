# Entrega de Relatórios — 24/10/2026

Os ajustes desta conversa foram isolados das alterações anteriores presentes no workspace. O commit funcional inclui a tela de Relatórios, consultas e testes de segurança/compatibilidade, a correção do envio das datas de controles externos ao formulário e a documentação em [Tela de Relatórios](contract-reports-screen.md).

## Validação executada

- `docker compose exec -T app composer check:architecture`: 3 testes, 4 asserções, passou.
- `docker compose exec -T app composer test:unit`: 81 testes, 195 asserções, passou.
- `docker compose exec -T app php vendor/bin/pint --test`: passou em 347 arquivos.
- `docker compose run --rm --no-deps node npm test`: 141 testes, passou.
- `docker compose run --rm --no-deps node npm run build`: passou.
- `ContractBrowsingTest`: 20 testes, 328 asserções, passou.
- `AdvancedContractSearchTest|ContractCompletionTest`: 9 testes, 131 asserções, passou.
- `ContractAmendmentsTest|ReportScaleTest`: 9 testes, 174 asserções, passou.
- `TableNavigationTest`: 4 testes, 50 asserções, passou.
- `git diff --check` e `git diff --cached --check`: passaram.

A primeira execução de `docker compose exec -T -e COMPOSER_PROCESS_TIMEOUT=0 app composer check` percorreu 800 testes e encontrou 8 falhas: sete verificações da interface anterior, corrigidas sem retirar a cobertura de consultas/exportações; e uma falha anterior fora do escopo de Relatórios.

A execução final do mesmo comando aprovou Pint em 347 arquivos e percorreu 800 testes, com 8.303 asserções, 1 teste ignorado e somente a falha anterior de `SelectedFundTest` descrita abaixo. Não houve falha restante nos testes alterados nesta entrega.

## Limitação do workspace

`SelectedFundTest::test_editing_a_contract_preserves_its_existing_manager` falha porque a alteração local anterior de `ContractFields` introduz o rótulo “Gestor do Fundo”, enquanto o teste antigo proíbe esse texto em toda a página. A falha foi reproduzida isoladamente; `SelectedFundTest` passou com 11 testes e 90 asserções ao pré-carregar a versão de `ContractFields` de HEAD em um processo de teste temporário. Nenhum arquivo do workspace foi substituído para esse diagnóstico.

As alterações anteriores de contratos, documentos, referências e previews não integram os commits desta entrega e foram preservadas. Testes PHP usaram SQLite em memória e storage/cache temporários. Não houve migration, gravação nos bancos instalados ou deploy. Não houve validação visual autenticada em navegador.
