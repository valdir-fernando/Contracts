# Tipo da Obra

Em Novo Contrato e na edição, a aba **4. Dados do Objeto** apresenta **Tipo da Obra** ao lado de **Tipo de veículo**, que ocupa 60% da largura anterior. O controle reutiliza `tipo_obra`, sem migration. As opções são Empreitada por preço unitário; Empreitada por preço global; Empreitada integral; Contratação por tarefa; Contratação integrada; Contratação semi-integrada; Fornecimento e prestação de serviço associado.

A validação contextual aceita esses sete nomes completos em novos preenchimentos. Na edição, permite preservar o valor histórico do próprio contrato, inclusive GLOBAL, ou omitir o campo; não aceita um valor histórico de outro contrato como nova opção. A gravação permanece no fluxo transacional com versão, autorização e auditoria existentes. O valor salvo preenche `#EMPREITADA` no contrato de Obras.

O ícone de informação ao lado do título abre um popover com as sete explicações fornecidas na referência visual, seguindo o mecanismo de ajuda do IPTU. O botão não submete o formulário; o conteúdo permanece acessível por teclado e em telas menores.

Validação: Pint dos quatro arquivos PHP alterados passou; os testes focados de tipo de obra, preservação histórica e IPTU passaram (4 testes, 87 asserções), com SQLite em memória e storage/cache isolados.

Após a definição dos sete regimes: testes focados de seleção/gravação de todas as opções, preservação histórica e preenchimento do PDF de Obras passaram (4 testes, 117 asserções); Pint dos quatro arquivos PHP atualizados passou.
