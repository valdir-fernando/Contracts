# Cadastro nativo de aditivos — 11/10/2026

No detalhe do contrato, abra **Gerenciar aditivos**. O cadastro nativo mantém os 43 campos de conteúdo de `tb_Aditivos` e um identificador próprio, totalizando os 44 campos representados. O histórico importado permanece preservado; seus IDs não são reutilizados como IDs nativos. O comando [de importação dos aditivos históricos](historical-amendment-import.md) copia os vinculados para essa mesma lista, com referência única à origem e situação **Importado do legado**.

## Cadastro e conferência

No resumo do contrato, os dois atalhos **Gerenciar aditivos** abrem um painel modal amplo, no padrão do Cadastro de Produtos. A listagem, paginação, consulta e edição usam as rotas autenticadas existentes dentro do painel. O botão de fechar e Escape retornam ao resumo; o link de volta ao contrato também fecha o painel. Ao fechar, o conteúdo é descarregado e o foco volta ao atalho utilizado. Sem JavaScript, o link continua abrindo a página de aditivos normalmente.

Validação do painel (25/10/2026): `docker compose exec -T app php vendor/bin/phpunit tests/Feature/ContractBrowsingTest.php tests/Feature/ContractAmendmentsTest.php --do-not-cache-result` passou com 32 testes e 547 asserções. `docker compose run --rm --no-deps node npm test` passou com 143 testes, incluindo abertura pelos dois atalhos, fechamento, foco, navegação e Escape com diálogos internos. `npm run build`, Pint do teste alterado e `git diff --check` passaram. Não houve ensaio visual em navegador nesta alteração.

Informe número, data, processo, tipo e motivo do aditivo, além da justificativa da gravação. Os demais campos estão organizados por partes, fundamentação, períodos e informações financeiras/de execução. Números preservam zeros iniciais; valores aceitam formato brasileiro ou decimal, com até quatro casas. Períodos exigem término igual ou posterior ao início quando ambos são informados.

Contrato, fornecedor, fundo e secretaria são preenchidos pelo servidor. Os dados das partes são copiados na criação e preservados nas edições posteriores do aditivo. Documentos pessoais ficam mascarados para usuários comuns, que não podem alterá-los. Novos CPFs são validados; dados históricos inalterados são preservados.

Usuários comuns preparam e editam rascunhos dentro do seu escopo. Administradores podem registrar o aditivo após conferência e editar registros conferidos. O registro marca a vigência do contrato para revisão e inclui o contrato no relatório de alterações. **Não aplica valores, porcentagens, acumulados ou datas ao contrato.** A [conferência administrativa](16_FUNCOES_INTERNAS_E_VISUAL.md) permite informar explicitamente término e valor acumulado; cálculos automáticos dependem de regras de negócio homologadas. Remover um aditivo ou devolvê-lo a rascunho não limpa automaticamente a pendência de revisão.

## Documentos, histórico e concorrência

É possível associar um documento privado ativo do mesmo contrato. O download conserva as verificações de escopo, integridade e auditoria. Se o documento for removido, a referência histórica permanece e o download fica indisponível até sua restauração.

Somente administradores excluem e restauram aditivos, mediante número de confirmação e motivo. A exclusão é lógica. O número continua reservado dentro do contrato, inclusive entre excluídos. Excluir o contrato impede o acesso a seus aditivos; restaurá-lo recupera o acesso aos ativos.

Todas as gravações registram ator, justificativa e alterações na auditoria contratual, na mesma transação. Cada operação incrementa a versão do aditivo e do contrato; uma versão desatualizada impede a gravação. Em conflitos de cadastro/edição, o formulário preserva os valores digitados e exige recarregar os dados antes de confirmar.

## Instalação e validação

Aplique `php artisan migrate --force` para criar `contract_amendments` com a migration `2026_10_11_000001`. Reinicie os workers com `php artisan queue:restart` para carregar a atualização dos relatórios. Backups existentes passam a incluir automaticamente a nova tabela.

Validação local: suíte SQLite com 77 testes e 935 asserções; seleção MySQL em schema isolado com 22 testes e 276 asserções. O navegador validou criação, edição, registro, associação/download de documento, exclusão/restauração, permissões, preservação dos valores/datas do contrato e formulário móvel. Veja [registro de validação](12_REGISTRO_DE_VALIDACAO.md).
