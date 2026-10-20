# Extrato de Locação de Imóvel

O botão **Extrato** abre o PDF em nova aba para contratos salvos do tipo **Locação de Imóvel**, além do modelo existente de Fornecimento. Salve alterações pendentes antes da emissão. O botão Contrato completo continua disponível apenas para Fornecimento.

Modelo original preservado em `Reference/Modelos_Doc/Extratos/Extrato_Contrato/Extrato_Loc_Imovel.docx`; cópia de execução em `resources/documents/extracts/property-lease.docx`. Ambos devem acompanhar a entrega. Não exige migration ou importação de dados.

Conferência com `Get-FileHash`: original e cópia possuem SHA-256 `0523686DC8A77C5987BDE28116D73F3030D6714722F1F123C9BDE5DC6583B42C`.

| Marcador | Campo salvo |
| --- | --- |
| `#FUNDO`, `#ADM` | Nome do Fundo no contrato e administração documental vigente |
| `#Contrato`, `#Processo`, `#Licitacao`, `#N_Licit` | Número, processo, modalidade e número da licitação |
| `#NomeLocador`, `#CNPJ` | Nome e documento do fornecedor/locador, com rótulo CPF ou CNPJ e mascaramento por perfil |
| `#EndImovel`, `#Instalacoes` | `end_imovel` e `instalacoes` |
| `#Vigencia`, `#Início`, `#Final` | Vigência informada, início e término original (`vigencia_fim_original`, campo Final da Vigência do painel) |
| `#VLMensal`, `#ExtMensal` | `vl_parcial` (Valor_Mensal na ficha) e `ext_parcial` |
| `#VLTotal`, `#ExtTotal` | `valor_total` inicial e `ext_total`; não usa valor acumulado nem calcula mensalidades |
| `#NomeGestor`, `#CPFGestor`, `#Cargo` | Gestor salvo no contrato: `nome_gestor`, `cpf_gestor` e `cargo_gestor`, como exibido no painel Dados Contratante |
| `#DataContrato` | Data de formalização salva, em dia/mês/ano |

O texto do objeto vem dos marcadores de endereço/destinação do Word fornecido. Não substitui o objeto salvo nem altera valores, datas ou outros dados do contrato. Campos ausentes aparecem como Não informado. Texto é escapado e o interpretador reúne marcadores divididos entre runs. Objetos extensos podem ocupar mais páginas.

Regra final corrigida pelo usuário: usar **Final da Vigência original** e o **gestor registrado no contrato**. A escolha anterior de Final_Vig_Atualizada foi substituída. O Extrato de Locação não substitui esse gestor pelo cadastro atual do Executivo municipal nem depende da existência de Prefeito/delegado atual para emitir. Campos ausentes do gestor ou término original permanecem Não informado. O Extrato de Fornecimento também usa o gestor salvo e o término original. Publicação e Contrato completo preservam suas regras.

## Compatibilidade e segurança

`GET /contracts/{contract}/extract?version=...` mantém sua assinatura. `SupplyContractExtract` foi estendido incrementalmente para selecionar o modelo exclusivamente pelo tipo persistido, depois de bloquear ator e contrato e autorizar `issue`. O nome histórico da classe e a assinatura de `generate` permanecem compatíveis.

`SupplyExtractTemplate::path($model = 'supply')` e `render($fields, $model = 'supply')` aceitam somente as chaves internas `supply` e `property-lease`. O padrão preserva o Fornecimento. Não recebem caminho arbitrário do cliente. A cópia específica de Locação trata também `#FUNDO` em maiúsculas e a linha de objeto com `#EndImovel`, permitindo paginação fora de células indivisíveis.

O PDF é renderizado fora da transação de escrita. Antes da entrega, são revalidados ator, permissão, escopo, perfil, versão, tipo/modelo, responsável, administração e hash do DOCX selecionado. Auditoria `contract_extract_generated` permanece transacional e registra `model: property-lease`, hashes e identificadores, sem dados pessoais em claro. O endpoint mantém cache privado, `nosniff` e nome `extrato-locacao-imovel-{id}.pdf`.

Na Locação, a impressão digital do responsável usa somente nome, CPF e cargo salvos no contrato. Esses campos são relidos sob lock na transação final: qualquer mudança durante a renderização impede entrega e auditoria, mesmo sem alteração de versão. CPF mantém o mascaramento por perfil. O Extrato de Fornecimento usa a mesma projeção e revalidação do gestor salvo.

## Validação

`php artisan test --filter='SupplyContractExtractTest|ExecutiveSignatoryTest'`, via container `app`: 27 testes, 195 asserções, aprovados. Cobre PDF de uma página para texto curto, marcadores, valores exatos salvos, preservação do contrato, CPF/CNPJ, ausência de campos, texto longo, escape de HTML, escolha persistida mesmo com parâmetros divergentes na URL, botão e modelo correto na auditoria. Testes de escopo, revogação durante renderização, sessão/versão/administração e falha de auditoria foram executados para ambos os tipos. A expectativa antiga de recusa de Locação foi substituída por Locação de Veículo, que continua sem modelo.

