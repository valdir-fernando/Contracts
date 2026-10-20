# Espaços nas entradas

Campos textuais editáveis removem espaços, tabs e quebras de linha somente das extremidades ao perder o foco e antes do envio. Espaços internos são preservados. A delegação de eventos cobre campos dinâmicos; o evento `input` informa alterações aos consumidores reativos. `submit` e `formdata` cobrem envios convencionais e FormData de salvamentos JavaScript, preservando nomes repetidos e arquivos.

O middleware HTTP `TrimInputStrings` substitui o TrimStrings padrão do Laravel e inclui senhas, confirmação e senha atual na mesma normalização. Consultas e payloads aninhados são tratados antes da validação. Nenhum registro existente é atualizado em lote; a regra se aplica às próximas entradas.

Validação executada em Docker: `npm test` passou (97 testes), `php artisan test --filter=TrimInputStringsTest` passou (1 teste, 6 asserções), `vendor/bin/pint --dirty` passou e `npm run build` passou. `git diff --check` passou. Sem ensaio manual autenticado em navegador.
