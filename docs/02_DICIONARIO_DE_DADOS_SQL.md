# Dicionário do SQL legado e mapeamento de contratos

**Fonte:** `u910323952_bdgestao_niq.sql` — estrutura `CREATE TABLE`, registros `INSERT` e índices.  
**Data da análise:** 09/10/2026. **NÃO contém credenciais, CPFs ou dados pessoais concretos.**

> O dicionário abaixo documenta **o SQL existente**. Tipos de destino, validações e os relacionamentos identificados são **propostos**, não elementos preexistentes no dump. O dump original não define chaves estrangeiras.

## 1. Indicadores estruturais

- **46 tabelas** com **546 colunas**.
- **27.190 linhas de dados** identificadas nas instruções `INSERT INTO`.
- Identificadores originais em geral `int NOT NULL` + `AUTO_INCREMENT` aplicados via `ALTER TABLE`.
- O banco tem `utf8mb4` e não possui declarações `FOREIGN KEY`.

## 2. Inventário de tabelas

| Tabela original (respeitar caixa) | Registros no dump | Colunas | Função identificada | Prioridade |
|---|---:|---:|---|---|
| `tb_Aditivos` | 985 | 44 | Aditivos históricos | MVP consulta / fase 2 edição |
| `tb_ADM` | 1 | 3 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Caminho` | 1 | 2 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Cargo_Credenciado` | 0 | 2 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Config` | 1 | 2 | Módulo auxiliar do sistema legado | Referência / futuro |
| `TB_Contratos` | 2.145 | 80 | Contrato: cadastro, vigências e valores | MVP |
| `tb_Controle_Licit` | 35 | 9 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Descricao_Tipo_Contratos` | 0 | 4 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_DFD` | 5 | 21 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Dotacao` | 1.733 | 15 | Dotação orçamentária | Fase 2 / consulta |
| `tb_Fiscal` | 15 | 14 | Fiscais de contrato | MVP catálogo |
| `tb_Fornecedor` | 1.175 | 39 | Cadastro de fornecedores | MVP catálogo |
| `tb_Funcionario` | 144 | 18 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Fundo` | 8 | 8 | Fundos e entidades contratantes | MVP catálogo |
| `tb_Fundos_Licitantes` | 33 | 9 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Gestor` | 8 | 17 | Gestores e representantes | MVP catálogo |
| `tb_IRRF` | 5 | 8 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_IRRF_Simples` | 1 | 3 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Modalidade` | 8 | 2 | Modalidades licitatórias | MVP catálogo |
| `tb_N_Contrato` | 8 | 3 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_N_DFD` | 1 | 2 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_N_Licit` | 7 | 3 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_N_OS` | 6 | 3 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_N_Pedido` | 1 | 2 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_OS` | 22 | 13 | Ordens de serviço | Fase 2 |
| `tb_Pedidos` | 6 | 20 | Pedidos e autorizações | Fase 2 |
| `tb_ProcArquivo` | 1 | 13 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Processos` | 1 | 5 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Processo_Arquivos` | 19 | 11 | Metadados de arquivos de processos | Fase 2 |
| `tb_Produtos` | 3.307 | 13 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Produtos_Contratos` | 4.553 | 23 | Itens vinculáveis a contratos | MVP consulta |
| `tb_Produtos_DFD` | 22 | 13 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Produtos_Pedido` | 8 | 15 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Produtos_Prodata` | 10.659 | 6 | Catálogo externo de produtos | Fora do MVP |
| `tb_Recibos` | 734 | 26 | Recibos/retenções | Fora do MVP |
| `tb_RP_CRED` | 1 | 3 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Secretaria` | 15 | 11 | Secretarias municipais | MVP catálogo |
| `tb_SenhasWeb` | 8 | 5 | Segredos legados não devem ser migrados | EXCLUIR credenciais da migração |
| `tb_Solicitacao` | 0 | 22 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Teto_INSS` | 1 | 5 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Tipo_Contratos` | 11 | 2 | Tipos contratuais | MVP catálogo |
| `tb_Tipo_Objeto` | 0 | 2 | Módulo auxiliar do sistema legado | Referência / futuro |
| `tb_Usuario` | 17 | 8 | Usuários legados (senhas inseguras) | MVP migração segura |
| `tb_ValorFicha` | 1.462 | 9 | Valores/fichas ligados a contratos | Fase 2 / conciliação |
| `tb_VL_Depend` | 1 | 5 | Módulo auxiliar do sistema legado | Referência / futuro |
| `Tb_Web` | 16 | 3 | Módulo auxiliar do sistema legado | Referência / futuro |

## 3. Mapeamento integral dos 80 campos de `TB_Contratos`

**Cobertura:** 80/80 campos, organizados em 7 grupos. **Regra:** cada campo deve ter destino persistente e ser exibido para visualização/edição conforme permissões. `legacy_records` guarda ainda o bruto para verificação.

| Campo SQL original | Tipo SQL | Grupo de tela | Destino sugerido | Observação/conversão |
|---|---|---|---|---|
| `ID_Contrato` | `int(11)` | Identificação e licitação | `contratos.legacy_id` | ID legado único; não gerar novo número a partir dele |
| `Contrato` | `text` | Identificação e licitação | `contratos.numero` | Texto; não único globalmente |
| `Cont_Inteiro` | `text` | Identificação e licitação | `contrato_detalhes.cont_inteiro` | Texto histórico; não derivar de Contrato sem revisão |
| `Data_Cont` | `date` | Identificação e licitação | `contratos.data_contrato` | date nullable; `0000-00-00` → NULL |
| `Data_Cont_Verd` | `date` | Identificação e licitação | `contrato_detalhes.data_cont_verd` | Significado operacional a confirmar; permite NULL para legado |
| `Processo` | `text` | Identificação e licitação | `contratos.processo` | Preservar texto e limpar apenas para busca |
| `Licitacao` | `text` | Identificação e licitação | `contratos.modalidade` | Preservar texto e limpar apenas para busca |
| `N_Licit` | `text` | Identificação e licitação | `contratos.n_licit` | Preservar texto e limpar apenas para busca |
| `Proc_Edital` | `text` | Identificação e licitação | `contrato_detalhes.proc_edital` | Preservar texto e limpar apenas para busca |
| `N_Edital` | `text` | Identificação e licitação | `contrato_detalhes.n_edital` | Preservar texto e limpar apenas para busca |
| `Data_Licit` | `date` | Identificação e licitação | `contrato_detalhes.data_licit` | date nullable; `0000-00-00` → NULL |
| `N_Solicit` | `text` | Identificação e licitação | `contrato_detalhes.n_solicit` | Preservar texto e limpar apenas para busca |
| `CPF_CNPJ` | `text` | Fornecedor e representante | `contratos.fornecedor_documento_snapshot` | CPF/CNPJ do fornecedor, normalização apenas para busca |
| `Fornecedor` | `text` | Fornecedor e representante | `contratos.fornecedor_nome_snapshot` | Snapshot do fornecedor na celebração |
| `RG_Fornec` | `text` | Fornecedor e representante | `contrato_detalhes.rg_fornec` | Preservar texto e limpar apenas para busca |
| `Doc_Prof` | `text` | Fornecedor e representante | `contrato_detalhes.doc_prof` | Preservar texto e limpar apenas para busca |
| `PIS_PASEP` | `text` | Fornecedor e representante | `contrato_detalhes.pis_pasep` | Preservar texto e limpar apenas para busca |
| `End_Fornec` | `text` | Fornecedor e representante | `contrato_detalhes.end_fornec` | Preservar texto e limpar apenas para busca |
| `Socio` | `text` | Fornecedor e representante | `contrato_detalhes.socio` | Preservar texto e limpar apenas para busca |
| `CPF_Socio` | `text` | Fornecedor e representante | `contrato_detalhes.cpf_socio` | Preservar texto e limpar apenas para busca |
| `RG_Socio` | `text` | Fornecedor e representante | `contrato_detalhes.rg_socio` | Preservar texto e limpar apenas para busca |
| `CNPJ_Fundo` | `text` | Contratante gestão e fiscalização | `contratos.fundo_documento_snapshot` | Documento contratante histórico; resolver fundo assistidamente |
| `Fundo` | `text` | Contratante gestão e fiscalização | `contratos.fundo_nome_snapshot` | Preservar texto e limpar apenas para busca |
| `End_Fundo` | `text` | Contratante gestão e fiscalização | `contrato_detalhes.end_fundo` | Preservar texto e limpar apenas para busca |
| `CPF_Gestor` | `text` | Contratante gestão e fiscalização | `contrato_detalhes.cpf_gestor` | Preservar texto e limpar apenas para busca |
| `Nome_Gestor` | `text` | Contratante gestão e fiscalização | `contrato_detalhes.nome_gestor` | Snapshot de gestor, preservado em mudanças de cadastro |
| `RG_Gestor` | `text` | Contratante gestão e fiscalização | `contrato_detalhes.rg_gestor` | Preservar texto e limpar apenas para busca |
| `Cargo_Gestor` | `text` | Contratante gestão e fiscalização | `contrato_detalhes.cargo_gestor` | Preservar texto e limpar apenas para busca |
| `Secretaria` | `text` | Contratante gestão e fiscalização | `contratos.secretaria_nome_snapshot` | Preservar texto e limpar apenas para busca |
| `CPF_Fiscal` | `text` | Contratante gestão e fiscalização | `contrato_detalhes.cpf_fiscal` | Preservar texto e limpar apenas para busca |
| `Nome_Fiscal` | `text` | Contratante gestão e fiscalização | `contrato_detalhes.nome_fiscal` | Snapshot de fiscal, preservar se cadastro for alterado |
| `RG_Fiscal` | `text` | Contratante gestão e fiscalização | `contrato_detalhes.rg_fiscal` | Preservar texto e limpar apenas para busca |
| `Decreto_Fiscal` | `text` | Contratante gestão e fiscalização | `contrato_detalhes.decreto_fiscal` | Preservar texto e limpar apenas para busca |
| `Tipo_Contrato` | `text` | Objeto e dados especiais | `contratos.tipo` | Normalizar categoria para filtros sem mudar texto original |
| `Escopo` | `text` | Objeto e dados especiais | `contrato_detalhes.escopo` | Preservar texto e limpar apenas para busca |
| `Tipo_Veiculo` | `text` | Objeto e dados especiais | `contrato_detalhes.tipo_veiculo` | Preservar texto e limpar apenas para busca |
| `Dados_Veiculo` | `text` | Objeto e dados especiais | `contrato_detalhes.dados_veiculo` | Preservar texto e limpar apenas para busca |
| `End_Imovel` | `text` | Objeto e dados especiais | `contrato_detalhes.end_imovel` | Preservar texto e limpar apenas para busca |
| `Cargo_Credenc` | `text` | Objeto e dados especiais | `contrato_detalhes.cargo_credenc` | Preservar texto e limpar apenas para busca |
| `Local_Servico` | `text` | Objeto e dados especiais | `contrato_detalhes.local_servico` | Preservar texto e limpar apenas para busca |
| `IPTU` | `text` | Objeto e dados especiais | `contrato_detalhes.iptu` | Preservar texto e limpar apenas para busca |
| `Instalacoes` | `text` | Objeto e dados especiais | `contrato_detalhes.instalacoes` | Preservar texto e limpar apenas para busca |
| `AreaTerreno` | `text` | Objeto e dados especiais | `contrato_detalhes.areaterreno` | Pode ter unidade/texto; validar antes de converter |
| `AreaConstruida` | `text` | Objeto e dados especiais | `contrato_detalhes.areaconstruida` | Pode ter unidade/texto; validar antes de converter |
| `Objeto` | `text` | Objeto e dados especiais | `contratos.objeto` | Texto longo, manter histórico de alterações |
| `Tipo_Fornecimento` | `text` | Objeto e dados especiais | `contrato_detalhes.tipo_fornecimento` | Preservar texto e limpar apenas para busca |
| `Vigencia` | `text` | Vigência execução e aditivos | `contrato_detalhes.vigencia` | Prazo por extenso, não converter cegamente |
| `Inicio_Vig` | `date` | Vigência execução e aditivos | `contratos.vigencia_inicio` | date nullable; `0000-00-00` → NULL |
| `Final_Vig` | `date` | Vigência execução e aditivos | `contratos.vigencia_fim_original` | date nullable; `0000-00-00` → NULL |
| `Final_Vig_Atualiz` | `date` | Vigência execução e aditivos | `contratos.vigencia_fim_atual` | 0000-00-00 para NULL; reconciliar prorrogações |
| `N_Aditivo` | `text` | Vigência execução e aditivos | `contrato_detalhes.n_aditivo` | Valor histórico resumido; ver tb_Aditivos para eventos |
| `Proc_Aditivo` | `text` | Vigência execução e aditivos | `contrato_detalhes.proc_aditivo` | Vínculo textual pode ser ambíguo |
| `VL_Parcial` | `text` | Financeiro garantia e dotação | `contrato_detalhes.vl_parcial` | Campo monetário textual; semântica precisa homologação |
| `VL_Total` | `text` | Financeiro garantia e dotação | `contratos.valor_total` | Decimal preciso; representa valor inicial no relatório |
| `VL_Acumulado` | `text` | Financeiro garantia e dotação | `contratos.valor_acumulado` | Decimal preciso; não somar ao valor inicial |
| `VL_Saldo_Contrato` | `text` | Financeiro garantia e dotação | `contrato_detalhes.vl_saldo_contrato` | Sem recálculo automático até homologação |
| `VL_Saldo_Disp` | `text` | Financeiro garantia e dotação | `contrato_detalhes.vl_saldo_disp` | Sem recálculo automático até homologação |
| `Ext_Parcial` | `text` | Financeiro garantia e dotação | `contrato_detalhes.ext_parcial` | Preservar texto e limpar apenas para busca |
| `Ext_Total` | `text` | Financeiro garantia e dotação | `contrato_detalhes.ext_total` | Preservar texto e limpar apenas para busca |
| `Ext_Acumulado` | `text` | Financeiro garantia e dotação | `contrato_detalhes.ext_acumulado` | Preservar texto e limpar apenas para busca |
| `Reforma` | `text` | Objeto e dados especiais | `contrato_detalhes.reforma` | Campo histórico específico da obra |
| `Tipo_Obra` | `text` | Objeto e dados especiais | `contrato_detalhes.tipo_obra` | Preservar texto e limpar apenas para busca |
| `Tipo_Garantia` | `text` | Financeiro garantia e dotação | `contrato_detalhes.tipo_garantia` | Preservar texto e limpar apenas para busca |
| `%_Garantia` | `text` | Financeiro garantia e dotação | `contrato_detalhes.garantia` | Identificador com símbolo % exige alias no modelo |
| `VL_Garantia` | `text` | Financeiro garantia e dotação | `contrato_detalhes.vl_garantia` | text BR → DECIMAL(18,4); manter original |
| `Ext_Garantia` | `text` | Financeiro garantia e dotação | `contrato_detalhes.ext_garantia` | Preservar texto e limpar apenas para busca |
| `N_OS` | `text` | Vigência execução e aditivos | `contrato_detalhes.n_os` | Preservar texto e limpar apenas para busca |
| `Data_OS` | `date` | Vigência execução e aditivos | `contrato_detalhes.data_os` | date nullable; `0000-00-00` → NULL |
| `Prazo_Obra` | `text` | Vigência execução e aditivos | `contrato_detalhes.prazo_obra` | Preservar texto e limpar apenas para busca |
| `Local_Public` | `text` | Publicação distrato e paralisação | `contrato_detalhes.local_public` | Preservar texto e limpar apenas para busca |
| `Data_Public` | `date` | Publicação distrato e paralisação | `contrato_detalhes.data_public` | date nullable; `0000-00-00` → NULL |
| `Ficha` | `text` | Financeiro garantia e dotação | `contrato_detalhes.ficha` | Não corresponde necessariamente a uma única dotação |
| `Empenho` | `text` | Financeiro garantia e dotação | `contrato_detalhes.empenho` | Pode ser texto; não converter em ID referencial automático |
| `Proc_Distrato` | `text` | Publicação distrato e paralisação | `contrato_detalhes.proc_distrato` | Preservar texto e limpar apenas para busca |
| `Data_Distrato` | `date` | Publicação distrato e paralisação | `contratos.data_distrato` | Datas 0000-00-00 são ausência de informação |
| `Tipo_Distrato` | `text` | Publicação distrato e paralisação | `contrato_detalhes.tipo_distrato` | Preservar texto e limpar apenas para busca |
| `Motivo_Distrato` | `text` | Publicação distrato e paralisação | `contrato_detalhes.motivo_distrato` | Preservar texto e limpar apenas para busca |
| `Proc_Paraliz_Obra` | `text` | Publicação distrato e paralisação | `contrato_detalhes.proc_paraliz_obra` | Preservar texto e limpar apenas para busca |
| `Data_Paraliz_Obra` | `date` | Publicação distrato e paralisação | `contrato_detalhes.data_paraliz_obra` | Separar situação jurídica de exclusão lógica |
| `Motivo_Paraliz_Obra` | `text` | Publicação distrato e paralisação | `contrato_detalhes.motivo_paraliz_obra` | Preservar texto e limpar apenas para busca |

### 3.1 Política de dados dos campos

- O destino é **proposto** e pode evoluir na migration; o mapeamento fonte→destino deve ser mantido em arquivo versionado da aplicação.
- Algumas colunas centrais (`CPF_CNPJ`, `Fornecedor`, `Fundo`, `Secretaria`) também geram FKs relacionais; armazenar os respectivos **snapshots** da época da contratação.
- Valores nominais do contrato e valores por extenso não são recalculados automaticamente; deve-se sinalizar divergências.
- Campos textuais com número (contrato, processo, empenho e ficha) continuam texto; preservar zeros à esquerda.
- `Final_Vig_Atualiz` não apaga `Final_Vig`; ambas devem aparecer na visualização com indicação de fonte.

## 4. Relacionamentos lógicos prováveis (não são FKs no SQL)

| Origem | Destino potencial | Campos envolvidos | Método seguro |
|---|---|---|---|
| `TB_Contratos` | `tb_Fornecedor` | `CPF_CNPJ` ↔ `CPF_CNPJ` | CPF/CNPJ normalizado; revisar duplicados e inválidos |
| `TB_Contratos` | `tb_Fundo` | `CNPJ_Fundo` ↔ `CNPJ_Fundo`; `Fundo` ↔ `Fundo` | Documento normalizado e nome auxiliar |
| `TB_Contratos` | `tb_Secretaria` | `Secretaria` ↔ `Secretaria` | Nome normalizado; histórico de renomeação |
| `TB_Contratos` | `tb_Gestor` | `CPF_Gestor` ↔ `CPF_Gestor` | CPF + unidade; snapshots preservados |
| `TB_Contratos` | `tb_Fiscal` | `CPF_Fiscal` ↔ `CPF_Fiscal` | CPF + unidade; vazios não são match |
| `tb_Aditivos` | `TB_Contratos` | `Contrato`, `CNPJ_Fornec`, `CNPJ_Fundo`, `Proc_ADT` | Correspondência múltipla; não atribuir se ambígua |
| `tb_Produtos_Contratos` | `TB_Contratos` | `Contrato`, `CNPJ`, `FUNDO`, `Processo` | Vínculo composto com fila de ambiguidades |
| `tb_ValorFicha` | `TB_Contratos` | `Contrato`, `CPF_CNPJ`, `Fundo`, `Proc_Cont` | Reconciliar por múltiplos identificadores |
| `tb_Processo_Arquivos` | `TB_Contratos` | `ID_Cont`, `Contrato`, `CPF_CNPJ` | Preferir ID apenas se referir de fato à mesma tabela; confirmar amostra |
| `tb_Pedidos` | `TB_Contratos` | `Contrato`, `Proc_Cont`, `Fundo`, `CNPJ` | Propor relação 1:N, reconciliação obrigatória |

## 5. Estrutura de cada tabela original (inventário literal)

> A seguir: lista integral de nomes de colunas e tipos **tal como declarados**. Evitar transformar esta seção em migration automática, pois vários tipos/datas precisam de saneamento.

### `tb_Aditivos` — 44 campos, 985 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `N_ADT` | `int(11) NOT NULL` |
| `Data_ADT` | `date NOT NULL` |
| `Proc_ADT` | `text NOT NULL` |
| `Contrato` | `text NOT NULL` |
| `CNPJ_Fornec` | `text NOT NULL` |
| `Fornecedor` | `text NOT NULL` |
| `RG_Fornecedor` | `text NOT NULL` |
| `Doc_Prof` | `text NOT NULL` |
| `End_Fornecedor` | `text NOT NULL` |
| `CPF_Socio` | `text NOT NULL` |
| `RG_Socio` | `text NOT NULL` |
| `Nome_Socio` | `text NOT NULL` |
| `CNPJ_Fundo` | `text NOT NULL` |
| `Fundo` | `text NOT NULL` |
| `End_Fundo` | `text NOT NULL` |
| `Secretaria` | `text NOT NULL` |
| `CPF_Gestor` | `text NOT NULL` |
| `RG_Gestor` | `text NOT NULL` |
| `Gestor` | `text NOT NULL` |
| `Cargo_Gestor` | `text NOT NULL` |
| `Tipo_ADT` | `text NOT NULL` |
| `Lei` | `text NOT NULL` |
| `Motivo_ADT` | `text NOT NULL` |
| `Tipo_Alteracao_Valor` | `text NOT NULL` |
| `Artigos` | `text NOT NULL` |
| `Texto_Artigos` | `text NOT NULL` |
| `Porcentagem` | `text NOT NULL` |
| `Incio_Prorrog` | `date NOT NULL` |
| `Final_Prorrog` | `date NOT NULL` |
| `Periodo_Prorrog` | `text NOT NULL` |
| `Incio_Servico` | `date NOT NULL` |
| `Final_Servico` | `date NOT NULL` |
| `Periodo_Servico` | `text NOT NULL` |
| `VL_Mensal` | `text NOT NULL` |
| `VL_ADT` | `text NOT NULL` |
| `VL_Acumulado` | `text NOT NULL` |
| `Ext_Mensal` | `text NOT NULL` |
| `Ext_Aditivo` | `text NOT NULL` |
| `Ext_Acumulado` | `text NOT NULL` |
| `N_OS` | `text NOT NULL` |
| `Data_OS` | `date NOT NULL` |
| `Ficha` | `text NOT NULL` |
| `Empenho` | `text NOT NULL` |

### `tb_ADM` — 3 campos, 1 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `Ano1` | `text NOT NULL` |
| `Ano2` | `text NOT NULL` |

### `tb_Caminho` — 2 campos, 1 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `id_Caminho` | `int(11) NOT NULL` |
| `Caminho` | `text NOT NULL` |

### `tb_Cargo_Credenciado` — 2 campos, 0 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID_Cargo` | `int(11) NOT NULL` |
| `Cargo` | `text NOT NULL` |

