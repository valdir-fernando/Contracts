# Modelos de decreto de fiscais

- `fiscal-individual.md`: designação de um fiscal.
- `fiscais-coletivo.md`: designação de dois ou mais fiscais no mesmo decreto, com anexo individualizando a atuação de cada um.

São minutas editáveis para revisão administrativa e jurídica, integradas ao botão Decreto do Fiscal_Contrato. Os marcadores não são diretivas Blade. A emissão não atribui numeração automaticamente nem altera cadastros.

## Emissão no sistema

Na ficha de um fiscal salvo, o botão emite diretamente o decreto. O servidor usa o número e a data cadastrados para reunir todos os fiscais ativos e não excluídos do mesmo decreto, inclusive de outros fundos. Um fiscal gera o modelo individual; dois ou mais geram o coletivo. Número é comparado sem espaços nas extremidades e sem distinguir maiúsculas/minúsculas; datas brasileiras e ISO são normalizadas. Decretos com número igual e data diferente permanecem separados. Número ausente ou data inválida impedem a emissão e precisam ser corrigidos no cadastro.

Sem uma ficha aberta, o botão abre `/inspectors/decree` para escolher apenas o fiscal de referência; o agrupamento e o modelo são igualmente automáticos. A relação apresenta somente nome do fiscal e Secretaria/Fundo. Não são solicitados matrícula, cargo ou escopo. Secretaria é usada para fiscais do Município quando vinculada; nos demais casos, usa-se o Fundo. Vínculo ambíguo ou indisponível em qualquer integrante impede emitir uma relação incompleta.

`Infrastructure/Documents/InspectorDecree` captura e assina a revisão dos dados/modelos para o administrador autenticado, válida por 30 minutos. Renderiza PDF A4 com Dompdf fora da transação e revalida ator, Prefeito, cadastros, configuração e modelos antes de auditar e entregar. `InspectorDecreeController` transporta a resposta privada, sem arquivo público ou URL de download persistente. Mudanças em qualquer cadastro capturado exigem reabrir a preparação. Falha de auditoria impede a entrega. A tela usa a caixa padrão para falhas de validação e geração; o PDF é baixado sem abandonar o formulário.

## Origem dos dados na emissão

- Emitente e cabeçalho: sempre o registro ativo e único de MUNICIPIO DE NIQUELÂNDIA em `contract_funds`, com CNPJ e endereço desse Município. Nunca substituir pelo fundo do fiscal, pelo fundo selecionado no login ou por dados enviados pelo navegador.
- Administração: configuração documental vigente de `DocumentSettings`.
- Assinatura: exclusivamente o Prefeito cadastrado no Município em `tb_Gestor`, identificado pelo cargo. Exigir um único Prefeito ativo e não excluído. Não utilizar `ManagerSignatory::forFund`, pois esse método permite substituir o Prefeito pelo gestor com `Assinatura` ativa. A assinatura delegada do Executivo não se aplica a estes decretos.
- Fundo e secretaria do fiscal aparecem somente na designação e no anexo, mesmo nos decretos de fiscais de outros fundos.
- Número e data: provenientes exclusivamente do cadastro do fiscal de referência. Modelo e integrantes são determinados pelo servidor; parâmetros manuais não alteram essa escolha.
- Nome do Prefeito e dos fiscais em maiúsculas. Datas em português por extenso. Não fixar o nome de um ocupante de cargo no modelo.
- Matrícula, cargo e escopo não integram esta versão dos modelos.
- CPF completo, RG, nascimento, telefone e endereço pessoal não integram estes modelos.
- O modelo coletivo relaciona automaticamente cada fiscal do mesmo decreto em uma linha do anexo.

## Base de elaboração e limites

Referência consultada em 18/10/2026: [Lei Federal nº 14.133/2021, arts. 7º e 117](https://www.planalto.gov.br/ccivil_03/_ato2019-2022/2021/lei/l14133.htm). A minuta destina-se a contratações regidas por essa lei; contratos sob regime anterior precisam de fundamentação própria. A competência específica da Lei Orgânica e a regulamentação municipal devem ser conferidas antes da publicação; não foi inventado número de artigo ou decreto municipal. Não há revogação genérica nem efeitos retroativos automáticos.

A emissão é exclusiva de administradores, assim como o cadastro de fiscais existente, e usa somente dados de entidades ativas resolvidas no servidor. Não altera a regra de assinatura dos contratos existentes. Os botões Gestor_Contrato e Gestor_Fundo continuam aguardando modelos próprios.

## Verificação desta entrega

Testes de integração em `tests/Feature/InspectorDecreeTest.php` cobrem os dois modelos, PDF real, origem municipal, Prefeito em vez do delegado, privacidade, escape de conteúdo, autorização, divergências, expiração, mudança durante renderização e falha de auditoria. Resultados dos comandos da integração constam em `docs/inspector-decrees-2026-10-18.md`.
