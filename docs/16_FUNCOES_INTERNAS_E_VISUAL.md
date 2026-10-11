# Funções internas e refinamento visual — 11/10/2026

## Conferência do contrato

Administradores podem concluir a revisão em **Conferir vigência e valor acumulado**, no detalhe de um contrato em revisão. A tela apresenta aditivos registrados e históricos associados, com acesso ao contrato e aos documentos. Informe o término atualizado, o valor acumulado conferido, o número do contrato e uma justificativa com referências documentais; confirme a conferência na caixa de aceite.

O resultado é registrado explicitamente. O sistema não soma acréscimos/supressões nem calcula porcentagens. O valor inicial, datas originais e saldos são preservados. Término atualizado vazio faz a situação temporal usar o término original; valor acumulado vazio o deixa não informado. Vínculos históricos de aditivos ainda ambíguos, com este contrato entre os candidatos, impedem a conclusão. A conferência não limpa ocorrências de qualidade nem declara regularidade jurídica.

A operação exige administrador atual, versão atual, confirmação e auditoria atômica. Alterar/remover/restaurar um aditivo registrado reabre a revisão. O histórico da conferência guarda os valores anteriores/posteriores e os IDs dos aditivos e históricos considerados.

## Relatórios personalizados

Abra **Colunas do relatório** em Relatórios. Selecione as colunas, aplique os filtros e exporte PDF/XLSX/CSV. Tela e arquivos usam a mesma seleção. A soma continua representando o valor inicial de todos os contratos no filtro, mesmo quando essa coluna estiver oculta.

Em **Meus relatórios personalizados**, dê um nome à combinação atual de filtros e colunas. Cada conta guarda até 50 combinações, com nomes distintos; pode abrir e remover suas próprias combinações. Administradores não acessam personalizações de outras contas. Abrir consulta novamente os dados e o escopo atual. A autorização para CPF completo e sua justificativa não são salvas; exportações posteriores continuam sujeitas à regra de acesso.

## Painel e visual

O painel mostra indicadores do escopo, somas exatas de valores iniciais, gráficos de situação temporal, términos conhecidos nos seis meses e até dez órgãos com mais contratos. Não inclui excluídos. Términos indeterminados/em revisão não aparecem como vencimentos conhecidos. A busca de contratos recentes não altera os indicadores gerais.

O visual compartilhado foi refinado: navegação com ícones e página ativa, menu móvel recolhível, acesso direto ao conteúdo por teclado, hierarquia dos indicadores, espaçamento, campos, estados de foco, tabelas e login. Gráficos têm rótulos e valores textuais. O layout foi conferido no navegador em desktop e celular.

## API interna de consulta

Endpoints somente de leitura, sob autenticação de sessão e verificação da conta ativa:

- `GET /internal-api/v1/contracts`: lista paginada; filtros iguais aos relatórios, incluindo seleção de colunas. `per_page` de 1 a 100, padrão 20; `page` inicia em 1.
- `GET /internal-api/v1/contracts/{id}`: resumo do contrato autorizado.

Envie `Accept: application/json` e a sessão autenticada. Cada item contém `id`, `version` e `values`, com chaves estáveis de `ContractReport::COLUMNS`. Moeda é string decimal, datas usam ISO e CPF fica mascarado inclusive para administrador. `include_personal` é proibido. Não retorna senhas, tokens, payloads brutos ou auditorias. Não há escrita nem credencial de integração criada automaticamente. Respostas não têm cache; acessos são auditados e limitados a 60 requisições/minuto. Conta/sessão revogada produz 401 em solicitações JSON. A integração entre sistemas continua pendência externa.

## Importação periódica opcional

`php artisan contracts:sync-legacy --file=/caminho/privado/snapshot.sql --dry-run` simula contratos, catálogos e histórico de aditivos/itens. Remova `--dry-run` para aplicar. A leitura usa o parser de literais existente; não executa o SQL do arquivo. O conjunto é transacional: falha em qualquer etapa reverte todos os dados. Um hash já concluído é ignorado; novos snapshots acrescentam registros e apontam conflitos de origem, preservando edições locais. Alteração do arquivo durante a leitura interrompe a operação.

O scheduler executa por hora quando `LEGACY_SYNC_ENABLED=true` e `LEGACY_SYNC_PATH` aponta para um arquivo privado legível dentro do container. O padrão é desativado. Configuração deve ser recarregada após mudanças; o provisionamento do snapshot é externo. O mutex é compartilhado com os importadores manuais. Sucesso/falha deixam evento operacional sem publicar o conteúdo do snapshot ou seu caminho.