### `tb_Config` — 2 campos, 1 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `Numeracao` | `text NOT NULL` |

### `TB_Contratos` — 80 campos, 2145 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID_Contrato` | `int(11) NOT NULL` |
| `Contrato` | `text NOT NULL` |
| `Cont_Inteiro` | `text NOT NULL` |
| `Data_Cont` | `date NOT NULL` |
| `Data_Cont_Verd` | `date NOT NULL` |
| `Processo` | `text NOT NULL` |
| `Licitacao` | `text NOT NULL` |
| `N_Licit` | `text NOT NULL` |
| `Proc_Edital` | `text NOT NULL` |
| `N_Edital` | `text NOT NULL` |
| `Data_Licit` | `date NOT NULL` |
| `N_Solicit` | `text NOT NULL` |
| `CPF_CNPJ` | `text NOT NULL` |
| `Fornecedor` | `text NOT NULL` |
| `RG_Fornec` | `text NOT NULL` |
| `Doc_Prof` | `text NOT NULL` |
| `PIS_PASEP` | `text NOT NULL` |
| `End_Fornec` | `text NOT NULL` |
| `Socio` | `text NOT NULL` |
| `CPF_Socio` | `text NOT NULL` |
| `RG_Socio` | `text NOT NULL` |
| `CNPJ_Fundo` | `text NOT NULL` |
| `Fundo` | `text NOT NULL` |
| `End_Fundo` | `text NOT NULL` |
| `CPF_Gestor` | `text NOT NULL` |
| `Nome_Gestor` | `text NOT NULL` |
| `RG_Gestor` | `text NOT NULL` |
| `Cargo_Gestor` | `text NOT NULL` |
| `Secretaria` | `text NOT NULL` |
| `CPF_Fiscal` | `text NOT NULL` |
| `Nome_Fiscal` | `text NOT NULL` |
| `RG_Fiscal` | `text NOT NULL` |
| `Decreto_Fiscal` | `text NOT NULL` |
| `Tipo_Contrato` | `text NOT NULL` |
| `Escopo` | `text NOT NULL` |
| `Tipo_Veiculo` | `text NOT NULL` |
| `Dados_Veiculo` | `text NOT NULL` |
| `End_Imovel` | `text NOT NULL` |
| `Cargo_Credenc` | `text NOT NULL` |
| `Local_Servico` | `text NOT NULL` |
| `IPTU` | `text NOT NULL` |
| `Instalacoes` | `text NOT NULL` |
| `AreaTerreno` | `text NOT NULL` |
| `AreaConstruida` | `text NOT NULL` |
| `Objeto` | `text NOT NULL` |
| `Tipo_Fornecimento` | `text NOT NULL` |
| `Vigencia` | `text NOT NULL` |
| `Inicio_Vig` | `date NOT NULL` |
| `Final_Vig` | `date NOT NULL` |
| `Final_Vig_Atualiz` | `date NOT NULL` |
| `N_Aditivo` | `text NOT NULL` |
| `Proc_Aditivo` | `text NOT NULL` |
| `VL_Parcial` | `text NOT NULL` |
| `VL_Total` | `text NOT NULL` |
| `VL_Acumulado` | `text NOT NULL` |
| `VL_Saldo_Contrato` | `text NOT NULL` |
| `VL_Saldo_Disp` | `text NOT NULL` |
| `Ext_Parcial` | `text NOT NULL` |
| `Ext_Total` | `text NOT NULL` |
| `Ext_Acumulado` | `text NOT NULL` |
| `Reforma` | `text NOT NULL` |
| `Tipo_Obra` | `text NOT NULL` |
| `Tipo_Garantia` | `text NOT NULL` |
| `%_Garantia` | `text NOT NULL` |
| `VL_Garantia` | `text NOT NULL` |
| `Ext_Garantia` | `text NOT NULL` |
| `N_OS` | `text NOT NULL` |
| `Data_OS` | `date NOT NULL` |
| `Prazo_Obra` | `text NOT NULL` |
| `Local_Public` | `text NOT NULL` |
| `Data_Public` | `date NOT NULL` |
| `Ficha` | `text NOT NULL` |
| `Empenho` | `text NOT NULL` |
| `Proc_Distrato` | `text NOT NULL` |
| `Data_Distrato` | `date NOT NULL` |
| `Tipo_Distrato` | `text NOT NULL` |
| `Motivo_Distrato` | `text NOT NULL` |
| `Proc_Paraliz_Obra` | `text NOT NULL` |
| `Data_Paraliz_Obra` | `date NOT NULL` |
| `Motivo_Paraliz_Obra` | `text NOT NULL` |