Prévia sintética: `php build/supply-preview/property-lease-preview.php`, sem escrita no banco. PDF gerado em `build/supply-preview/property-lease-extract.pdf`; página rasterizada com `pdftoppm` e conferida visualmente; conteúdo extraído com `pdftotext`. O diretório é ignorado pelo Git. A prévia não representa emissão autenticada. Os testes usam SQLite em memória e storage/cache temporários.

A primeira tentativa de prévia por stdin do PowerShell perdeu o acento na chave `#Início` e foi recusada pelo interpretador. A prévia final passou a usar arquivo PHP UTF-8, gerando uma página com os campos corretamente preenchidos; não houve alteração no modelo para contornar a validação.

Verificação final via Docker Compose: `composer check` passou no Pint (299 arquivos) e PHPUnit (615 testes, 6.043 asserções, sem falhas, um ignorado); `composer check:architecture` passou (3 testes, 4 asserções); `composer test:unit` passou (77 testes, 169 asserções); `npm run build` concluiu; `npm test` passou (90 testes). `git diff --check` passou. Não houve migration, importação, emissão manual autenticada ou alteração de dados do banco instalado.

## Conferência dos campos do painel

Consulta somente leitura dos contratos municipais `031/23`, `056/23`, `037/24`, `045/25` e `105/25` e revisão do mapeamento em `SupplyContractExtract` confirmaram:

- Nome/documento do locador, Fundo, processo, modalidade, licitação, endereço, instalações, vigência textual, início, mensalidade/extenso, valor inicial/extenso e formalização vêm dos mesmos atributos salvos exibidos na ficha.
- O término do extrato corresponde a **Final_Vig_Atualizada** quando preenchida, com fallback para **Final da Vigência**. São campos distintos no painel. Exemplo: `045/25` mostra término original `31/12/2025` e atualizado `31/12/2026`; o PDF usa o atualizado.
- Na conferência inicial, nome, CPF e cargo do gestor municipal seguiam o responsável atual autorizado por `ManagerSignatory`, enquanto o painel exibia o snapshot salvo. Nos cinco contratos consultados esses três campos diferiam entre o snapshot e o responsável atual. Após a definição do usuário, o Extrato de Locação passou a usar o snapshot do contrato.
- O objeto segue o texto do Word, composto por endereço, instalações e vigência; não é cópia literal do campo `objeto`. A administração é a configuração documental vigente, fora da ficha de dados do contrato.

Foi solicitada definição do usuário, posteriormente confirmada: manter o término atualizado e usar o gestor salvo no contrato. A conferência inicial não alterou regras ou dados; executou `docker compose exec -T app php artisan test --filter='SupplyContractExtractTest|ExecutiveSignatoryTest'`: 27 testes, 195 asserções, passou. `git diff --check` passou; `Get-FileHash` confirmou que modelo original e cópia continuam idênticos. Os testes usam SQLite temporário, incluindo emissão por endpoint com usuário fictício; não houve emissão manual autenticada no banco instalado.

Validação específica após o ajuste do gestor: `php artisan test --filter='SupplyContractExtractTest|ExecutiveSignatoryTest' --stop-on-failure`, via container `app`, passou com 31 testes e 213 asserções. Novos cenários cobrem gestor histórico diferente do cadastro atual, ausência de cadastro municipal atual, término atualizado preservado, CPF mascarado e mudança de nome/CPF/cargo durante renderização sem incremento de versão. Pint passou nos dois PHP alterados. Na primeira execução, o usuário fictício do teste de mascaramento não recebeu a permissão porque ela não é permitida em mass assignment; a preparação passou a concedê-la explicitamente, preservando a verificação de autorização.

Verificação final do ajuste: `composer check` passou no Pint (299 arquivos) e PHPUnit (622 testes, 6.128 asserções, sem falhas, um ignorado). `composer check:architecture`: 3 testes, 4 asserções, passou; `composer test:unit`: 77 testes, 169 asserções, passou; `npm run build` via container `node`: passou; `git diff --check`: passou. Consulta diagnóstica somente leitura nos cinco contratos municipais confirmou nome/CPF/cargo iguais ao snapshot e os mesmos términos atualizados. Nenhum dado do banco instalado foi alterado; testes executados com SQLite e storage/cache temporários.

Correção posterior solicitada pelo usuário: o término do Extrato de Locação passou a usar exclusivamente `vigencia_fim_original`, mantendo o gestor salvo. Datas atualizadas diferentes foram usadas nos testes para confirmar que não aparecem no extrato de Locação. Fornecimento continua usando a regra anterior. `php artisan test --filter='SupplyContractExtractTest|ExecutiveSignatoryTest' --stop-on-failure`: 31 testes, 214 asserções, passou. Pint dos três PHP envolvidos, `composer check:architecture` (3 testes, 4 asserções), `composer test:unit` (77 testes, 169 asserções), `npm run build` e `git diff --check` passaram. Comandos executados via Docker Compose. A suíte completa não foi repetida nesta correção pontual da projeção de data; a execução completa anterior está registrada acima. Nenhuma data ou outro dado do banco instalado foi alterado.
