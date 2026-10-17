# Valor dos contratos do Fundo no Início

O indicador abaixo de **Próximos vencimentos** soma o valor acumulado de cada contrato (`VL_Acumulado` legado, `contracts.valor_acumulado`). Se esse valor não estiver informado, usa o valor inicial daquele registro (`VL_Total`, `contracts.valor_total`). Acumulado igual a zero é um valor informado e não aciona a alternativa. Registros sem os dois valores contam como ausentes e não acrescentam valor à soma.

O recorte continua sendo o Fundo selecionado na sessão, os escopos atuais do usuário e o exercício escolhido no Início (ano atual por padrão). **Todos os anos** inclui contratos sem exercício. Contratos excluídos permanecem fora. O indicador considera todos os contratos desse recorte, independentemente de aparecerem no gráfico de vencimentos; as contagens mensais do gráfico mantêm suas regras.

`OverviewMetrics::for` acrescenta `portfolioValue`, contendo `total` decimal exato e `missing`, sem alterar a assinatura nem o resumo de valores iniciais usado pelos relatórios. A soma usa Brick Math e strings decimais, com projeção de apenas `valor_acumulado` e `valor_total` na leitura adicional. Não há cálculo de aditivos, alteração de valores armazenados ou migration.

Validação específica via Docker: `php vendor/bin/phpunit --filter=OverviewYearTest --do-not-cache-result` passou com 8 testes e 69 asserções, cobrindo prioridade do acumulado, alternativa por registro, zero, ausência de ambos, precisão decimal, escopos, Fundo para administrador, exercício anterior, todos os exercícios, exclusão lógica e ausência de Fundo selecionado. Pint, `composer check:static`, `php artisan view:cache` e `git diff --check` passaram. Testes em SQLite em memória com armazenamento temporário. Não houve teste em navegador nem escrita no banco instalado.

Verificação final: composer check passou (Pint em 228 arquivos; 407 testes, 406 aprovados e 1 ignorado por exigir MySQL isolado; 3.820 asserções). Nenhuma mudança de JavaScript/CSS exigiu build nesta revisão.
