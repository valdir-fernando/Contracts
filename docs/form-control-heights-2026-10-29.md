# Altura dos campos da suíte

Os campos de uma linha compartilham a altura de Novo contrato em cadastros, contratos/aditivos, financeiro, relatórios/filtros, Minha conta, usuários, login, dotação e janelas de edição.

O CSS usa `--form-single-line-height` no escopo global: 34,8px em desktop e 39,6px em telas de até 600px, com fontes de 13px e 16px, respectivamente. `--contract-single-line-height` referencia esse padrão. O preenchimento vertical é de 6px. Botões de calendário e de mostrar senha acompanham o tamanho compacto.

Textos com várias linhas, listas de seleção múltipla, checkboxes, radios, uploads e o número destacado do contrato preservam seus formatos próprios. Campos de endereço mantêm o crescimento automático existente. O seletor nativo de data, usado internamente pelo calendário, permanece oculto com 1px. A regra móvel dos usuários não reduz mais a altura da lista de órgãos com seleção múltipla.

## Validação executada

- `docker compose run --rm --no-deps node npm run build`: aprovado após cada ajuste de CSS.
- `git diff --check`: aprovado.
- Chrome headless com `--dump-dom`, `--virtual-time-budget=3000` e janelas de 1280×900 e 500×900: fixture HTML temporária carregando o CSS compilado servido pela aplicação local. Doze controles representativos por tamanho apresentaram 34,796875px e 39,59375px, respectivamente, dentro da tolerância de 0,1px. Conferidos também seleção múltipla, texto de duas linhas, checkbox, input oculto e seletor nativo de data. Verificação final sem falhas nos dois tamanhos.

A fixture foi criada em `%TEMP%/contracts-control-height-check`, sem autenticação, gravações ou consultas ao banco. Não houve ensaio dos fluxos autenticados nem nova execução de PHPUnit; a alteração desta etapa é exclusivamente de apresentação CSS.
