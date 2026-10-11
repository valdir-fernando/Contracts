# Registro de validação — 10/10/2026

As validações abaixo foram executadas localmente. Os testes são sintéticos e não constituem homologação de dados ou regras jurídicas.

## Ambiente

Docker Engine/Compose; PHP 8.4.26; MySQL 8.4; Laravel 13; Livewire 4; Node 22/Vite; PHPUnit 12.5.38; Playwright 1.58.2 com Chromium.

## Resultados

| Verificação | Resultado |
|---|---|
| Suíte completa em SQLite, incluindo P1 | 70 testes aprovados, 800 asserções |
| Suíte completa em MySQL, schema isolado | 67 testes aprovados, 753 asserções; depois 15 testes direcionados aprovados com 177 asserções para gravação, fornecedores, requisitos por tipo e contagens do calendário |
| Verificação adicional da contagem de ocorrências históricas | 7 testes aprovados em SQLite e MySQL, 56 asserções em cada execução |
| Formatação PHP | Pint aprovado nos 92 arquivos PHP |
| Assets | Build Vite aprovado |
| CI | Workflow com SQLite/MySQL; sintaxe validada por actionlint; execução remota não realizada |
| Navegador | Login, busca Livewire, seleção de fornecedores, requisitos por tipo, máscara documental, criação, edição, exclusão, restauração, anexos privados, calendário, preferências de alerta, pesquisa avançada, CSV/XLSX/PDF, auditoria, isolamento e largura móvel aprovados; nenhum erro JavaScript capturado |
| Escala dos relatórios | CSV, XLSX e PDF para 0, 1, 100 e 2.145 contratos fictícios; somas CSV e XLSX iguais à consulta; zeros e decimais preservados |
| PDF | Geração em blocos, montagem com FPDI e paginação contínua; relatório agrupado conferido por extração de texto |
| Fila real | Trabalho GenerateReport executado pelo worker; PDF disponível com estado ready |
| Backup final | Banco, storage e configuração com SHA-256 válido |
| Restauração | Schema isolado restaurado; INSERTs de todas as tabelas conferidos; storage/configuração extraídos com filtros de segurança |
| Produção | Imagem compilada sem dependências de desenvolvimento e sem arquivo .env; runtime validou banco, dependências PDF, cookies seguros e debug desligado |
| Aplicação local | `/login` HTTP 200; `/ready` ready; app, web, banco, worker e scheduler ativos |

A restauração final da evolução P1 recuperou 6 contratos fictícios, 2 contas de demonstração e 26 eventos de auditoria contratual, além das tabelas de documentos, fornecedores, preferências e requisitos por tipo. Os demais eventos e tabelas foram incluídos na comparação integral de INSERTs. Os contratos de navegador são identificados por `SMOKE-`.

## Correções encontradas durante a validação

- Volumes novos precisavam de diretórios de cache/views antes do bootstrap; o entrypoint prepara a estrutura.
- PDFs grandes excediam memória ao montar uma única árvore HTML; agora são processados em blocos de 60 registros e reunidos com FPDI.
- Testes presumiam ID inicial 1 e ordem de chaves JSON; foram ajustados para validar valores e identidade independente do banco.
- A sessão de quem troca a própria senha permanece válida, enquanto as demais são invalidadas.
- Relatórios aplicam máscaras também nos agrupamentos e pedem justificativa para CPF completo.
- Downloads enfileirados verificam as permissões e a relação atual de contratos visíveis; mudança de escopo revoga arquivos anteriores.
- Datas anteriores ao limite suportado no MySQL são tratadas como pendência, preservando a origem bruta.
- A contagem de ocorrências dos lotes históricos corresponde à tabela de ocorrências.

## Evolução P1 validada

- Anexos: escopo atual, isolamento entre contratos, upload privado, download auditado, SHA-256, MIME/extensão/tamanho, remoção/restauração e preservação ao excluir contrato. Falha de auditoria desfaz metadados e limpa o novo arquivo.
- Calendário e alertas: limites inclusivos, término atualizado, períodos, ausência de datas, revisão, exclusão lógica, revogação de escopo inclusive em atualização Livewire, preferências pessoais e contagens por dia em SQLite/MySQL.
- Pesquisa: combinação de campos, soma decimal, filtros idênticos na listagem/relatório/CSV, catálogo restrito, documento pontuado, período inválido e faixa de valores invertida. Comparações de datas passaram a considerar integralmente o último dia do período.
- Fornecedores: seleção e preenchimento assistido, lista limitada aos órgãos e CNPJ para usuário comum, proteção de CPF e bloqueio de IDs fabricados.
- Tipos: configuração administrativa auditada, campos permitidos, obrigatoriedade no cadastro/troca do tipo, compatibilidade histórica e validação de CPFs novos. Requisitos não reescrevem contratos existentes.
- `/ready`: consulta efetiva da tabela de fila; indisponibilidade produz HTTP 503.