### `tb_Controle_Licit` — 9 campos, 35 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `N_Licit` | `text NOT NULL` |
| `Data_Licit` | `text NOT NULL` |
| `Processo` | `text NOT NULL` |
| `Modalidade` | `text NOT NULL` |
| `Tipo_Objeto` | `text NOT NULL` |
| `Resumo_Objeto` | `text NOT NULL` |
| `RP` | `text NOT NULL` |
| `CRED` | `text NOT NULL` |

### `tb_Descricao_Tipo_Contratos` — 4 campos, 0 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `ID_Tipo` | `int(11) NOT NULL` |
| `Tipo` | `text NOT NULL` |
| `Detalhes` | `text NOT NULL` |

### `tb_DFD` — 21 campos, 5 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `N_DFD` | `text NOT NULL` |
| `Data_DFD` | `date NOT NULL` |
| `Processo_DFD` | `text NOT NULL` |
| `Fundo` | `text NOT NULL` |
| `Necessidade` | `text NOT NULL` |
| `Desricao` | `text NOT NULL` |
| `Data_Conclusao` | `date NOT NULL` |
| `Prioridade` | `text NOT NULL` |
| `Vigencia` | `text NOT NULL` |
| `Local` | `text NOT NULL` |
| `End_Local` | `text NOT NULL` |
| `Vinculacao` | `text NOT NULL` |
| `Observacoes` | `text NOT NULL` |
| `Requisitante` | `text NOT NULL` |
| `Responsavel` | `text NOT NULL` |
| `Decreto` | `text NOT NULL` |
| `Cargo` | `text NOT NULL` |
| `Categoria` | `text NOT NULL` |
| `Ficha` | `text NOT NULL` |
| `email` | `text NOT NULL` |

