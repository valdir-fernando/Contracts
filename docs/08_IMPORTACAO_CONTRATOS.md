# Importação de contratos legados

A execução retomada encontrou a migration `2026_10_09_000004_create_contract_import` pendente e o importador sem testes específicos. A migration cria contratos, catálogos de fundos/secretarias/fornecedores, origem versionada, execuções e ocorrências de qualidade.

## Uso local

```powershell
docker compose exec -T app php artisan contracts:import --dry-run
docker compose exec -T app php artisan contracts:import
```

O arquivo padrão é `database-example/u910323952_bdgestao_niq.sql`. Use `--file=/var/www/html/caminho.sql` para outra referência acessível no container. O leitor interpreta valores literais; não executa instruções SQL do dump. A simulação executa o processamento em transação e desfaz as gravações, inclusive seu registro de execução.

## Preservação e conciliação

Os 80 campos de `TB_Contratos` são mapeados em `App\Support\ContractFields`. O conteúdo original fica em `legacy_records`, com tabela, identificador histórico e hash da versão. Datas zeradas/inválidas e valores monetários não convertíveis recebem ocorrência; valores monetários válidos são convertidos por strings para quatro casas decimais.

A importação não sobrescreve contratos existentes, inclusive excluídos logicamente. Repetir a mesma origem ignora registros existentes; uma versão alterada gera conflito para revisão. Erros de processamento desfazem a transação e preservam somente o registro de execução com estado `failed`.

Associações ambíguas permanecem sem vínculo. Contratos sem órgão conciliado ficam restritos a administradores pela consulta `Contract::visibleTo()` e pela Policy. Aditivos ainda não são importados; os indicadores existentes no contrato geram pendência e vigência em revisão. Os demais módulos do legado não são abrangidos por este comando.

## Validação da retomada

A simulação e a importação definitiva do dump local processaram os 2.145 contratos sem rejeição. Foram gravados 1.175 fornecedores, 8 fundos e 15 secretarias, além de 10.748 ocorrências de qualidade. A ausência de rejeições não significa ausência de pendências de conciliação.

Os testes automatizados cobrem preservação dos 80 campos, escape de texto SQL, moedas e datas, idempotência após exclusão lógica, alterações da origem sem sobrescrita, simulação sem persistência, rollback de arquivo incompleto, rejeição de expressões SQL e isolamento por escopo, inclusive vínculos desconhecidos e usuários inativos. Execute `docker compose exec -T app php vendor/bin/phpunit` e `docker compose exec -T app php vendor/bin/pint --test app database routes tests lang`.

## Continuidade

Esta etapa entrega a importação e a base dos contratos. A listagem e o detalhe foram implementados na entrega seguinte, descrita em [Consulta de contratos](09_CONSULTA_CONTRATOS.md), com integração à Policy e testes de isolamento por escopo nas rotas. Cadastro, edição e auditoria continuam pendentes conforme o backlog.
