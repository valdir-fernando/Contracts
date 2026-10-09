# Consulta de contratos

O menu Contratos e o atalho no início levam a `/contracts`. A consulta usa os contratos já importados, com paginação de 20 registros, ordem decrescente de exercício e identificador, busca por número/processo/fornecedor/documento e filtros combináveis de fundo, secretaria, exercício e vigência. A navegação entre páginas preserva os filtros.

Usuários comuns consultam somente contratos com órgão conciliado e vínculos autorizados. As opções de fundos, secretarias e exercícios também vêm apenas dos contratos acessíveis, impedindo a exposição de catálogos de outros órgãos. Contas sem vínculo válido recebem uma lista vazia. Administradores consultam todos os contratos não excluídos, inclusive aqueles com conciliação pendente.

`/contracts/{id}` aplica a ContractPolicy no servidor: acessar diretamente um contrato de outro escopo retorna 403, e contratos excluídos logicamente retornam 404. Visitantes e contas inativas são redirecionados ao login. As rotas de escrita de contratos ainda não existem.

A tela de detalhes apresenta os 80 campos do mapeamento, agrupados em sete seções, e as ocorrências de qualidade registradas na importação. Dados faltantes aparecem como Não informado. Textos de origem são escapados; documentos pessoais são mascarados para contas comuns, inclusive na listagem. Os valores exibidos usam arredondamento decimal exato para duas casas, preservando as quatro casas persistidas. Valor inicial e acumulado continuam separados.

O status de vigência informa a data de referência e não substitui a situação jurídica/administrativa. Contratos com aditivos pendentes continuam Em revisão; datas insuficientes geram Indeterminado. O detalhe mantém visíveis os dados históricos de distrato e paralisação.

Validação: 30 testes, 333 asserções; Pint validado em 35 arquivos PHP; build Vite concluído com assets estáticos. Os testes incluem números repetidos, busca por documentos formatados e normalizados, filtros combinados, escopos e opções restritas, URL direta, contas inativas, campos completos, mascaramento, escape HTML, exclusão lógica e paginação.

Cadastro, edição, controle de concorrência e auditoria foram implementados na entrega seguinte: [Cadastro e edição de contratos](10_CADASTRO_EDICAO_CONTRATOS.md). Exclusão/restauração, conciliação de aditivos e relatórios continuam pendentes.