### `tb_Dotacao` — 15 campos, 1733 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID_Dotacao` | `int(11) NOT NULL` |
| `Orgao` | `text NOT NULL` |
| `Gestao` | `text NOT NULL` |
| `Unidade` | `text NOT NULL` |
| `Funcao` | `text NOT NULL` |
| `SubFunc` | `text NOT NULL` |
| `Programa` | `text NOT NULL` |
| `Projeto` | `text NOT NULL` |
| `Fonte` | `text NOT NULL` |
| `Codigo` | `text NOT NULL` |
| `Ficha` | `text NOT NULL` |
| `Descricao` | `text NOT NULL` |
| `Dotacao` | `text NOT NULL` |
| `Fundo` | `text NOT NULL` |
| `Secretaria` | `text NOT NULL` |

### `tb_Fiscal` — 14 campos, 15 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID_Fiscal` | `int(11) NOT NULL` |
| `CPF_Fiscal` | `text NOT NULL` |
| `RG_Fiscal` | `text NOT NULL` |
| `Data_Nasc` | `text NOT NULL` |
| `Sexo` | `text NOT NULL` |
| `Nome_Fiscal` | `text NOT NULL` |
| `Email` | `text NOT NULL` |
| `Fundo` | `text NOT NULL` |
| `Secretaria` | `text NOT NULL` |
| `Decreto` | `text NOT NULL` |
| `Data_Decreto` | `text NOT NULL` |
| `Celular` | `text NOT NULL` |
| `WhatsApp` | `text NOT NULL` |
| `Cod_Prodata` | `int(11) NOT NULL` |