## Limites externos

O dump real não está neste checkout. Homologação dos contratos reais, decisões marcadas [PENDENTE], política institucional de retenção, destino externo de backup, domínio/TLS e publicação em produção dependem da área e da infraestrutura. Nenhum deploy externo foi realizado. P1 já inclui documentos, calendário, alertas internos, pesquisa avançada, fornecedores e requisitos por tipo. Os módulos financeiros de aditivos e dotação/empenhos dependem das regras solicitadas ao responsável. P2 permanece evolução.

## Cadastro nativo de aditivos — 11/10/2026

| Verificação | Resultado |
|---|---|
| Suíte completa SQLite | 77 testes aprovados, 935 asserções |
| MySQL em schema isolado | 22 testes aprovados, 276 asserções, abrangendo aditivos, conclusão de contratos, histórico/operação e relatório de auditoria |
| Formatação PHP | Pint aprovado nos 98 arquivos PHP |
| Assets | Build Vite aprovado |
| Navegador | Fluxo anterior e aditivos: criar rascunho, editar, registrar, associar/baixar documento, excluir/restaurar, bloquear edição de registro conferido pelo usuário comum, preservar valores/datas do contrato e formulário móvel |
| Backup e restauração | Checksums válidos; schema isolado restaurado e INSERTs de todas as tabelas, incluindo aditivos, iguais ao snapshot; storage e configuração recuperados |

Os sete testes novos cobrem os 44 campos, máscara de documentos pessoais, escopo atual, revogação da sessão do ator, validação de dados, isolamento de IDs e documentos entre contratos, versões concorrentes, números reservados após exclusão e reversão integral em falha de auditoria na criação. A restauração recuperou sete contratos fictícios, duas contas e 40 eventos de auditoria contratual. O cadastro nativo não aplica efeitos financeiros nem altera as datas de vigência do contrato; o histórico importado continua separado.

## Conclusão das funções internas e visual — 11/10/2026

| Verificação | Resultado |
|---|---|
| Suíte completa SQLite | 91 testes aprovados, 1.197 asserções |
| Suíte completa MySQL em schema isolado | 91 testes aprovados, 1.197 asserções |
| Formatação | Pint aprovado nos 114 arquivos PHP |
| Assets | Build Vite aprovado |
| CI remoto | [Execução do commit 1c70639](https://github.com/valdir-fernando/Contracts/actions/runs/38137490418) aprovada: instalação, formatação, build e suítes SQLite/MySQL |
| Navegador | Fluxos anteriores e dotação/ficha/empenho, edição, exclusão/restauração, conferência administrativa, relatório com colunas selecionadas, salvar/abrir/remover personalização, API autenticada, gráficos e largura móvel aprovados; nenhum erro JavaScript capturado |
| Visual | Capturas desktop e celular conferidas após refinamento do login, navegação, indicadores, formulários e tabelas |
| Produção | Imagens app/web compiladas e etiquetadas `1c70639`; runtime validou conexão, novas tabelas, debug desligado, cookies seguros, ausência de .env e de dependências de testes |
| Backup/restauração | SHA-256 válido, todas as tabelas restauradas em schema isolado e INSERTs iguais ao snapshot; storage/configuração recuperados |
| Aplicação local | `/ready` ready; migrations aplicadas e worker reiniciado |

Os 14 testes adicionais cobrem campos financeiros, precisão decimal, escopo e máscaras, associações explícitas, concorrência, exclusão/restauração, reversão de gravações em falha de auditoria, personalizações privadas, seleção de colunas consistente em CSV/XLSX/PDF, API paginada e auditada, indicadores do painel, conferência/reabertura da revisão e importação periódica atômica/idempotente. Um teste que dependia da ordem de chaves JSON foi corrigido para aceitar a ordenação canônica do MySQL; as duas suítes completas passaram após a correção.

A restauração final recuperou nove contratos fictícios, duas contas e 82 eventos de auditoria contratual, além das tabelas novas de registros financeiros e personalizações. O ensaio continua sintético. Assinatura eletrônica, integrações, publicação externa e homologação institucional permanecem pendências, conforme o [estado final](17_ESTADO_FINAL_E_PENDENCIAS.md).
