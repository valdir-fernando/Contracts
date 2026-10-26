# Contracts

[Entrega de 26/10/2026: relatórios, Painel de Acompanhamento e validação](docs/delivery-2026-10-26.md).

Relatórios e indicadores usam início de vigência, término atualizado e valor atualizado, desconsiderando revisão na seleção. [Critérios e compatibilidade](docs/report-criteria-2026-10-26.md).

O botão **Gerar PDF** em **Relatórios de contratos** exporta a lista filtrada em **A4 horizontal**, com o título da mensagem do painel. [Formato, filtros e validação](docs/contract-panel-pdf.md).

Os aditivos históricos podem ser copiados para `tb_Aditivos` por `contracts:import-amendments --apply`, com origem, histórico e auditoria preservados. Sem `--apply`, apenas simula. [Procedimento e pendências](docs/historical-amendment-import.md).

O botão **Contrato** também emite **Credenciamento Saúde**, usando o Word fornecido, os dados profissionais salvos e a conferência de produtos e dotações. [Campos, referências do modelo e validação](docs/health-accreditation-contract.md).

[Entrega de 23/10/2026 e validação antes do push](docs/delivery-2026-10-23.md).

O botão **Contrato** também emite o PDF de **Obras**, usando o modelo Word fornecido e concordância automática para uma ou várias dotações. [Preenchimento e validação](docs/works-contract.md).

Produtos dos aditivos ficam em `tb_Prtodutos_Aditivos`, com origem histórica, referências do aditivo e auditoria preservadas. [Estrutura, separação segura e comandos](docs/amendment-product-separation.md).

[Entrega de 21/10/2026 e validação antes do push](docs/delivery-2026-10-21.md).

O botão **Contrato** emite o PDF de **Serviços Diversos** usando o modelo Word fornecido, os produtos do contrato original e as dotações por ficha. [Preenchimento, segurança e validação](docs/services-contract.md).

O **Cadastro de Dotação** permite salvar, abrir e excluir logicamente registros de `tb_Dotacao`, com Dotação Orçamentária composta automaticamente pelos códigos. [Campos, composição, segurança e instalação](docs/budget-registration.md).

O **Painel de Ficha** exibe apenas fichas do contrato original, filtradas por Fundo, Contrato e Proc_Cont, com Proc_ADT/N_ADT vazios. Mostra a soma e sua conferência com o valor original. [Regras, migration e validação](docs/original-contract-ficha-panel.md).

O botão **Contrato** também emite o PDF de **Locação de Imóvel**, usando o modelo Word fornecido, os itens salvos e os valores mensal/global. [Preenchimento, segurança e validação](docs/property-lease-contract.md).

O menu **Publicação** permite enviar e consultar PDFs assinados por contrato, com seis categorias e histórico privado. [Como usar, instalação e segurança](docs/publication-module-2026-10-20.md).

A tabela atual `contracts` possui seis campos para referências privadas aos documentos assinados. [Estrutura, instalação e escopo](docs/signed-document-fields-2026-10-20.md).

O campo **Tipo de contrato** tem ícone informativo para valores históricos e correções de grafia opcionais. [Comportamento e manutenção auditada](docs/historical-contract-types.md).

Ordens de Serviço têm modelos PDF específicos para **Obras** e **Serviços**, com cabeçalho institucional, quadro de prazos e assinaturas. [Modelos e exemplos](docs/service-order-models-2026-10-20.md).

Ordens de Serviço usam a tabela `tb_OS`. Importação do modelo: `contracts:import-service-orders --dry-run`. [Renomeação, resultados e pendência de vínculo](docs/service-order-import-2026-10-20.md).

A lupa de contratos carrega a ficha com os dados salvos do contratado, contratante e fiscal, preservando o histórico e isolando valores de tentativas anteriores em outros formulários. [Comportamento e validação](docs/contract-search-saved-form.md).

O botão **Extrato** também emite o PDF de **Locação de Imóvel**, usando o modelo Word fornecido e os valores salvos na ficha. [Campos e validação](docs/property-lease-extract.md).

[Entrega de 19/10/2026 e validação antes do push](docs/delivery-2026-10-19.md).

Importação dos produtos do banco de modelo: `contracts:import-contract-products --dry-run` confere os vínculos; sem `--dry-run`, importa os registros confirmados sem recalcular totais ou saldos. [Procedimento e resultados](docs/model-data-import-2026-10-19.md).

