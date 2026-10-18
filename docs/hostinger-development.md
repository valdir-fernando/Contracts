# Desenvolvimento com banco na Hostinger

## O que muda no banco

Datas de calendário usam `AAAA-MM-DD` na gravação (por exemplo, `1971-12-30`) e `DD/MM/AAAA` nos campos da interface (`30/12/1971`). Os formulários convertem antes do envio e o servidor valida ISO, sem depender do idioma ou formato regional do MySQL. Isso vale também para nascimento e decreto de fiscais: datas brasileiras importadas são reconhecidas na leitura e gravadas em ISO ao salvar a ficha. A importação original permanece preservada na trilha, sem atualização em massa do histórico. Datas de calendário não recebem conversão de fuso horário.

Crie um banco separado, inicialmente vazio, para o Contracts. O banco original permanece como fonte de importação. Não execute as migrations diretamente no banco legado: a tabela `tb_N_Contrato` já existente colidiria com a migration de criação, e acrescentar campos às tabelas antigas não as transforma na estrutura usada pelo Laravel.

| Origem legada | Destino do Contracts | Melhoria atendida |
| --- | --- | --- |
| `tb_Fundo` | `contract_funds` | Login por fundo, alternância e dados cadastrais |
| `tb_Secretaria` | `contract_departments` | Secretarias e escopos de acesso |
| `tb_Gestor` | `tb_Gestor` | Sugestões do gestor no contrato |
| `tb_Fornecedor` | `suppliers` | Busca por CPF/CNPJ e preenchimento automático |
| `TB_Contratos` | `contracts` | Contratos, filtros por ano/fundo e edição auditada |
| `tb_N_Contrato` | `tb_N_Contrato` no NOVO banco | Contador independente por fundo e ano |
| Cadastros de usuários | `users`, `access_scopes`, `access_scope_user` | Autenticação e permissões atuais |

A máscara de CPF/CNPJ, a fonte do cabeçalho, o filtro anual e o botão de alternância não exigem campos adicionais nas tabelas antigas. Os dados de fornecedor/gestor são preservados em `raw_payload` e projetados conforme permissões. A escolha do fundo é contexto da sessão.

As migrations criam também auditoria, importações/ocorrências, aditivos, registros financeiros, documentos, preferências, regras de tipo, relatórios, fila e demais estruturas já implementadas. Não crie tabelas manualmente: `php artisan migrate` aplica todas em ordem e registra as versões em `migrations`.

A nova `tb_N_Contrato` possui `ID` (bigint), `fund_id` (FK), `ANO` (smallint), `FUNDO` (texto), `NUMERO` (bigint, padrão 0), `legacy_record_id` (FK opcional), `created_at` e `updated_at`. Há unicidade por `fund_id`/`ANO`. A tabela `contracts` recebe `number_year`/`number_sequence` opcionais e unicidade por fundo/ano/sequência. Detalhes: [numeração automática](contract-numbering-2026-10-13.md).

## Preparar o hPanel

1. Exporte o banco original pelo phpMyAdmin, incluindo estrutura e dados, em SQL UTF-8. Guarde esse backup em local privado.
2. Em Bancos de dados/MySQL, crie um banco de desenvolvimento e seu usuário. Anote os nomes completos, incluindo o prefixo da conta. Não importe o SQL legado diretamente pelo phpMyAdmin para esse banco: os comandos do Contracts fazem a conversão.
3. Em Sites → Painel, procure **MySQL remoto**. Autorize o IP público da conexão de internet deste computador para o banco novo. O IP interno `192.168...` ou o IP do contêiner não é o endereço a autorizar. Se o IP público mudar, atualize a autorização.
4. Copie o hostname indicado nessa tela e a porta (normalmente 3306). `localhost` não identifica a Hostinger quando o código roda aqui.
5. Confirme a versão do servidor, suporte a InnoDB e conexão criptografada. Se houver TLS, obtenha a CA e use o hostname correspondente ao certificado. Quando necessário, avalie um túnel SSH conforme os recursos do plano. A compatibilidade com o servidor remoto precisa ser conferida: os testes locais foram feitos em MySQL 8.4, não certificam qualquer versão de MariaDB.

