# Dotação, fichas e empenhos — 11/10/2026

No detalhe do contrato, abra **Dotação, fichas e empenhos**. Há três cadastros separados, todos vinculados explicitamente ao contrato e ao seu escopo:

- **Dotação:** identificador nativo e os 14 campos de conteúdo de `tb_Dotacao`, totalizando os 15 campos representados. Órgão, gestão, unidade, função, subfunção, programa, projeto, fonte, código, ficha, descrição, dotação, fundo e secretaria permanecem disponíveis. `Dotacao` é texto de classificação informado, não um valor monetário presumido.
- **Valor por ficha:** identificador nativo e os oito campos de conteúdo de `tb_ValorFicha`, totalizando os nove campos representados. Preserva ficha, valor, fundo, contrato, documento do fornecedor, processo do contrato e referências textuais ao aditivo.
- **Empenho:** número informado, ficha, valor, objeto, processo e observação, com snapshots do contrato e das partes. É um registro documental interno; não emite autorização de empenho nem realiza pagamentos.

Cada registro também tem identificador administrativo, exercício e data. O identificador é único dentro do contrato, tipo e exercício, inclusive entre excluídos. Números e classificações permanecem textos, com zeros iniciais. Valores aceitam formato brasileiro ou decimal não negativo com até quatro casas, preservadas no banco. A apresentação monetária arredonda para centavos.

## Conferência e associações

Usuários preparam rascunhos dentro do escopo atual. Administradores registram após conferência e editam registros conferidos. Dotação e aditivo podem ser associados por seleção explícita, sempre dentro do mesmo contrato. Referências textuais legadas não se transformam automaticamente em relações. A associação não exige que a referência já esteja conferida; o administrador deve conferir o conjunto antes de registrar.

Na listagem, filtre tipo, exercício e registros atuais/excluídos. As somas consideram somente registros conferidos no filtro selecionado. Valores por ficha e empenhos são totalizados separadamente, com aritmética decimal exata. Não constituem saldo disponível, saldo contratual, pagamentos ou acréscimos ao contrato. Os valores e datas do contrato permanecem preservados.

Fundo, secretaria, contrato, processo contratual e documento do fornecedor são preenchidos pelo servidor, quando aplicáveis, e preservados como snapshots. O CPF do fornecedor fica protegido para usuários comuns. Campos do legado são representados no novo cadastro, mas a carga real das tabelas financeiras e sua conciliação dependem do snapshot e da homologação da área; não se presume que registros nativos sejam registros já migrados.

## Histórico e operação

Excluir/restaurar exige administrador, identificador de confirmação, motivo e versões atuais. A exclusão é lógica; associações existentes permanecem históricas quando sua referência é excluída. Contratos excluídos deixam de expor os registros. Todas as gravações usam transação, auditoria e controle concorrente da versão do contrato e do registro.

A migration `2026_10_11_000002` cria `contract_financial_records`. O backup inclui automaticamente a tabela e suas associações. Testes cobrem campos, escopo, snapshots, valores, vínculos entre contratos, concorrência, totais, exclusão/restauração e rollback na falha de auditoria.