No **Cadastro de produtos**, a lista e as opções de **Ficha** exibem apenas itens do contrato original, conferidos por Fundo, contrato, Processo e identificação legada de aditivo. **Ficha** é uma seleção editável e permite digitar outra no mesmo campo. Salvar um produto acrescenta a nova ficha à lista; escolher uma ficha filtra **Produtos do contrato**. [Comportamento e validação](docs/product-ficha-selection-2026-10-19.md).

O botão **Contrato** emite o PDF dos contratos salvos de **Fornecimento**, com tabela única de produtos agrupados por item, conferência dos totais, dotações somadas por ficha e assinaturas. Também atende **Locação de Imóvel**, conforme o guia acima; os demais tipos aguardam seus modelos. [Preenchimento, segurança e validação do Fornecimento](docs/supply-contract-pdf-2026-10-19.md).

Relatórios de contratos incluem filtro, agrupamento e coluna por **Fiscal**, com exportação PDF, Excel e CSV. [Como usar e regras](docs/reports-by-inspector.md).

Perfis de usuários: **Administrador Master** gerencia todos os perfis; **Administrador** gerencia apenas Usuários. FERNANDO foi promovido a Master no ambiente local. [Regras, segurança e promoção](docs/user-role-hierarchy-2026-10-18.md).

O botão **Decreto** do **Fiscal_Contrato** reúne automaticamente os fiscais do mesmo número/data de decreto e gera o PDF individual ou coletivo com nome e Secretaria/Fundo, sempre com dados do Município e assinatura do Prefeito. [Operação e validação](docs/inspector-decrees-2026-10-18.md).

O menu **Cadastros → Gestor_Contrato** abre o cadastro de **Gestor de Contratos**, separado de Fiscal_Contrato e Gestor_Fundo. [Campos, instalação e segurança](docs/contract-steward-registration-2026-10-18.md).

O menu **Cadastros → Gestor_Fundo** permite cadastrar, editar e excluir logicamente gestores com os campos do legado, no padrão visual do sistema. [Instalação, segurança e validação](docs/manager-fund-registration-2026-10-18.md).

Fornecedor e Fundo consultam dados públicos do CNPJ em JSON para preencher novos cadastros e comparar cadastros existentes antes de salvar. [Fonte, comportamento e validação](docs/cnpj-consultation-2026-10-18.md).

O **Cadastro de Fundo** reúne os campos de `tb_Fundo`, busca da ficha existente pelo CNPJ, edição, validação e exclusão lógica protegida, no padrão visual de Novo contrato. [Campos, instalação e validação](docs/fund-registration-2026-10-18.md).

Datas aparecem como **dia/mês/ano** na interface e nos relatórios **PDF, Excel e CSV** e são enviadas e gravadas como **ano-mês-dia**, inclusive na hospedagem Hostinger.

Nos campos de preenchimento, **Enter** avança ao próximo campo sem salvar. Botões selecionados mantêm sua ação por Enter. [Regras e validação](docs/enter-navigation-2026-10-18.md).

O menu **Cadastros → Fiscal_Contrato** possui ficha com os campos do cadastro legado no padrão de Novo contrato, edição e auditoria transacional. [Instalação e validação](docs/inspector-registration-2026-10-18.md).

O botão **Publicação** gera um PDF para contratos salvos com o modelo do Diário Público. [Campos e emissão](docs/contract-publication-2026-10-17.md).

O botão **Extrato** gera o PDF de contratos salvos de **Fornecimento**, usando o modelo Word fornecido e a administração configurada. [Campos, segurança e validação](docs/supply-contract-extract-2026-10-17.md).

A administração dos documentos inicia em **2025/2028** e pode ser alterada por um administrador em **Órgãos e permissões → Administração nos documentos**. [Configuração e validação](docs/document-administration-2026-10-17.md).

Em **Minha conta**, o usuário pode escolher, visualizar, salvar ou remover sua **foto de perfil**, exibida nos círculos do cabeçalho e do menu lateral. [Instalação, privacidade e validação](docs/profile-photo-2026-10-17.md).

O **Acervo Contratual** abre com o exercício do ano atual selecionado e a lista filtrada. É possível escolher exercícios anteriores ou **Todos**. [Comportamento e validação](docs/contract-archive-2026-10-17.md).