Referência oficial: [acesso remoto MySQL na Hostinger](https://www.hostinger.com/support/1583546-how-to-set-up-remote-mysql-access-in-hostinger/).

## Configurar o projeto local

O overlay `compose.hostinger-dev.yaml` troca a conexão de app/worker/scheduler e usa volumes separados de storage/cache. O banco local e seu volume permanecem preservados. Ambos os modos usam o mesmo endereço local e os mesmos serviços: execute apenas um modo de cada vez.

No PowerShell, na pasta `C:\Projects\Contracts`:

```powershell
Copy-Item -LiteralPath .env.hostinger-dev.example -Destination .env.hostinger-dev
```

Preencha o arquivo com hostname, banco, usuário e senha do NOVO banco. Use aspas simples na senha para evitar interpolação de `$`. O arquivo privado é ignorado pelo Git; não envie senhas pelo chat. Se já existir, edite-o sem sobrescrever os valores.

Para obter uma chave própria do ambiente, execute localmente `docker compose exec app php artisan key:generate --show` e copie o resultado para `HOSTINGER_APP_KEY`. O comando mostra uma nova chave sem modificar a chave local. Guarde-a privadamente e mantenha-a nas próximas inicializações.

Se o provedor fornecer uma CA, coloque o arquivo em uma pasta privada do projeto e informe seu caminho dentro do contêiner em `HOSTINGER_MYSQL_SSL_CA`, por exemplo `/var/www/html/.codex/hostinger-ca.pem`. Não use um caminho `C:\...` nesse campo.

Defina esta função no terminal para que todos os comandos usem explicitamente os dois arquivos:

```powershell
function Invoke-HostingerCompose {
    & docker compose --env-file .env --env-file .env.hostinger-dev -f compose.yaml -f compose.hostinger-dev.yaml @args
    if ($LASTEXITCODE -ne 0) { throw 'Falha no comando Docker/Contracts.' }
}
Invoke-HostingerCompose config --quiet
```

Use `config --quiet`; a saída completa de `config` pode mostrar senhas. O arquivo remoto só usa variáveis `HOSTINGER_*`, portanto não modifica as credenciais interpoladas do serviço MySQL local.

Com o ambiente local já instalado (dependências/imagem PHP disponíveis), pare seus processos antes de trocar de banco:

```powershell
docker compose stop web worker scheduler app
Invoke-HostingerCompose up -d app
Invoke-HostingerCompose exec app php artisan config:clear
```

Antes de qualquer migration, confira o banco efetivo pelo comando de leitura abaixo. Ele mostra somente nome do schema e versão, sem credenciais ou dados pessoais:

```powershell
Invoke-HostingerCompose exec app php artisan tinker --execute="dump(DB::selectOne('SELECT DATABASE() AS database_name, VERSION() AS server_version'));"
```

O nome deve ser exatamente o NOVO banco. Se não for, pare e corrija a configuração. Banco vazio pode produzir `Migration table not found` em `migrate:status`; isso é esperado antes da primeira migration.

## Criar tabelas e importar dados

Execute somente no novo banco conferido:

```powershell
Invoke-HostingerCompose exec app php artisan migrate --pretend
Invoke-HostingerCompose exec app php artisan migrate --force
Invoke-HostingerCompose exec app php artisan migrate:status
```

`--pretend` apresenta o SQL previsto; a execução seguinte cria as tabelas. Não use `migrate:fresh`, `refresh`, `reset` ou rollback no banco hospedado. Futuras atualizações usam `migrate --force` após backup e conferência do destino.

Guarde o export atualizado em `database-example/u910323952_bdgestao_niq.sql`, caminho privado e não versionado. Rode, em ordem:

```powershell
Invoke-HostingerCompose exec app php artisan contracts:import --file=database-example/u910323952_bdgestao_niq.sql --dry-run
Invoke-HostingerCompose exec app php artisan contracts:import --file=database-example/u910323952_bdgestao_niq.sql
Invoke-HostingerCompose exec app php artisan contracts:import-history database-example/u910323952_bdgestao_niq.sql --dry-run
Invoke-HostingerCompose exec app php artisan contracts:import-history database-example/u910323952_bdgestao_niq.sql
Invoke-HostingerCompose exec app php artisan contracts:import-contract-counters 2026 database-example/u910323952_bdgestao_niq.sql --dry-run
Invoke-HostingerCompose exec app php artisan contracts:import-contract-counters 2026 database-example/u910323952_bdgestao_niq.sql
Invoke-HostingerCompose exec app php artisan contracts:create-admin ADMIN --name="Administrador"
```

Examine cada simulação antes da importação correspondente. A importação completa inclui fundos, secretarias, fornecedores e gestores. Históricos são preservados sem efeitos financeiros presumidos. O ano 2026 corresponde aos contadores atuais confirmados; uma futura origem de outro ano exige informar o ano correto. IDs internos podem diferir dos IDs legados.

O administrador é criado com senha solicitada de forma oculta. Cadastre os demais usuários e atribua escopos na interface. Importar usuários legados é uma decisão separada; a importação de contratos não provisiona automaticamente essas contas. Se quiser migrá-los, revisar o `ReferenceUsersSeeder`, o dump padrão e os acessos antes de executá-lo.

Compile os assets e inicie os processos:

```powershell
docker compose run --rm --no-deps node npm run build
Invoke-HostingerCompose up -d web worker scheduler
Invoke-HostingerCompose exec app php artisan contracts:check-operations
```

Use o build estático, com Vite desligado e sem `public/hot` de uma sessão anterior. Acesse `http://127.0.0.1:8000`. Confira fundos, gestores, fornecedores, contratos por ano, permissões e contadores. Faça um cadastro de teste somente no banco de desenvolvimento, verificando número, sequência e auditoria; esse cadastro consome uma sequência nesse ambiente. Documentos privados criados aqui ficam no volume remoto de desenvolvimento do computador, não são enviados automaticamente para a Hostinger.

Para voltar ao banco local:

```powershell
Invoke-HostingerCompose stop web worker scheduler app
docker compose up -d app web worker scheduler
```

Nunca use `down -v` para trocar ambientes. O script `start.ps1` é destinado ao modo local; não o use para inicializar o remoto, pois ele executa migrations automaticamente.

## Atualizações da origem e entrada em produção

A importação é por snapshot SQL, não sincronização em tempo real nem escrita de volta no banco antigo. Alterações posteriores na origem podem gerar conflitos que precisam ser conciliados. Se o sistema antigo continuar emitindo contratos, este ambiente deve permanecer de desenvolvimento: os dois sistemas não compartilham a reserva de números. Na migração definitiva, interrompa escritas no sistema antigo, exporte a última versão, importe contratos/histórico/contadores no banco definitivo, confira e só então libere o novo sistema. Dados de teste não devem ir para produção.

A hospedagem do PHP exige conferir plano, SSH/Composer, PHP 8.4 (igual ao ambiente validado), extensões do lockfile, acesso ao banco e limites de execução. Use `composer check-platform-reqs --no-dev` com o PHP efetivo do servidor, sem ignorar requisitos. A versão PHP do terminal e a do site precisam ser compatíveis. Instale este projeto; o instalador automático de outro Laravel não substitui o código atual.

O site deve servir somente a pasta `public`, mantendo `.env`, dumps, vendor e storage privado fora da raiz pública. Use HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` público, cookies seguros e chave própria da produção. Instale dependências com `composer install --no-dev --prefer-dist --optimize-autoloader`, publique o build Vite e dê permissão de escrita a `storage`/`bootstrap/cache`. Preserve a chave depois da instalação. A configuração final depende do plano e não foi aplicada à Hostinger nesta etapa.

O scheduler precisa executar `php /CAMINHO/DO/PROJETO/artisan schedule:run` a cada minuto. A fila de relatórios precisa de worker com timeout e memória compatíveis com o projeto; em VPS pode ser gerenciada como serviço. Em Web/Cloud confirme limites do plano antes de adaptar o processamento para cron. Não substituir a fila por execução síncrona para contornar limites de hospedagem. Referências: [frameworks suportados](https://www.hostinger.com/support/which-programming-languages-and-frameworks-are-supported-at-hostinger/) e [processos e capacidades](https://www.hostinger.com/support/which-server-capabilities-are-supported-at-hostinger/).

Backup de produção precisa incluir banco, arquivos privados e chave/configuração, com restauração conferida em ambiente isolado. Os scripts Docker de backup atuais visam o serviço MySQL local; não presumir que fazem backup do banco remoto. Consulte também [operação do projeto](11_MVP_E_OPERACAO.md).

## Validação desta preparação

As instruções foram conferidas com migrations/importadores/configuração existentes e documentação oficial da Hostinger. Executado `docker compose --env-file .env --env-file .env.hostinger-dev.example -f compose.yaml -f compose.hostinger-dev.yaml config --format json`, com variáveis de ambiente fictícias somente no processo de validação: configuração aprovada. Foram verificadas a conexão remota nos três serviços, a substituição dos volumes de storage/cache e a preservação do banco local. O template vazio também recusou a configuração por ausência de campos obrigatórios, como previsto. `git diff --check` aprovado. Não foram iniciados serviços nem acessado banco remoto; nenhuma migration ou teste de aplicação foi executado nesta preparação de documentação/configuração. Ainda são necessários o plano, hostname, nome do banco novo e credenciais locais privadas para testar a conexão e instalar. Nenhuma alteração foi realizada no banco original da Hostinger.
