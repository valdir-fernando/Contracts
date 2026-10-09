# Cadastro e edição de contratos

O menu Contratos agora oferece Novo contrato e o detalhe oferece Editar contrato. Os formulários seguem as sete seções do mapeamento dos 80 campos. Código de origem e snapshots do fundo/secretaria são exibidos como campos de leitura: contratos nativos não recebem código legado fictício, e os dados do órgão são obtidos do catálogo ao cadastrar ou transferir o vínculo. A migration mantém os contratos importados e torna a origem nullable para novos registros.

Para novos contratos, são obrigatórios número, processo, identificação do fornecedor, fundo, objeto, data do contrato, início/fim original, tipo e valor inicial. CPF/CNPJ deve ter dígitos válidos; datas devem ser reais, a partir do ano 1000, e o término não pode anteceder o início. Valores BR e decimais são convertidos sem float, com até quatro casas. Exceções de obrigatoriedade por tipo ainda dependem de parametrização/homologação.

Na edição, campos omitidos permanecem preservados. Campos históricos ausentes e documentos inválidos inalterados não bloqueiam uma correção parcial. Alterar datas exige cronologia válida; toda edição exige justificativa. Os dados brutos da importação não são sobrescritos. A seleção inalterada de órgão não substitui a grafia histórica; administradores podem manter vínculos históricos ainda não conciliados. Pendências antigas continuam registradas como histórico, e uma edição não equivale à sua conciliação.

Administradores escrevem em qualquer órgão. Usuários comuns só criam e editam dentro de fundos/secretarias autorizados; a transferência do vínculo também verifica o órgão de destino. Contas inativas, sem escopo ou que perderam o acesso são bloqueadas. Dados pessoais mascarados na edição ficam somente para leitura de usuários comuns, e alterações desses campos são rejeitadas no servidor. A auditoria completa no detalhe é exclusiva de administrador.

`ContractWriter` aplica transação, bloqueio da conta/contrato, autorização atualizada, validação e auditoria. Cada gravação incrementa `version`. Uma versão desatualizada retorna HTTP 409, preserva os valores digitados na tela e bloqueia o botão de salvar até recarregar e revisar o registro. Campos enviados para falsificar ator, vínculos derivados ou códigos de origem não são utilizados.

`contract_audits` registra ator, ação, data, justificativa e diferenças anteriores/posteriores. Falha de auditoria desfaz a alteração do contrato. O detalhe mostra os últimos 20 eventos para administradores; os demais eventos permanecem no banco. Não há endpoint para editar/apagar eventos. Escritas diretas por SQL/Tinker ficam fora desta trilha.

Possível duplicidade por número + fundo + exercício + processo gera aviso após a gravação, sem impedir números repetidos legítimos. Indicadores de aditivos mantêm a vigência em revisão; a edição não concilia aditivos automaticamente.

Validação final: 39 testes, 424 asserções, formatação PHP validada e assets compilados. Migração aplicada no ambiente local. Os testes incluem campos específicos, moedas, datas, autorização e transferência de escopo, mascaramento, imutabilidade da origem, campos omitidos, legado incompleto, concorrência, falsificação de autoria, duplicidade e rollback da auditoria.

Próximas etapas: exclusão lógica e restauração com motivo/confirmação, conciliação de aditivos, relatórios e exportações. Não foram implementadas nesta entrega.