No Início, **Valor dos contratos do Fundo** usa o valor acumulado e, quando ausente, o valor inicial de cada contrato, respeitando Fundo e exercício selecionados. [Regra e validação](docs/overview-contract-value-2026-10-17.md).

O **Calendário de vencimentos** mostra somente contratos do Fundo selecionado no login, incluindo lista e contagens. [Regra e validação](docs/calendar-selected-fund-2026-10-17.md).

O botão **8.Produtos** abre uma janela ampla para buscar por Item os produtos já existentes na `tb_Produtos` e lançá-los nos contratos. A opção **Exibir produtos do processo** permite compartilhar o saldo entre fornecedores de um credenciamento; desmarcada, filtra pelo fornecedor. Inclui ficha opcional, estorno e recálculo do valor. [Instalação e regras](docs/contract-products-2026-10-16.md).

O painel **5. Vigência** permite abrir as [Ordens de Serviço](docs/service-orders-2026-10-15.md) pelo botão **Emitir Ordem de Serviço** em contratos salvos de Serviços Diversos e Obras, com dados do Fundo/Fornecedor/Objeto/Contrato, datas de execução, Período e PDF privado. Período preenche **Prazo da obra**, que é somente leitura. Suporta múltiplas ordens e vínculo opcional a aditivos; exige as migrations aditivas do guia.

Dados Contratante permanece somente para consulta, com fiscal automático por Fundo ou Secretaria do Município. [Importação de `tb_Fiscal`, comportamento e validação](docs/inspector-autofill-2026-10-15.md).

Banco de desenvolvimento na Hostinger: [criação das tabelas, conexão remota, importação e preparação da hospedagem](docs/hostinger-development.md). A configuração opcional `compose.hostinger-dev.yaml` preserva o banco local e usa storage/cache separados.

Sistema de gestão de contratos com Laravel 13, Livewire 4/Alpine, Blade, Tailwind 4, PHP 8.4, Nginx, MySQL 8.4 e Vite.

O login exige selecionar um Fundo autorizado. O Início exibe esse Fundo, e novos contratos usam sugestões do Fundo e do gestor cadastrado na `tb_Gestor`. [Comportamento, instalação e validação](docs/login-fundo-2026-10-13.md).

O Início mostra os contratos do Fundo selecionado e do exercício atual por padrão. O seletor **Ano dos contratos** permite consultar exercícios anteriores ou **Todos os anos**, atualizando indicadores, gráficos, alertas e a lista recente. O Fundo é escolhido na tela de login; para trocar, saia e entre novamente selecionando outro Fundo autorizado.

Novos contratos aplicam máscara de CPF/CNPJ e preenchem os dados do fornecedor ao sair do campo ou selecionar um cadastro, usando a `tb_Fornecedor` importada e respeitando permissões. [Comportamento e validação](docs/supplier-autofill-2026-10-13.md). O [cadastro de fornecedores](docs/supplier-registration-2026-10-15.md) permite preencher os dados cadastrais, contatos e representante.

A numeração de novos contratos é automática por Fundo e ano, atribuída ao salvar: `001/26` até `999/26`, continuando com `1000/26`. Identificação completa guarda a sequência sem máscara. A virada do ano inicia um contador independente, e gravação/auditoria/contador são transacionais. [Instalação e validação](docs/contract-numbering-2026-10-13.md).

Auditoria incremental: [correções de segurança, concorrência e exportações — 12/10/2026](docs/technical-audit-2026-10-12.md). Cadastros potencialmente duplicados agora pedem confirmação antes da gravação. Exportações anteriores à atualização do hash precisam ser solicitadas novamente.

Verificação posterior: [sete achados](docs/audit-findings-2026-10-12.md) e [correções e validação](docs/audit-corrections-2026-10-12.md). Campos protegidos permanecem intactos com entradas vazias; importações históricas alteram versão e auditoria; administração de usuários detecta formulários antigos; exportações verificam integridade e encerram gerações abandonadas.

Desenvolvimento: [arquitetura e fronteiras](docs/architecture.md), [evolução do núcleo de contratos](docs/clean-architecture-development.md) e [regras do repositório](AGENTS.md). `composer check` executa Pint e todas as suites; `composer check:architecture` verifica as dependências entre camadas; `composer test:unit` testa regras e casos de uso sem Laravel/banco.

