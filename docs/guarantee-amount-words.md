# Valor da garantia e extenso

Em contratos novos, sair de Valor da garantia formata o número e gera uma prévia do extenso. Em contratos salvos, o texto persistido permanece visível durante a edição. Alterar o valor exibe: “O valor da garantia foi alterado. O extenso será atualizado após salvar o contrato.” Restaurar o valor original remove o aviso.

Nas rotas de criação e edição, LaravelContractInputValidator calcula ext_garantia no servidor quando vl_garantia é informado na criação ou muda na edição. GuaranteeAmountWords converte o decimal exato para reais e centavos; casas adicionais são arredondadas apenas para a representação por extenso. O valor monetário mantém suas quatro casas. Limpar o valor limpa o extenso; omitir o valor ou mantê-lo preserva o histórico. A gravação e a auditoria usam a mesma transação existente. A prévia JavaScript não autoriza nem persiste alterações.

Validação via Docker: ContractWritingTest passou (48 testes, 846 asserções); guarantee-message.test.mjs passou (5 testes); composer check:architecture passou (3 testes, 4 asserções); Pint dos três arquivos PHP envolvidos, npm run build e git diff --check passaram. Testes PHP usam SQLite isolado. A primeira execução identificou o nome incorreto da enum de arredondamento; corrigido para RoundingMode::HalfUp. Sem inspeção visual no navegador.
