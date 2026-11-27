# Consolidação dos aditivos de ATA

A migration `2026_11_24_000001_consolidate_ata_amendments.php` prepara `tb_ADT_ATA` como cadastro único dos aditivos usados pelo painel, relatórios, documentos e comandos de importação/manutenção. `tb_ADITIVOS_ATA` permanece intacta como arquivo da importação anterior; os fluxos da aplicação deixam de consultá-la ou gravá-la. A migração de dados é a única leitora da tabela antiga.

## Dados e vínculos

Os IDs de `tb_ADT_ATA` são preservados, inclusive os vínculos dos PDFs assinados e das exclusões de produtos. O PDF binário `Doc_ADT`, os saldos, a vantajosidade e os demais campos existentes permanecem no registro. Os dados de origem passam para colunas adicionais dessa mesma tabela.

| Origem anterior | Campo no cadastro único |
| --- | --- |
| `NUMERO` | `ADT` |
| `PROCESSO_ORIGEM` | `Proc_ATA` |
| `PROCESSO_ADITIVO` | `Proc_ADT` |
| `INICIO_ADITIVO` | `INICIO` |
| `FINAL_ADITIVO` | `FINAL` |
| `ID` da importação | `imported_id` |
| `ID_ATA`, licitação, CNPJ e metadados de extração | Colunas de mesmo nome |
| Registro completo importado | `import_snapshot` |

O vínculo dos produtos passa a ser `tb_PRODUTOS_ATA.amendment_id`, com chave estrangeira para `tb_ADT_ATA.ID`. O campo anterior `ID_ADITIVO` e sua chave estrangeira permanecem como referência histórica; as novas gravações usam o vínculo canônico. Não há remoção de campos, tabelas, produtos ou documentos.

A união usa número normalizado da ATA, processo, número do aditivo e situação de exclusão, sem presumir igualdade entre IDs de tabelas diferentes. Divergências nos campos comuns, vínculos incompatíveis ou múltiplos candidatos interrompem a conversão. Documentos sem ATA confirmada mantêm a pendência, sem receber autorização de acesso apenas pela coincidência do número/processo. Registros excluídos permanecem excluídos.

As referências importadas em `validity_basis` são traduzidas para o ID canônico e conservam `imported_id` para rastreabilidade. A migração não muda datas de vigência, versões de negócio, valores ou conteúdo dos PDFs. Vínculos novos e a conversão são auditados na mesma transação. A execução pode ser repetida sem duplicar registros ou auditorias.

## Fluxos após a instalação

Salvar cria/atualiza apenas `tb_ADT_ATA`; produtos continuam na tabela própria. A origem importada deixa de impedir, por si só, a edição do último aditivo ativo. Continuam obrigatórios autorização, versão atual, identificação única e vigência conciliada. Edições de aditivos importados de tipo suportado preservam os produtos do mesmo tipo, sem duplicá-los.

O último aditivo ativo pode ser editado mantendo o tipo histórico gravado. Ao consultar um registro com `Prorrogação de vigência`, o campo **Tipo_ADT** exibe **Prorrogação/Quantitativo - Período e Valor Variável**. O servidor valida o tipo contra o aditivo salvo e aplica as regras de vigência da edição. A atualização mantém quantidades, preços, valores, PDFs, saldos, exclusões e a vantajosidade já registrada. Quando o tipo histórico tem produtos vinculados, a troca para uma modalidade atual exige conciliar os produtos e valores.

A importação documental e a conferência de PDFs passam a completar o mesmo cadastro, em qualquer ordem. O snapshot da origem permite conferir novamente o documento mesmo quando seus campos atuais foram corrigidos. A importação de produtos ignora aditivos já gravados pelo painel (`edited_in_panel_at`), para não recriar produtos retirados ou sobrescrever os dados corrigidos. Documentos conflitantes permanecem sujeitos à conferência, sem escolha arbitrária.

## Instalação

Aplicar com os fluxos de escrita parados e após backup verificado. O script `scripts/backup.ps1` documentado no README fornece o backup portátil completo. A migration pode ser executada isoladamente:

```powershell
docker compose stop web worker scheduler
docker compose exec -T app php artisan migrate --path=database/migrations/2026_11_24_000001_consolidate_ata_amendments.php --force
docker compose up -d web worker scheduler
```

DDL aditivo fica separado da transação de conversão, pois MySQL não faz rollback de alterações de estrutura. Se houver divergência, os dados não são parcialmente convertidos; as colunas novas podem permanecer e a migration pode ser retomada após conferir a causa. O método `down` preserva todos os dados. Não usar rollback destrutivo ou restaurar banco sem procedimento próprio.

Código e migration devem ser instalados juntos. A versão nova exige as colunas adicionais. Para revisão sem aplicar no banco operacional, manter a implementação em checkout separado da aplicação em execução.

## Validação

Ensaios em SQLite em memória e storage/cache temporários cobrem união com IDs diferentes, preservação binária e decimal, exclusões lógicas, pendências de vínculo, divergências, rollback por falha de auditoria, repetição e edição importada sem duplicação de produtos. A importação e a conferência também são exercitadas nas duas ordens.

