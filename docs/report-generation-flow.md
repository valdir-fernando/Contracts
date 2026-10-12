# Geração de relatórios acompanhada — 12/10/2026

## Comportamento

Exportar PDF/XLSX/CSV nas telas de contratos ou auditoria cria imediatamente um card. A solicitação é encaminhada ao worker e a página continua disponível. O card acompanha preparação/geração e mostra **Baixar relatório** automaticamente quando pronto. A tela de exportações usa os mesmos estados, sem botão de atualização manual. Downloads continuam privados e auditados.

Quando uma geração acompanhada conclui, o download inicia automaticamente uma única vez, usando a mesma rota privada verificada pelo servidor. O botão permanece para baixar novamente. Relatórios que já estavam prontos ao abrir a tela não disparam novos downloads. Se a aba estiver oculta, a conclusão/download é acompanhada quando o usuário voltar.

O acompanhamento para quando o job conclui/falha/expira/perde autorização, pausa em abas ocultas e retoma após voltar pelo cache de navegação. Erros transitórios de rede repetem a consulta sem repetir a criação do job. Cliques duplicados no mesmo relatório pendente focam o card existente. Preferência por movimento reduzido desativa a animação do spinner; o estado tem anúncio acessível.

Nenhuma migration ou nova dependência foi necessária. As rotas anteriores preservam a resposta para chamadas diretas; a interface acrescenta `background=1` e `Accept: application/json`. O servidor responde 202 com ID/URL de status. A nova rota de status é privada, limitada e retorna projeção explícita. Somente o resultado pronto exige fingerprint completo; o download continua verificando SHA-256/tamanho, estado, expiração e autorização.

## Otimizações e medições

- A requisição da interface não conta os contratos para decidir entre geração imediata/fila e não renderiza arquivos. Solicitação/evento são gravados sob lock do ator; a fila é acionada após a transação.
- A captura/totalização lê 21 atributos usados no relatório, preservando filtros/escopos/casts, sem hidratar os demais campos extensos ou pessoais. Referência temporal é criada uma vez na captura; início/término são convertidos uma vez por linha.
- Consulta de status pendente não percorre todos os contratos. Não foi introduzido cache de autorização ou hash que aceite permissões antigas.
- Lotes originais de 60 linhas de PDF foram mantidos: ensaios com 180/30 linhas ou tabela com layout fixo pioraram tempo/memória. Nenhuma dessas alterações foi mantida.

Ensaio sintético em PHP 8.4.26, SQLite em memória, mesma máquina, 6.000 contratos com textos extensos fora da projeção. Comparação alternada na mesma execução, mantendo o algoritmo de linhas/totais igual e variando somente a seleção SQL:

| Leitura | Primeira captura | Segunda captura | Pico adicional de memória na captura aquecida |
| --- | --- | --- | --- |
| Todos os atributos | 5.248 ms | 6.416 ms | 127.392 bytes |
| Projeção do relatório | 5.605 ms | 3.953 ms | 44.872 bytes |

Média desse ensaio: 5.832 → 4.779 ms, aproximadamente 18% menor; pico adicional aquecido aproximadamente 65% menor. Há variabilidade importante, e não se trata de benchmark de produção ou garantia de aceleração de PDFs. O ensaio usa uma cópia temporária da classe de relatório com a projeção removida como comparador; não modifica o banco instalado.

Medição inicial de renderização, 600 contratos: CSV 481 ms, XLSX 872 ms e PDF 7.722 ms. PDF continua o formato mais caro e proporcional ao volume. A melhoria de experiência não depende de tornar essa renderização instantânea: ela ocorre fora da requisição, com acompanhamento automático.

## Validação executada

- `composer check`: Pint aprovado em 172 arquivos; suíte SQLite completa com 255 testes e 1.950 asserções, aprovada.
- `npm run build`: aprovado, Vite 7.3.7; assets atualizados localmente.
- Testes de fluxo: solicitação 202 sem renderização/COUNT, auditoria administrativa, dados pessoais/justificativa, proprietário, resposta explícita, status pendente sem consulta de contratos, revalidação de resultado pronto, estados finais/expiração, escopo/projeção e solicitação → geração → status → download verificado.
- Snapshot e segurança de relatórios continuam aprovados; registros antigos, tokens de geração, integridade e reautorização foram preservados.
- Playwright 1.58.2, `scripts/browser-report-generation.cjs`: card muda automaticamente de geração para pronto, interrompe consultas depois da conclusão, realiza download e cabe em largura móvel de 390px; sem erros JavaScript. Esse ensaio de interface usa respostas interceptadas; autorização e geração real são verificadas pelos testes HTTP/de integração separados.
- `view:clear`, restart do worker e `contracts:check-operations`: instalação local carregou os assets/código novos; worker/scheduler com atividade recente. Nenhum teste foi executado sobre seus dados.

Não houve commit, publicação externa ou geração de fixtures no banco instalado. A suíte desta etapa usa SQLite em memória/storage temporário; não foi repetida a suíte MySQL nessa mudança de interface/projeção.

## Ajuste de download automático

Alteração posterior apenas no JavaScript de acompanhamento, sem mudanças de autorização, fila ou endpoints. `npm run build` foi executado para atualizar os assets. O ensaio Playwright foi ampliado para verificar download sem clique após conclusão, ausência de duplicação, botão manual funcional e ausência de download automático ao abrir um relatório histórico já pronto. A suíte PHP não foi repetida nessa alteração exclusivamente de frontend.
