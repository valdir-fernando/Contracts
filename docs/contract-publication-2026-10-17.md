# Publicação do contrato

O botão **Publicação** gera um PDF privado em uma nova aba para contratos salvos, usando o modelo fornecido para o Diário Público. A cópia original em Reference/Modelos_Doc/Extratos/Public_Diario/Public_Diario.docx permanece intacta; a cópia de execução fica em resources/documents/extracts/publication.docx. Alterações pendentes precisam ser salvas antes de emitir.

| Marcador | Campo usado |
| --- | --- |
| #FUNDO | Nome do Fundo no snapshot do contrato |
| #ADM | Administração atual configurada nos documentos |
| #CONTRATO | Número do contrato |
| #TIPO | Tipo do contrato |
| #PROCESSO | Processo |
| #MODALIDADE / #N_LICIT | Modalidade / número da licitação |
| #FORNECEDOR | Fornecedor do snapshot |
| #CNPJ_FORNECEDOR | Documento do fornecedor com rótulo e formato CPF ou CNPJ |
| #OBJETO | Objeto |
| #VALOR | Valor inicial, em moeda brasileira |
| #EXTENSO | Valor por extenso persistido |
| #DATA | Data do contrato |
| #NOME_GESTOR / #CPF_GESTOR / #CARGO_GESTOR | Dados do gestor |

Valores ausentes aparecem como “Não informado”. CPF segue as regras de proteção por perfil; CNPJ mantém a apresentação formatada. A emissão não altera dados do contrato.

O endpoint autenticado reautoriza o acesso e a versão do contrato. A renderização ocorre fora da transação e a sessão, o acesso, a versão, a administração e o hash do modelo são conferidos novamente antes da entrega. Uma auditoria guarda versões e hashes, sem os dados pessoais. O PDF usa private, no-store; Dompdf não executa PHP nem busca recursos remotos.
