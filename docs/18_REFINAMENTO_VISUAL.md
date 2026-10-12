# Refinamento visual — 11/10/2026

A aplicação recebeu uma identidade visual consistente em login, navegação, painel, contratos, relatórios, calendário e cadastros administrativos.

- Navegação em azul profundo, seções de acompanhamento/administração, ícones, indicação da página atual e menu compacto no celular, com fechamento por Escape.
- Tipografia Inter variável hospedada no projeto e compilada pelo Vite, com a licença OFL em `resources/fonts/Inter-OFL.txt`. Sem requisições a serviços de fontes durante o uso.
- Cores, espaçamentos, botões, campos, estados de foco, avisos, badges e tabelas padronizados no CSS compartilhado.
- Painel com quatro indicadores, distribuição temporal em gráfico circular, vencimentos mensais, órgãos, avisos e busca Livewire. Os gráficos utilizam os dados existentes e preservam os escopos de acesso.
- Filtros de contratos organizados, filtros avançados recolhíveis, resumo no detalhe e seções expansíveis para os 80 campos.
- Campos curtos de cadastro usam inputs; conteúdo longo ou com quebras de linha permanece em textarea. Navegação por etapas, requisitos por tipo recolhíveis e ações de gravação visíveis durante a rolagem.
- Relatórios com configuração principal, opções avançadas, resumo, exportações e tabelas que preservam a legibilidade com rolagem horizontal interna.
- Calendário com destaque para o dia atual e formulários organizados.
- Paginação em português compartilhada por listagens e painel, com indicação do intervalo e da página atual.

## Validação

- Build Vite concluído, incluindo as fontes locais; templates Blade compilados.
- Suíte completa SQLite: 91 testes aprovados, 1.197 asserções.
- Pint aprovado.
- Navegador: 18 telas em 1440, 1024, 768, 390 e 360 pixels, sem transbordamento horizontal da página e sem erros JavaScript.
- Busca Livewire, paginação normal/Livewire, menu por teclado, mudança de seção do formulário e restrição de acesso do usuário comum conferidos.

As capturas e os acessos usados no ensaio ficam fora do repositório, pois o ambiente local contém a base legada privada. O dump e os arquivos de importação continuam ignorados pelo Git. Esta entrega não publica a aplicação externamente.

## Ajuste fino de espaçamento e uso em telas menores

- Controles principais com altura de 44 px, ações de tabela com 36 px no desktop e 44 px no celular. Botões deixam de se esticar em grades de formulário; ações e links de limpeza ficam agrupados.
- Grades com espaçamento vertical consistente, sem acumular a margem do campo com a distância da grade. Campos extensos e justificativas ocupam a largura disponível; campos curtos dos aditivos usam inputs e preservam textos longos ou com quebras em textarea.
- Navegação compacta até 1024 px, mantendo abertura pelo teclado e fechamento por Escape. Tipografia auxiliar maior, contraste de textos secundários reforçado e campos móveis com 16 px para evitar zoom automático no iOS.
- Filtros de contratos e usuários e configuração dos relatórios recolhidos inicialmente no celular; consultas com filtros aplicados mantêm a configuração aberta. O comportamento sem JavaScript mantém os filtros disponíveis.
- Tabelas largas contidas no próprio painel, com indicação de rolagem e acesso pelo teclado quando necessário. A posição relativa do contêiner impede que textos ocultos de cabeçalhos causem transbordamento da página.
- Paginação móvel com indicação da página atual e total de páginas. Navegação entre seções do contrato move a rolagem e o foco para o título escolhido e preserva os valores digitados.
- Órgão e situação administrativa reunidos no cadastro do contrato. Formulários financeiros com cancelamento e justificativa mais legível; resumo de erros duplicado removido.

### Conferência deste ajuste

A prévia usou SQLite isolado e dados fictícios, incluindo nomes longos, valores altos e listas paginadas. Foram conferidas 22 telas em 320, 360, 390, 600, 768, 1024, 1100, 1440 e 1920 px: 198 combinações sem transbordamento horizontal da página ou erros JavaScript. A rolagem horizontal das tabelas permanece intencional.

Busca e paginação Livewire, filtros móveis, rolagem da tabela pelo teclado, menu por Escape, alternância de seções e exibição da senha foram exercitados. O ensaio funcional cobriu contratos, aditivos, documentos, registros financeiros, relatórios e permissões. O script abre as seções de detalhes antes de conferir conteúdo visível em painéis recolhíveis.

Suíte SQLite: 91 testes, 1.197 asserções. Pint, compilação dos assets e dos templates Blade aprovados. As capturas e os dados da prévia ficam fora do repositório.

## Barra lateral recolhível

No desktop, o botão ao lado da marca alterna entre a barra completa e uma faixa de 88 px com ícones. A preferência fica salva no navegador e é restaurada ao navegar ou recarregar. Os links preservam seus nomes acessíveis e exibem o nome da página ao passar o mouse. O menu de celular e tablet mantém sua abertura independente da preferência do desktop.

