# Publicação do contrato

O botão **Publicação** gera um PDF privado em uma nova aba para contratos salvos, usando o modelo fornecido para o Diário Público. A cópia original em Reference/Modelos_Doc/Extratos/Public_Diario/Public_Diario.docx permanece intacta; a cópia de execução fica em resources/documents/extracts/publication.docx. Alterações pendentes precisam ser salvas antes de emitir.

Ao abrir contratos salvos, sair de Tipo ou de outro campo de origem sem alterar seu valor preserva o Objeto histórico. Isso evita que um clique em Publicação gere uma alteração automática e bloqueie a emissão indevidamente. Mudanças reais nos campos de origem continuam atualizando a sugestão e exigindo salvar antes de emitir; formatação equivalente de CPF/CNPJ não conta como mudança de origem.

Validação da correção (26/10/2026): `docker compose run --rm --no-deps node npm test` passou com 144 testes; `docker compose run --rm --no-deps node npm run build` e `git diff --check` passaram. Reprodução em Edge headless com cópia local da tela e HTTP simulado confirmou o bloqueio antes da correção, a abertura da URL de publicação sem alterações após a correção e a manutenção do bloqueio quando um campo de origem é realmente editado. A conferência somente de leitura do modelo e dos dados persistidos também renderizou um PDF válido; não foi chamada a emissão auditada nem houve gravação no contrato ou no banco instalado. A cópia temporária da tela foi removida após o diagnóstico.

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