### `tb_Fornecedor` — 39 campos, 1175 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID_Fornecedor` | `int(11) NOT NULL` |
| `Data_Atualizacao` | `text NOT NULL` |
| `PF_PJ` | `text NOT NULL` |
| `CPF_CNPJ` | `text NOT NULL` |
| `Nome_Fornecedor` | `text NOT NULL` |
| `Nome_Fantasia` | `text NOT NULL` |
| `RG` | `text NOT NULL` |
| `Data_Nasc` | `text NOT NULL` |
| `Sexo` | `text NOT NULL` |
| `PIS_PASEP` | `text NOT NULL` |
| `Doc_Prof` | `text NOT NULL` |
| `Endereco` | `text NOT NULL` |
| `Fone_Fixo` | `text NOT NULL` |
| `Fone_Celular` | `text NOT NULL` |
| `WhatsApp_Fornecedor` | `text NOT NULL` |
| `Email_Fornecedor` | `text NOT NULL` |
| `Nome_Socio` | `text NOT NULL` |
| `CPF_Socio` | `text NOT NULL` |
| `RG_Socio` | `text NOT NULL` |
| `End_Socio` | `text NOT NULL` |
| `Celular_Socio` | `text NOT NULL` |
| `Fixo_Socio` | `text NOT NULL` |
| `WhatsApp_Socio` | `text NOT NULL` |
| `Email_Socio` | `text NOT NULL` |
| `Nome_Contato` | `text NOT NULL` |
| `Celular_Contato` | `text NOT NULL` |
| `WhatsApp_Contato` | `text NOT NULL` |
| `Email_Contato` | `text NOT NULL` |
| `VL_Pensao` | `text NOT NULL` |
| `ISSQN` | `text NOT NULL` |
| `Dependentes` | `text NOT NULL` |
| `INSS` | `text NOT NULL` |
| `Situacao` | `text NOT NULL` |
| `Tributario` | `text NOT NULL` |
| `Honorario` | `text NOT NULL` |
| `Empregados` | `text NOT NULL` |
| `Origem` | `text NOT NULL` |
| `Porte` | `text NOT NULL` |
| `NaturezaJuridica` | `text NOT NULL` |

