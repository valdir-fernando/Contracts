# Diagnóstico de navegação — 15/10/2026

No Docker local em Windows, o HTML de `/login` respondeu em 268 ms, enquanto a requisição de `resources/css/app.css` ao Vite permaneceu pendente por mais de 30 segundos. O serviço de desenvolvimento estava ativo, com `public/hot` apontando para a porta 5173.

O Tailwind agora usa `source(none)` e fontes explícitas em `resources/views`, `resources/js` e `app/Livewire`, evitando descoberta automática de arquivos no restante do projeto montado do Windows. Novos diretórios que contenham classes utilitárias precisam de uma declaração `@source` em `resources/css/app.css`.

Após a alteração e reinício do Vite, o CSS respondeu com HTTP 200 em 1,022 s na primeira compilação e 6 ms na seguinte. O CSS compilado servido pelo Nginx respondeu em 13 ms. As medições são locais e não representam o tempo completo de navegação entre telas autenticadas.

Comandos executados e resultados:

- `git status --short`: árvore limpa antes da alteração.
- `docker compose exec -T app php scripts/diagnose-performance.php`: leitura de 195 arquivos em 6 ms, bootstrap CLI em 390 ms, conexão/consulta ao banco em 12 ms; somente leitura.
- `curl.exe` com `time_starttransfer`/`time_total` para `/login` e `time_total` para CSS do Vite e do build: tempos acima.
- `docker compose restart node`: reinício concluído para repetir a compilação sem cache do processo anterior.
- `docker compose run --rm --no-deps node npm run build`: passou, compilação Vite em 1,51 s.
- `docker compose run --rm --no-deps node npm test`: 11 testes passaram.
- `git diff --check`: passou.

O Vite continua ativo. Não houve alteração de PHP, banco, permissões ou contratos HTTP; suites PHP não foram executadas. Não foi feita validação visual em navegador autenticado.