Conferidos no navegador: alternância por clique e teclado, navegação com a barra recolhida, persistência após recarga, retorno do celular ao desktop e ausência de transbordamento em oito larguras de 320 a 1920 px. Build e compilação Blade aprovados.


## Identidade visual e comportamento da marca

A direção visual foi refinada a partir da pesquisa das interfaces do [Linear](https://linear.app/now/behind-the-latest-design-refresh) e dos estados de navegação do [Carbon Design System](https://www.carbondesignsystem.com/building-blocks/core/components/ui-shell-left-panel/specifications). Após o ajuste de preferência visual, o Contracts usa azul-marinho na navegação, azuis nos destaques e superfícies claras, com Inter em títulos, controles e conteúdo. Painéis, indicadores, gráficos, tabelas, formulários e login compartilham essa identidade.

A navegação desktop ocupa uma coluna de 304 px, ou 284 px entre 1025 e 1200 px, ocupando toda a altura, sem margem externa ou cantos arredondados. Recolhida, ocupa 88 px. O botão de expansão assume o lugar da marca, sem um botão adicional abaixo dela: a marca aparece em repouso e revela o ícone de painel no hover e no foco de teclado. Os destinos recebem tooltips próprios por mouse ou teclado e mantêm nomes acessíveis. O estado permanece salvo no navegador.

Os selects simples mantêm seu comportamento nativo de escolha e teclado, com chevron desenhado no CSS, recuo de 16 px, 44 px de espaço reservado à direita e texto de 16 px no celular. O reset de tipografia tem baixa especificidade para respeitar o tamanho definido nos campos. Em modo de cores forçadas, o indicador nativo é restaurado.

Validação: 198 combinações de tela/largura sem transbordamento da página ou erros JavaScript; recolhimento, marca/ícone por hover e foco, tooltips, persistência e menu móvel conferidos. Suíte SQLite aprovada com 91 testes e 1.197 asserções; Pint, build Vite e compilação Blade aprovados.

## Contenção da sidebar e paleta azul

A mudança de largura da barra usa uma animação de 240 ms. A contenção dos textos e da conta evita transbordamento e barras de rolagem durante os estados intermediários. A preferência de movimento reduzido desativa a animação. O cabeçalho e a conta permanecem fixos dentro do painel; em telas baixas, apenas os destinos rolam, sem barras visíveis e com acesso por roda do mouse, trackpad e teclado. A sidebar não transborda na horizontal. A tipografia usa Inter também nos títulos e no login; fundos, controles, gráficos e navegação compartilham a paleta azul.

Conferência: 32 estados da sidebar em larguras de 1025 a 1920 px e alturas de 400 a 900 px, com 480 quadros observados durante a alternância, sem transbordamento do painel. Conta visível e todos os links alcançáveis por teclado. Revisão de 198 combinações de tela/largura sem problemas de layout ou erros JavaScript; selects, persistência e menu móvel aprovados. Build Vite e compilação Blade aprovados.

## Movimento, painel contínuo e campos obrigatórios

Sidebar encostada nas bordas, ocupando toda a altura, com animação de expansão e recolhimento. A marca mantém a função de expandir quando recolhida. O indicador reutilizável `required-mark` usa asterisco vermelho de 16 px e peso 800, junto ao rótulo; as condições de obrigatoriedade de contratos, aditivos e registros financeiros permanecem preservadas. Também aplicado a usuários, conta, login, documentos, justificativas e cadastros administrativos.

Validação: 32 estados da sidebar e 704 quadros durante a animação, sem transbordamento, em alturas de 400 a 900 px; navegação por teclado e rodapé acessíveis. Indicadores conferidos em oito formulários no desktop e celular. Ensaio funcional completo no navegador aprovado, assim como build e compilação Blade.

## Cadastros em modais

Fornecedores tem a ação “Novo fornecedor” no cabeçalho. Órgãos e permissões separa Fundos e Órgãos e secretarias em painéis com suas listas e ações “Novo fundo” e “Novo órgão”. A seção Fornecedores dessa tela também oferece cadastro e acesso à lista; escopos permanecem abaixo, com fundo e órgão selecionáveis.

Os três cadastros usam o mesmo componente de modal, com cantos arredondados, controles de 44 px, campos obrigatórios destacados e texto de 16 px no celular. O dialog nativo impede interação com o fundo e permite Escape; fechar ou cancelar devolve o foco ao botão de abertura. O fundo escurecido e desfocado aceita clique fora para fechar. Erros do servidor reabrem apenas o modal correspondente, preservam os dados e focam o primeiro campo inválido. CPF/CNPJ mantém a formatação e validação existentes.

Cadastro segue restrito a administradores. O retorno após salvar usa duas rotas internas permitidas, mantendo fornecedor na lista ou em Órgãos e permissões conforme a origem. Fundos e órgãos retornam aos seus painéis. Escopos e registros de auditoria preservam o comportamento anterior.

Validação: 10 testes de fornecedores, catálogos, escopos e permissões aprovados (96 asserções); 15 estados de modal em larguras de 320 a 1440 px, com abertura, cancelamento, Escape, clique fora e restauração de foco. Cadastros dos três tipos e retorno após erro exercitados no celular. Ensaio funcional completo, Pint, build Vite e compilação Blade aprovados.

## Gráficos, calendário e indicadores clicáveis

Gráficos usam cores mais vivas: esmeralda para vigente, rosa para vencido, âmbar para revisão e ciano para contratos a iniciar. Barras mensais usam azul sólido; barras por órgão usam azul/ciano. Os indicadores do início são links completos, com cores por situação, setas, hover e foco de teclado. Total, vigentes e vencidos abrem as listas existentes; revisão usa um filtro próprio que inclui pendências de vigência ou de identificação do órgão, preservando o critério e o total do painel. O filtro pode ser combinado com busca, desmarcado e levado aos relatórios e exportações.

Calendário destaca hoje, fins de semana e dias com vencimentos; datas passadas e os próximos sete dias recebem cores próprias e legenda. Os controles de mês anterior, próximo mês e mês atual preservam os limites de data aceitos. A grade continua acessível em telas menores, com contagem compacta por dia e descrição completa para leitores de tela.

Validação: 17 testes de consultas, calendário, indicadores, acesso e relatórios, com 307 asserções. No navegador, 54 páginas em nove larguras e 36 fluxos de clique nos cards conferiram os mesmos totais nas listas, preservação de revisão nos relatórios, datas e navegação mensal. Sem transbordamento da página ou erros JavaScript. Pint e compilação de assets e templates aprovados.

A conferência final em 1440, 390 e 320 px também garantiu que os sete dias da semana caibam na grade sem rolagem horizontal. Contagens ficam abaixo da data, controles de navegação têm alvo mínimo de 44 px e o destaque azul do card total permanece consistente.

## Conferência antes dos commits

Em 11/10/2026, a suíte completa passou com 94 testes e 1.239 asserções. Pint aprovado nos 115 arquivos PHP, assets compilados e templates Blade compilados. Os dados e capturas dos ensaios no navegador ficam fora do repositório; os cadastros fictícios usaram apenas a prévia SQLite isolada.

## Entrada animada dos gráficos e ícones de situação

Ao carregar o painel, o anel se revela em 900 ms; colunas e barras por órgão crescem em 700 ms, com pequenos atrasos entre os itens. As colunas mensais usam azul sólido. Rótulos e totais permanecem visíveis e estáveis durante a animação. Busca Livewire preserva os gráficos e não reinicia a entrada. Movimento reduzido apresenta imediatamente os valores finais.

Ícones dos cards têm fundo azul para cadastrados, verde para vigentes, vermelho para vencidos e âmbar para revisão, com símbolo branco. As variáveis de cor foram separadas das regras de estrutura para preservar as cores específicas de cada situação.

Conferência no navegador em 1440, 390 e 320 px: início, meio e fim do anel, colunas e barras por órgão; cores dos ícones e ausência de degradê nas colunas; busca Livewire sem reiniciar a animação e movimento reduzido sem animação. Sem transbordamento ou erros JavaScript. Build Vite e compilação Blade aprovados.

## Contagem animada dos cards

Os quatro indicadores do painel contam de zero até o total em 1.100 ms, com desaceleração suave. A formatação usa `pt-BR`, incluindo separador de milhares. Atualizações do mesmo total pelo Livewire não reiniciam a contagem; totais alterados partem do número que já está visível. Os rótulos acessíveis mantêm o valor final sem anunciar cada etapa da animação. Sem JavaScript, os números renderizados pelo servidor continuam disponíveis. Movimento reduzido mostra imediatamente os totais, inclusive se a preferência mudar durante a contagem.

Verificação no navegador: início e progressão da animação, totais finais, agrupamento `12.345`, zero, busca Livewire sem reinício, movimento reduzido no carregamento e durante a animação, acessibilidade e largura de 320 px. Cinco testes PHP dos indicadores e calendário aprovados com 67 asserções. Build Vite, compilação Blade e diferenças sem erros.

## Login centralizado

A tela de acesso apresenta a marca acima e fora do card, com 24 px de intervalo. O conjunto formado pela marca e pelo card fica centralizado na largura e na altura da tela, com cabeçalho alinhado ao centro. O painel lateral de apresentação foi retirado para que o formulário ocupe o centro da página. O card mantém cantos arredondados, fundo azul suave e largura máxima de 420 px. Em telas baixas, o conteúdo permite rolagem e mantém margens para os controles.

Conferido no navegador em 1440×900, 1024×768, 390×844, 320×568 e 667×375, incluindo centralização, ausência de rolagem horizontal e alternância da visibilidade da senha. Os oito testes de login passaram com 62 asserções. Build Vite, compilação Blade e diferenças sem erros. A atualização está disponível no túnel em execução.