### `tb_Funcionario` — 18 campos, 144 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID_Func` | `int(11) NOT NULL` |
| `CPF` | `text NOT NULL` |
| `Nome` | `text NOT NULL` |
| `RG` | `text NOT NULL` |
| `Data_Nasc` | `date NOT NULL` |
| `Endereco` | `text NOT NULL` |
| `Email` | `text NOT NULL` |
| `Celular` | `text NOT NULL` |
| `WhatsApp` | `text NOT NULL` |
| `FoneFixo` | `text NOT NULL` |
| `Sexo` | `text NOT NULL` |
| `Cargo` | `text NOT NULL` |
| `Fundo` | `text NOT NULL` |
| `Setor` | `text NOT NULL` |
| `Entrada01` | `text NOT NULL` |
| `Saida01` | `text NOT NULL` |
| `Entrada02` | `text NOT NULL` |
| `Saida02` | `text NOT NULL` |

### `tb_Fundo` — 8 campos, 8 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID_Fundo` | `int(11) NOT NULL` |
| `CNPJ_Fundo` | `text NOT NULL` |
| `Fundo` | `text NOT NULL` |
| `End_Fundo` | `text NOT NULL` |
| `Abreviatuda` | `text NOT NULL` |
| `Codigo` | `text NOT NULL` |
| `Telefone` | `text NOT NULL` |
| `Nome` | `text NOT NULL` |

### `tb_Fundos_Licitantes` — 9 campos, 33 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `ID_Licit` | `int(11) NOT NULL` |
| `N_Licit` | `text NOT NULL` |
| `RP` | `text NOT NULL` |
| `CRED` | `text NOT NULL` |
| `Ano` | `text NOT NULL` |
| `Modalidade` | `text NOT NULL` |
| `Processo` | `text NOT NULL` |
| `Fundo` | `text NOT NULL` |

### `tb_Gestor` — 17 campos, 8 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID_Gestor` | `int(11) NOT NULL` |
| `Fundo` | `text NOT NULL` |
| `CPF_Gestor` | `text NOT NULL` |
| `Nome_Gestor` | `text NOT NULL` |
| `RG_Gestor` | `text NOT NULL` |
| `Data_Nasc` | `text NOT NULL` |
| `Sexo` | `text NOT NULL` |
| `Endereco` | `text NOT NULL` |
| `Cargo` | `text NOT NULL` |
| `Cargo_Abreviado` | `text NOT NULL` |
| `Fone_Celular` | `text NOT NULL` |
| `WhatsApp` | `text NOT NULL` |
| `Email` | `text NOT NULL` |
| `Decreto` | `text NOT NULL` |
| `Data_Decreto` | `text NOT NULL` |
| `Assinatura` | `int(11) NOT NULL` |
| `Matricula` | `text NOT NULL` |

### `tb_IRRF` — 8 campos, 5 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `Data` | `date NOT NULL` |
| `Data_Fim` | `date NOT NULL` |
| `Opcao` | `text NOT NULL` |
| `De` | `text NOT NULL` |
| `Ate` | `text NOT NULL` |
| `Aliquota` | `text NOT NULL` |
| `Deduzir` | `text NOT NULL` |

### `tb_IRRF_Simples` — 3 campos, 1 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `Data` | `date NOT NULL` |
| `Valor` | `text NOT NULL` |

### `tb_Modalidade` — 2 campos, 8 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `Modalidade` | `text NOT NULL` |

### `tb_N_Contrato` — 3 campos, 8 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `FUNDO` | `text NOT NULL` |
| `NUMERO` | `text NOT NULL` |

### `tb_N_DFD` — 2 campos, 1 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `N_DFD` | `int(11) NOT NULL` |

### `tb_N_Licit` — 3 campos, 7 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `Modalidade` | `text NOT NULL` |
| `Numero` | `text NOT NULL` |

### `tb_N_OS` — 3 campos, 6 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID_OS` | `int(11) NOT NULL` |
| `Fundo` | `text NOT NULL` |
| `Numero_OS` | `int(11) NOT NULL` |

### `tb_N_Pedido` — 2 campos, 1 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `Numero` | `int(11) NOT NULL` |

### `tb_OS` — 13 campos, 22 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID_OS` | `int(11) NOT NULL` |
| `N_OS` | `text NOT NULL` |
| `Fundo` | `text NOT NULL` |
| `Contrato` | `text NOT NULL` |
| `Processo` | `text NOT NULL` |
| `Licitacao` | `text NOT NULL` |
| `N_Licitacao` | `text NOT NULL` |
| `Data_OS` | `date NOT NULL` |
| `Data_Inicio` | `date NOT NULL` |
| `Data_Final` | `date NOT NULL` |
| `Periodo` | `text NOT NULL` |
| `Objeto` | `text NOT NULL` |
| `Tipo_Contrato` | `text NOT NULL` |

