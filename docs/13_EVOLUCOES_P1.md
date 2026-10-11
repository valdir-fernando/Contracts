# Evoluções P1 — documentos, calendário, alertas, pesquisa e cadastros

## Documentos privados

No detalhe do contrato, **Documentos privados** recebe PDF, JPEG e PNG de até 10 MB. Informe um nome legível e selecione o arquivo. O servidor valida extensão e conteúdo; nomes enviados não determinam o caminho de armazenamento. Os arquivos ficam em `storage/app/private/contract-documents`, dentro do volume privado incluído nos backups.

Usuários com permissão de editar o contrato podem anexar. O download exige o escopo atual do contrato, confere o SHA-256 e registra auditoria. A resposta força download, sem cache. Não há URL pública de arquivo. Uma mudança de órgão ou escopo afeta imediatamente os acessos seguintes.

Só administrador remove ou restaura documentos, mediante ID de confirmação e motivo. A remoção é lógica e preserva o arquivo. Excluir o contrato impede downloads; restaurá-lo recupera o acesso aos documentos ativos. A política institucional de retenção precisa ser definida antes de qualquer descarte definitivo. Falhas de auditoria revertem a operação; falhas de upload removem o arquivo recém-gravado.

## Calendário e alertas

**Calendário e alertas** apresenta contagens por dia e uma lista paginada dos vencimentos no mês escolhido. Usa o término atualizado quando informado; caso contrário, o original. Considera contratos acessíveis, ativos na lista, com início e término conhecidos e sem revisão de vigência. Contratos em revisão ou com vigência indeterminada aparecem nas contagens de pendências, sem vencimento inventado.

Cada usuário pode ativar/desativar os avisos e escolher antecedência de 1 a 365 dias, padrão 30. O painel mostra o total e os cinco primeiros contratos que vencem entre hoje e a antecedência escolhida, inclusive os limites. Esses avisos são internos à aplicação e recalculados em cada acesso. E-mail, SMS e notificações externas não foram adicionados.

A classificação administrativa continua distinta da situação temporal. O calendário não certifica situação jurídica nem altera datas, valores ou aditivos.

## Pesquisa avançada

Na listagem, abra **Pesquisa avançada** para combinar objeto, empenho e ficha com tipo, modalidade, situação administrativa, período e faixa de valor inicial. Escolha assinatura, início ou término informado para o período. Valores aceitam formato brasileiro ou decimal com até quatro casas, sem conversão para ponto flutuante.

**Abrir estes filtros nos relatórios e exportações** transporta os filtros para a tela de relatórios. Os controles permanecem visíveis nessa tela e são mantidos no CSV/XLSX/PDF. Listagem e relatório compartilham o processamento da pesquisa avançada. Opções de tipo e modalidade provêm apenas dos contratos acessíveis.

## Cadastro assistido de fornecedores

No cadastro do contrato, **Selecionar fornecedor cadastrado** abre uma lista pesquisável e paginada. O fornecedor escolhido preenche nome e documento no novo formulário. O usuário pode revisar os dados antes de salvar. O vínculo interno só é atribuído quando o documento corresponde a um único cadastro, preservando a regra de ambiguidades.

Administradores consultam todo o catálogo e cadastram fornecedores em **Órgãos e permissões**. Usuários comuns consultam apenas fornecedores com CNPJ vinculados a contratos do seu escopo. Cadastros de pessoas físicas não são expostos nessa seleção. Buscar ou fabricar o ID de outro cadastro não amplia o acesso.

## Requisitos por tipo e formatos de documentos

Administradores configuram **Requisitos por tipo**, com justificativa auditada. Além dos campos básicos, podem exigir endereço de imóvel, dados/tipo de veículo, cargo de credenciamento, local de serviço ou tipo/prazo de obra. O nome identifica a configuração sem diferenças de caixa ou acento. Nenhum requisito específico foi presumido como regra jurídica padrão.

As regras são aplicadas ao cadastrar contrato ou alterar seu tipo. Não impedem a manutenção de registros históricos com o mesmo tipo. O formulário apresenta os requisitos e sugere tipos configurados. CPFs de sócio, gestor e fiscal recebem validação de dígitos verificadores nas novas entradas; valores históricos inalterados continuam preservados. Campos CPF/CNPJ são formatados ao sair do campo, sem converter identificadores em números.

## Operação

As migrations `000004`, `000005` e `000006` adicionam documentos, preferências e requisitos por tipo sem alterar os registros legados. Aplique `php artisan migrate --force` na instalação existente. O `/ready` também verifica a leitura da tabela de fila e retorna 503 quando ela não está disponível.

## Conclusão interna do backlog

O [cadastro de aditivos](14_ADITIVOS.md), [dotação/fichas/empenhos](15_DOTACAO_FICHAS_E_EMPENHOS.md) e [conferência administrativa](16_FUNCOES_INTERNAS_E_VISUAL.md) completam as funções internas P1. Valores e datas conferidos são informados explicitamente; cálculos automáticos exigem regras homologadas. P2 interno e pendências externas estão no [estado final](17_ESTADO_FINAL_E_PENDENCIAS.md).