Login, usuários e escopos, cadastro/edição dos 80 campos, exclusão/restauração, histórico de aditivos/itens, conciliação, relatórios PDF/XLSX/CSV, painel, auditoria, conta pessoal, fila, backup e imagens de produção. Guia: [MVP e operação](docs/11_MVP_E_OPERACAO.md). Evoluções: [documentos privados, calendário, alertas e pesquisa avançada](docs/13_EVOLUCOES_P1.md) e [cadastro de aditivos](docs/14_ADITIVOS.md). [Estado final e pendências externas](docs/17_ESTADO_FINAL_E_PENDENCIAS.md): dotação/fichas/empenhos, conferência administrativa, personalização de relatórios, gráficos, API interna, importação periódica e visual refinado.

## Iniciar no Linux/macOS

```bash
./scripts/start.sh
docker compose exec app php artisan contracts:create-admin ADMIN
```

A senha inicial é solicitada de forma oculta. Configure órgãos e escopos na aplicação; o dump legado é opcional.

## Iniciar no Windows

Instale e abra o Docker Desktop com containers Linux. Na pasta do projeto, execute:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start.ps1
```

O script cria `.env` se necessário, constrói a imagem PHP, instala as dependências, gera a chave da aplicação apenas se estiver vazia, inicia o banco, executa as migrations e inicia Nginx e Vite.

Acesse http://127.0.0.1:8000. O endpoint de saúde é http://127.0.0.1:8000/up. Esse endereço evita o atraso de tentativa IPv6 de `localhost` quando as portas estão publicadas apenas em IPv4.

Por padrão, o script compila os assets e mantém o Vite desligado. Para editar CSS/JavaScript com recarregamento automático, execute `powershell -ExecutionPolicy Bypass -File .\scripts\start.ps1 -HotReload`. O serviço `node` usa o perfil `assets` e só inicia quando solicitado explicitamente.

## Comandos úteis

O dump legado e os dados locais não são versionados. Para importar dados reais, disponibilize o arquivo localmente em `database-example/u910323952_bdgestao_niq.sql`; os testes usam dados fictícios e não dependem desse arquivo. Os comandos de seed/importação legada exigem o dump, mas a inicialização do ambiente não o exige.

```powershell
docker compose up -d
docker compose down
docker compose logs -f
docker compose exec app php artisan migrate
docker compose exec app php artisan make:model Contract -m
docker compose exec app composer require vendor/package
docker compose run --rm --no-deps node npm run build
```

O MySQL usa um volume persistente e fica acessível aos containers em `db:3306`. As credenciais locais estão em `.env.example`. Alterar as credenciais após criar o volume também exige atualizar o usuário no banco.

Os arquivos da aplicação são montados nos containers; alterações em PHP são aplicadas diretamente e o Vite recarrega os assets. `docker compose down` preserva o banco. Após interromper o Vite, remova `public/hot` para usar os assets de um build estático.

## Desempenho no Windows

O código permanece montado a partir do Windows. Dependências PHP (`vendor`), arquivos de execução (`storage`) e cache do bootstrap usam volumes Linux (`php_vendor`, `app_storage`, `app_cache`) para evitar milhares de acessos ao disco do Windows em cada requisição. Instale dependências PHP pelo container, usando `docker compose exec app composer install`; a pasta `vendor` do Windows não é sincronizada com o volume. Logs e sessões ativos também ficam no volume: consulte-os com `docker compose exec app sh`.

O script de inicialização prepara esses volumes. `docker compose down` os preserva; `docker compose down -v` apaga também sessões, caches, dependências e banco. As mudanças no PHP continuam sendo detectadas, com verificação do OPcache a cada segundo. Alterações em `docker/php/local.ini` exigem reiniciar o serviço `app`.

Para repetir o diagnóstico de leitura de arquivos, inicialização do Laravel e consulta ao banco:

```powershell
docker compose exec -T app php scripts/diagnose-performance.php
curl.exe -s -o NUL -w "TTFB=%{time_starttransfer} total=%{time_total}\n" http://127.0.0.1:8000/login
```

Documentação: https://laravel.com/docs/13.x

A [revisão de segurança, CI e confiabilidade](docs/security-hardening-2026-10-12.md) registra o endurecimento de autenticação, CSP parcial, isolamento da verificação de backup, auditorias de dependências, análise estática e cobertura reproduzível.