### `tb_Pedidos` — 20 campos, 6 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID_Pedido` | `int(11) NOT NULL` |
| `Data_Pedido` | `date NOT NULL` |
| `N_Pedido` | `text NOT NULL` |
| `Contrato` | `text NOT NULL` |
| `Tipo_Cont` | `text NOT NULL` |
| `Proc_Cont` | `text NOT NULL` |
| `Licitacao` | `text NOT NULL` |
| `N_Licit` | `text NOT NULL` |
| `Fundo` | `text NOT NULL` |
| `Setor` | `text NOT NULL` |
| `Cargo_Gestor` | `text NOT NULL` |
| `CPF_Gestor` | `text NOT NULL` |
| `Nome_Gestor` | `text NOT NULL` |
| `CNPJ` | `text NOT NULL` |
| `Fornecedor` | `text NOT NULL` |
| `Cotacao` | `text NOT NULL` |
| `Autorizacao` | `text NOT NULL` |
| `Ficha` | `text NOT NULL` |
| `Empenho` | `text NOT NULL` |
| `Situacao` | `text NOT NULL` |

### `tb_ProcArquivo` — 13 campos, 1 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `Processo` | `text NOT NULL` |
| `Data_Arquivo` | `date NOT NULL` |
| `Prateleira` | `text NOT NULL` |
| `Divisoria` | `text NOT NULL` |
| `Modalidade` | `text NOT NULL` |
| `N_Licit` | `text NOT NULL` |
| `Proc_Vinculo` | `text NOT NULL` |
| `Fundo` | `text NOT NULL` |
| `Data_Saida` | `date NOT NULL` |
| `Data_Retorno` | `date NOT NULL` |
| `Retirante` | `text NOT NULL` |
| `Link_Digital` | `text NOT NULL` |

### `tb_Processos` — 5 campos, 1 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `Processo` | `text NOT NULL` |
| `Nome` | `text NOT NULL` |
| `Situacao` | `text NOT NULL` |
| `Remessa` | `text NOT NULL` |

### `tb_Processo_Arquivos` — 11 campos, 19 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `ID_Proc_Arq` | `int(11) NOT NULL` |
| `ID_Cont` | `int(11) NOT NULL` |
| `Tipo_Documento` | `text NOT NULL` |
| `CPF_CNPJ` | `text NOT NULL` |
| `Fornecedor` | `text NOT NULL` |
| `Modalidade` | `text NOT NULL` |
| `Numero_Licit` | `text NOT NULL` |
| `Contrato` | `text NOT NULL` |
| `Numero_NF` | `text NOT NULL` |
| `Valor` | `double NOT NULL` |

### `tb_Produtos` — 13 campos, 3307 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID_Produto` | `int(11) NOT NULL` |
| `Processo` | `text NOT NULL` |
| `Cod_Prod` | `text NOT NULL` |
| `CNPJ_Fornec` | `text NOT NULL` |
| `Item` | `text NOT NULL` |
| `Discriminacao` | `text NOT NULL` |
| `Marca` | `text NOT NULL` |
| `Unidade` | `text NOT NULL` |
| `QTD` | `text NOT NULL COMMENT 'Quantidade do Contrato Original'` |
| `QTD_Acumulado` | `text NOT NULL COMMENT 'Quantidade original + Aditivos'` |
| `QTD_Autorizado` | `text NOT NULL COMMENT 'Soma das Quantidades as Autorizações de Empenho'` |
| `VL_Unitario` | `text NOT NULL` |
| `VL_Total` | `text NOT NULL` |

### `tb_Produtos_Contratos` — 23 campos, 4553 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `CNPJ` | `text NOT NULL` |
| `Contrato` | `text NOT NULL` |
| `Processo` | `text NOT NULL` |
| `ADT` | `text NOT NULL` |
| `Proc_ADT` | `text NOT NULL` |
| `FUNDO` | `text NOT NULL` |
| `Autorizacao` | `text NOT NULL` |
| `Ficha` | `text NOT NULL` |
| `Cotacao` | `text NOT NULL` |
| `Descricao` | `text NOT NULL` |
| `Empenho` | `text NOT NULL` |
| `Cod_Prod` | `text NOT NULL` |
| `Item` | `text NOT NULL` |
| `Cargo_Credenc` | `text NOT NULL` |
| `Discriminacao` | `text NOT NULL` |
| `Marca` | `text NOT NULL` |
| `UND` | `text NOT NULL` |
| `QTD` | `text NOT NULL` |
| `VL_UNIT` | `text NOT NULL` |
| `TOTAL_UNIT` | `text NOT NULL` |
| `Saldo_Atual` | `text NOT NULL` |
| `Saldo_Disp` | `text NOT NULL` |

### `tb_Produtos_DFD` — 13 campos, 22 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `ID_DFD` | `int(11) NOT NULL` |
| `N_DFD` | `text NOT NULL` |
| `ID_PROD` | `int(11) NOT NULL` |
| `COD_PROD` | `text NOT NULL` |
| `ITEM` | `int(11) NOT NULL` |
| `PROD_SIMPLES` | `text NOT NULL` |
| `CARACT` | `text NOT NULL` |
| `PRODUTO` | `text NOT NULL` |
| `UND` | `text NOT NULL` |
| `QTD` | `text NOT NULL` |
| `VL_UNIT` | `text NOT NULL` |
| `VL_TOTAL` | `text NOT NULL` |

### `tb_Produtos_Pedido` — 15 campos, 8 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `N_Pedido` | `text NOT NULL` |
| `Contrato` | `text NOT NULL` |
| `Autorizacao` | `text NOT NULL` |
| `Ficha` | `text NOT NULL` |
| `ID_Prod` | `int(11) NOT NULL` |
| `Item` | `text NOT NULL` |
| `UND` | `text NOT NULL` |
| `Marca` | `text NOT NULL` |
| `QTD_Pedida` | `text NOT NULL` |
| `Discriminacao` | `text NOT NULL` |
| `VL_Unit` | `text NOT NULL` |
| `VL_Total` | `text NOT NULL` |
| `Situacao` | `text NOT NULL` |
| `Fundo` | `text NOT NULL` |

### `tb_Produtos_Prodata` — 6 campos, 10659 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `ID_Prodata` | `int(11) NOT NULL` |
| `Classe` | `text NOT NULL` |
| `Descricao` | `text NOT NULL` |
| `UND` | `text NOT NULL` |
| `Inativo` | `int(11) NOT NULL` |