O ensaio MySQL usou um schema descartável separado, com cópia local: 48 registros reunidos e 675 produtos vinculados. Comparação integral das colunas anteriores de `tb_ADT_ATA`, `tb_PRODUTOS_ATA` e `tb_ADITIVOS_ATA` confirmou sua preservação, incluindo PDFs. A consulta autorizada da ATA 107/2025 retornou o aditivo 1. Nenhuma conversão foi aplicada ao banco operacional nessa validação.

Comandos executados:

- `php vendor/bin/phpunit --filter='ConsolidateAtaAmendmentsTest|AtaEditingTest|AtaLookupTest|AtaAmendmentProductsTest|AtaAmendmentWordTest|AtaSignedDocumentsTest|UpdateAtaValidityTest|ReconcileAtaProcessValidityTest|RegisterAtaAmendmentsTest|AtaReferenceImportTest|AtaReportsTest|AtaSummaryTest' --do-not-cache-result --colors=never`: 144 testes, 1.294 asserções aprovados, como `www-data` no Docker.
- `composer check:architecture`: 3 testes, 4 asserções; `composer test:unit`: 89 testes, 223 asserções; `composer check:static`: sem erros.
- Pint nos 25 PHP envolvidos, `npm.cmd test` (332 testes), `npm.cmd run build` e `git diff --check`: aprovados.
- `composer check`, com timeout desativado: Pint passou em 525 arquivos; PHPUnit executou 1.240 testes e encontrou um erro, 16 falhas e um teste ignorado. Duas causas da implementação foram corrigidas durante a validação e confirmadas pela repetição da suíte dirigida. Os outros 15 casos foram reproduzidos no código original HEAD, em checkout isolado: 17 casos executados, 15 falhas e dois aprovados. A suíte geral permanece sem aprovação.

## Aplicação local autorizada

Em 24/11/2026, após autorização para aplicar com backup, `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/backup.ps1` gerou `backup/20261124-162949-434`. Os quatro arquivos do manifesto (banco com PDFs binários, storage, ambiente/chave e código/referências) tiveram seus hashes SHA-256 conferidos novamente antes da aplicação. O comando foi acompanhado pelo log de conclusão e pelo manifesto, pois o redirecionamento do stderr do Docker no PowerShell reportou código 1 apesar da conclusão do script e dos arquivos íntegros.

Com web, worker e scheduler parados, a migration específica concluiu no lote 54. Foram consolidados 48 aditivos, vinculados 675 produtos e gravada uma auditoria. A comparação de hashes dos campos anteriores confirmou que `tb_ADT_ATA`, `tb_ADITIVOS_ATA` e `tb_PRODUTOS_ATA` conservaram integralmente os dados anteriores; as versões e datas das ATAs e a soma decimal dos produtos também permaneceram iguais. Foram adicionados somente os metadados/vínculos da consolidação e as referências canônicas da base de vigência.

`php artisan view:cache` passou. Os serviços foram reiniciados e saúde/login responderam HTTP 200. `php artisan contracts:check-operations` confirmou banco e fila acessíveis, atividade recente do worker e do scheduler e zero jobs falhos. O schema MySQL e o checkout usados para comparação foram removidos após as verificações. Sem ensaio visual autenticado, commit, publicação ou alteração dos tipos históricos.

Para a edição do tipo histórico, `php vendor/bin/phpunit --filter='ConsolidateAtaAmendmentsTest|AtaEditingTest|AtaRegistrationTest|AtaLookupTest' --do-not-cache-result --colors=never` passou no Docker com SQLite em memória (68 testes e 767 asserções). Também passaram `composer check:architecture`, `composer test:unit`, `composer check:static`, Pint nos PHP alterados, `npm.cmd test` (333 testes), `npm.cmd run build`, `php artisan view:cache` e `git diff --check`. No Chrome, um formulário com dados fictícios carregou e enviou o tipo histórico na edição com a requisição interceptada. A conferência da ATA 107/2025 no banco local foi somente leitura. A suíte geral não foi repetida nesta correção.

## Reclassificação dos aditivos locais

Uma operação administrativa pelo CLI atualizou os 48 registros com `Prorrogação de vigência` para **Prorrogação/Quantitativo - Igual Período e Valor** em `tb_ADT_ATA`. A auditoria `274` (`ata_amendment_types_reclassified`) registra os valores anteriores e os IDs alcançados. O lote incluiu 22 aditivos com vínculo pendente. As versões das 26 ATAs vinculadas foram incrementadas para invalidar formulários abertos antes da alteração.

A operação foi ensaiada com SQLite em memória, incluindo reversão integral diante de falha da auditoria e repetição sem novas escritas. A execução local usou `docker compose exec -T -u www-data app php` com o script via entrada padrão. A consulta após o commit confirmou os 48 novos tipos e zero ocorrências do tipo anterior no cadastro. A comparação de hashes confirmou a integridade dos 675 produtos vinculados e dos 47 PDFs assinados. As datas de vigência e a origem documental foram preservadas. A verificação HTTP de `/up` retornou 200.
