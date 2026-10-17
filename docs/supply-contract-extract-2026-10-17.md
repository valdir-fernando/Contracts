# Extrato de Fornecimento

O botão Extrato abre um PDF em outra aba para contratos salvos do tipo Fornecimento. Outros tipos aguardam seus próprios modelos. Alterações pendentes no formulário devem ser salvas antes da emissão. Não exige migration adicional à configuração de administração dos documentos.

A cópia de execução do modelo fornecido é `resources/documents/extracts/supply.docx`. O original em `Reference/Modelos_Doc/Extratos/Extrato_Contrato/Extrato_Fornecimento.docx` permanece intacto. O adaptador lê o DOCX confiável, reúne textos divididos em runs do Word, substitui marcadores conhecidos e escapa os dados. Preserva o brasão e a estrutura da tabela em A4; não é um conversor genérico de Word. Objetos longos podem ocupar mais páginas.

| Marcador | Origem |
| --- | --- |
| #Fundo | fundo_nome_snapshot |
| #ADM | Administração configurada, inicialmente 2025/2028 |
| #Contrato | numero |
| #Processo | processo |
| #Licitacao / #N_Licit | modalidade / n_licit |
| #Fornecedor | fornecedor_nome_snapshot |
| #CNPJ | fornecedor_documento_snapshot, com prefixo CPF ou CNPJ e máscara correspondente |
| #Objeto / #Vigencia | objeto / vigencia |
| #Início | vigencia_inicio |
| #Final | vigencia_fim_atual, usando vigencia_fim_original quando ausente |
| #VLTotal | valor_total (VL_Total), moeda com duas casas |
| #ExtTotal | ext_total (Ext_Total) persistido |
| #NomeGestor / #CPFGestor / #Cargo | nome_gestor / cpf_gestor / cargo_gestor |
| #DataContrato | data_contrato |

Campos ausentes exibem “Não informado”. O valor por extenso não é recalculado. A emissão não altera o contrato. CPFs completos seguem a permissão administrativa existente; outros perfis recebem a proteção de dados pessoais aplicada pelo sistema. CNPJ usa formato público completo.

## Segurança e integração

`GET /contracts/{contract}/extract?version=...` exige autenticação, limita requisições e verifica o acesso atual ao contrato. `Infrastructure/Documents/SupplyContractExtract` bloqueia ator antes do contrato e verifica versão/tipo. A renderização ocorre fora da transação de escrita. Antes de entregar os bytes, revalida sessão, perfil, acesso, versão do contrato, configuração administrativa e hash do modelo. A auditoria `contract_extract_generated` registra identificadores, versões e hashes, sem copiar os dados pessoais.

A resposta é PDF privado com `Cache-Control: no-store, private`. Não há arquivo publicado nem URL permanente de armazenamento. Dompdf não executa PHP nem busca recursos remotos. Falha na auditoria impede a entrega.

## Validação

Testes de integração cobrem substituições, CPF/CNPJ, datas, administração, mascaramento e escopos, contrato excluído, versões antigas, alterações durante a renderização e falha da auditoria. O teste do PDF curto verifica uma página, prevenindo a regressão das colunas mescladas do Word. Testes JavaScript cobrem emissão, bloqueio de formulário alterado e atualização de versão após operações de produtos.

Uma amostra com dados fictícios foi renderizada e inspecionada visualmente em A4. Verificações executadas no Docker:

- `composer check`: 431 testes, 4.044 asserções, sem falhas; um teste de concorrência MySQL ignorado por exigir schema descartável isolado.
- Após o ajuste final da grade do PDF: `vendor/bin/pint` nos dois PHP alterados e `vendor/bin/phpunit --filter=SupplyContractExtractTest --do-not-cache-result`: 7 testes, 64 asserções, aprovados.
- `composer check:architecture`: 3 testes, 4 asserções, aprovados.
- `composer check:static`: sem erros.
- `npm test`: 49 testes aprovados.
- `npm run build` e `php artisan view:cache`: concluídos.
- `git diff --check`: sem erros de whitespace; avisos de conversão CRLF/LF em documentação existente.

As primeiras verificações identificaram uma asserção que desconsiderava a transação externa do RefreshDatabase e uma versão padrão ainda não recarregada no fixture; os fixtures foram corrigidos. A inspeção visual identificou colunas mescladas comprimidas pelo Dompdf; a grade explícita resolveu a quebra indevida em duas páginas. Nenhum teste foi executado no banco real.