### `tb_Recibos` — 26 campos, 734 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `Cont` | `text NOT NULL` |
| `Mes_Ref` | `text NOT NULL` |
| `CPF` | `text NOT NULL` |
| `Nome` | `text NOT NULL` |
| `RG` | `text NOT NULL` |
| `Doc_Prof` | `text NOT NULL` |
| `Endereco` | `text NOT NULL` |
| `Fundo` | `text NOT NULL` |
| `Processo` | `text NOT NULL` |
| `Tipo_Cont` | `text NOT NULL` |
| `Valor_Mensal` | `text NOT NULL` |
| `INSS` | `text NOT NULL` |
| `Descontos` | `text NOT NULL` |
| `Base_Calc` | `text NOT NULL` |
| `IRRF` | `text NOT NULL` |
| `ISSQN` | `text NOT NULL` |
| `Liquido` | `text NOT NULL` |
| `Ficha` | `text NOT NULL` |
| `Empenho` | `text NOT NULL` |
| `End_Imovel` | `text NOT NULL` |
| `Veiculo` | `text NOT NULL` |
| `Cargo` | `text NOT NULL` |
| `Objeto` | `text NOT NULL` |
| `Licitacao` | `text NOT NULL` |
| `N_Licit` | `text NOT NULL` |

### `tb_RP_CRED` — 3 campos, 1 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `RP` | `int(11) NOT NULL` |
| `CRED` | `int(11) NOT NULL` |

### `tb_Secretaria` — 11 campos, 15 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID_Secretaria` | `int(11) NOT NULL` |
| `Secretaria` | `text NOT NULL` |
| `Telefone_Fixo` | `text NOT NULL` |
| `Secretario` | `text NOT NULL` |
| `Decreto` | `text NOT NULL` |
| `Data_Decreto` | `text NOT NULL` |
| `Matricula` | `text NOT NULL` |
| `Cargo` | `text NOT NULL` |
| `CPF_Secretario` | `text NOT NULL` |
| `Fone_Celular` | `text NOT NULL` |
| `WhatsApp` | `text NOT NULL` |

### `tb_SenhasWeb` — 5 campos, 8 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `Local` | `text NOT NULL` |
| `HTTP` | `text NOT NULL` |
| `Senha_Acesso` | `text NOT NULL` |
| `Senha_Recup` | `text NOT NULL` |

### `tb_Solicitacao` — 22 campos, 0 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `Contrato` | `text NOT NULL` |
| `Data_Solicit` | `text NOT NULL` |
| `Tipo_Solicitacao` | `text NOT NULL` |
| `Proc_Contrato` | `text NOT NULL` |
| `Processo_ADT` | `text NOT NULL` |
| `Tipo_Contrato` | `text NOT NULL` |
| `N_ADT` | `int(11) NOT NULL` |
| `Tipo_ADT` | `text NOT NULL` |
| `CNPJ_Fundo` | `text NOT NULL` |
| `CNPJ_Fornecedor` | `text NOT NULL` |
| `Inicio_Vig` | `text NOT NULL` |
| `Final_Vig` | `text NOT NULL` |
| `Inicio_Prorrog_Serv` | `text NOT NULL` |
| `Final_Prorrog_Serv` | `text NOT NULL` |
| `Periodo_Vig` | `text NOT NULL` |
| `Periodo_Prorrog` | `text NOT NULL` |
| `Lei` | `text NOT NULL` |
| `Total_ADT` | `double(10,4) NOT NULL` |
| `Ext_Total` | `text NOT NULL` |
| `Obra_Reforma` | `text NOT NULL` |
| `Por_Cento` | `text NOT NULL` |

### `tb_Teto_INSS` — 5 campos, 1 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `Ano` | `text NOT NULL` |
| `Data` | `date NOT NULL` |
| `Data_Fim` | `date NOT NULL` |
| `Teto_INSS` | `text NOT NULL` |

### `tb_Tipo_Contratos` — 2 campos, 11 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `Tipo_Contrato` | `text NOT NULL` |

### `tb_Tipo_Objeto` — 2 campos, 0 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `Tipo_Objeto` | `text NOT NULL` |

### `tb_Usuario` — 8 campos, 17 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID_User` | `int(11) NOT NULL` |
| `Nome` | `text NOT NULL` |
| `Tipo_User` | `text NOT NULL` |
| `Nick_Name` | `text NOT NULL` |
| `Senha` | `text NOT NULL` |
| `Fundo` | `text NOT NULL` |
| `Secretaria` | `text NOT NULL` |
| `Caminho` | `text NOT NULL` |

### `tb_ValorFicha` — 9 campos, 1462 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID_Ficha` | `int(11) NOT NULL` |
| `Fundo` | `text NOT NULL` |
| `Contrato` | `text NOT NULL` |
| `Ficha` | `text NOT NULL` |
| `Valor` | `text NOT NULL` |
| `CPF_CNPJ` | `text NOT NULL` |
| `Proc_Cont` | `text NOT NULL` |
| `Proc_ADT` | `text NOT NULL` |
| `N_ADT` | `int(11) NOT NULL` |

### `tb_VL_Depend` — 5 campos, 1 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID` | `int(11) NOT NULL` |
| `Ano` | `text NOT NULL` |
| `Data` | `date NOT NULL` |
| `Data_Fim` | `date NOT NULL` |
| `Valor` | `text NOT NULL` |

### `Tb_Web` — 3 campos, 16 registros

| Campo | Tipo SQL e restrição |
|---|---|
| `ID_Web` | `int(11) NOT NULL` |
| `Tipo` | `text NOT NULL` |
| `Endereco` | `text NOT NULL` |

## 6. Questões técnicas identificadas

1. **Sem FKs:** índices primários são declarados, mas não há chaves estrangeiras entre tabelas.
2. **Campos financeiros com tipo texto:** especialmente em `TB_Contratos`, `tb_Aditivos` e `tb_Produtos_Contratos`.
3. **Datas zeradas:** `0000-00-00` devem ser convertidas para `NULL` em tipo `date` novo.
4. **Nomenclatura heterogênea:** caixa mista (`TB_Contratos`, `Tb_Web`), campo `%_Garantia`, erros ortográficos (`Incio_Prorrog`, `Abreviatuda`) e variantes acentuadas.
5. **Credenciais sensíveis:** nunca transportar dados de senha legada para app nem deixar o dump em repositório.
6. **Variáveis de contexto jurídico:** datas, vigências, saldo e nomes das partes devem continuar acessíveis como histórico.
7. **Migrations:** criar nomes e tipos novos, sempre com tabela versionada de origem e mapeamento completo.
