# Decretos de fiscais — 18/10/2026

O botão Decreto do Fiscal_Contrato emite diretamente o PDF do decreto cadastrado no fiscal aberto. O servidor reúne os fiscais ativos do mesmo número/data e escolhe automaticamente entre individual e coletivo. Sem ficha aberta, permite escolher um fiscal de referência. A relação contém apenas nome e Secretaria/Fundo. [Modelos, regras, origem dos dados e operação](../resources/documents/decrees/README.md).

Secretaria aplica-se exclusivamente ao Município de Niquelândia. Nos demais Fundos, o decreto usa somente o Fundo e ignora vínculos legados de Secretaria, inclusive inexistentes, sem alterar os dados históricos. A ficha oculta/desabilita a Secretaria nesses Fundos e a listagem apresenta “Não se aplica”; o backend já recusa novos vínculos incompatíveis. Verificação deste ajuste: `phpunit tests/Feature/InspectorDecreeTest.php tests/Feature/InspectorRegistrationTest.php --do-not-cache-result` no container, SQLite em memória, com 25 testes e 197 asserções aprovados; Blade recompilado. Sem mudanças em assets, dispensando build.

Rotas autenticadas: `GET /inspectors/decree` e `POST /inspectors/decree`, com limitação de frequência na emissão. Acesso exclusivo de administrador atual. Não há migration, alteração dos registros selecionados, persistência de PDF público ou uso do delegado do Executivo como emitente.

O adaptador `InspectorDecree` recebe `LockActor` por construtor. Em transações separadas antes/depois da renderização bloqueia primeiro o ator, depois Fundos, Prefeito e fiscais, mantendo a auditoria na autorização final. A revisão HMAC vincula fontes, modelos, configuração, ator/sessão e validade. A emissão direta verifica também a revisão da ficha aberta antes de preparar o grupo. Dados pessoais não utilizados ficam internos ao fingerprint, sem projeção para o navegador ou documento. O anexo coletivo associa cada fiscal à Secretaria/Fundo e permite reunir fiscais de fundos distintos. Integrantes ativos com vínculo indisponível bloqueiam a emissão; não são omitidos silenciosamente.

Validação executada em SQLite em memória; sem alterações ou testes no banco real. Não houve commit/push ou verificação visual interativa no navegador.

O alerta de vínculo indisponível ou ambíguo identifica nominalmente todos os fiscais afetados do decreto, separados por ponto e vírgula, sem incluir fiscais com vínculo válido. Validação desse ajuste: `docker compose exec -T app php vendor/bin/phpunit tests/Feature/InspectorDecreeTest.php --do-not-cache-result` aprovado com 11 testes e 69 asserções; `git diff --check` sem erros. Sem alteração em assets, dispensando novo build.

Comandos efetivamente executados na integração inicial (antes do agrupamento automático):

- `docker compose exec -T app composer check`: Pint aprovado; 518 testes, 4.813 asserções, nenhum erro e um teste de concorrência MySQL ignorado por exigir schema descartável isolado. Inclui Architecture e Unit.
- Após os ajustes finais, `docker compose exec -T app php vendor/bin/phpunit tests/Feature/InspectorDecreeTest.php --do-not-cache-result`: 9 testes e 51 asserções aprovados.
- `npm.cmd test`: 84 testes aprovados, incluindo quatro verificações do fluxo de emissão e download.
- `docker compose run --rm --no-deps node npm run build`: aprovado.
- `docker compose exec -T app php vendor/bin/pint --dirty`, `docker compose exec -T app php artisan view:cache` e `git diff --check`: aprovados; Git apenas informa normalização CRLF/LF em arquivos existentes.
- `curl.exe` no endpoint local `/up`: HTTP 200.

Validação após o agrupamento automático e simplificação dos dados:

- `docker compose exec -T app php vendor/bin/phpunit tests/Feature/InspectorDecreeTest.php tests/Feature/InspectorRegistrationTest.php --do-not-cache-result`: 23 testes, 179 asserções aprovados. Inclui agrupamento sem seleção manual, número/data distintos, datas legadas, secretaria municipal, fundos, exclusão/inatividade, vínculo inválido e revisão da ficha aberta.
- `docker compose exec -T app php vendor/bin/phpunit --testsuite Architecture,Unit --do-not-cache-result`: 60 testes, 133 asserções aprovados.
- `npm.cmd test`: 84 testes aprovados; o fluxo JavaScript aceita o botão associado ao formulário externo da emissão direta.
- `docker compose run --rm --no-deps node npm run build`, Pint, `php artisan view:cache` e `git diff --check`: aprovados. Suíte PHP completa não repetida nesta alteração; testes relevantes executados em SQLite em memória. Sem validação visual interativa.
