<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ata de Registro de Preços {{ $fields['number'] }}</title>
<style>
    @page { margin: 98pt 28.3pt 81pt 54pt; }
    body { margin: 0; font-family: Helvetica, sans-serif; font-size: 10pt; line-height: 11.5pt; }
    header { position: fixed; top: -76pt; left: 0; right: 0; text-align: right; }
    header img { position: absolute; width: 162.75pt; height: 51.95pt; top: 0; left: 0; }
    header .heading { margin-left: 167pt; min-height: 42pt; color: #404040; }
    header .fund { font-size: 14pt; font-weight: bold; line-height: 15pt; }
    header .administration { font-family: "Times-Roman", serif; font-size: 12pt; line-height: 14pt; }
    header .details { margin-left: 167pt; padding-right: 58pt; min-height: 13pt; font-size: 9pt; line-height: 10pt; }
    header .separator { border-top: 0.75pt solid #000; }
    footer { position: fixed; bottom: -72pt; left: 0; right: 0; text-align: left; }
    footer img { width: 100%; }
    h1 { margin: 0; font-size: 12pt; line-height: 14pt; font-weight: normal; }
    h2 { margin: 0; font-size: 10pt; line-height: 11.5pt; font-weight: normal; page-break-after: avoid; }
    p { margin: 0; text-align: justify; }
    .spaced { margin-top: 11.5pt; }
    .center { text-align: center; }
    .left { text-align: left; }
    .subclause { margin-left: 28.35pt; }
    .subclause-deep { margin-left: 56.7pt; }
    .supplier { border-top: 0.75pt solid #000; border-bottom: 0.75pt solid #000; padding: 1pt 0; }
    .products, .signatures { border-collapse: collapse; width: 100%; table-layout: fixed; }
    .products { margin-top: 11.5pt; font-size: 8pt; line-height: 9.5pt; }
    .products thead { display: table-header-group; }
    .products th, .products td { border: 0.5pt solid #000; padding: 2pt; overflow-wrap: break-word; }
    .products td.number { text-align: right; }
    .products.parallel-item { margin-top: 3pt; page-break-inside: avoid; }
    .products.parallel-item.parallel-first { margin-top: 11.5pt; }
    .products.parallel-item th { background: #e7e6e6; }
    .products.parallel-item td.description { text-align: left; }
    ul { margin: 0; }
    .closing-date { margin: 23pt 0; text-align: right; font-size: 11pt; }
    .signatures { page-break-inside: avoid; }
    .signatures td { width: 50%; vertical-align: top; }
    .signature-line { margin-top: 62pt; }
    .witnesses { margin-top: 11.5pt; }
</style>
</head>
<body>
<header>
    <img src="{{ $header }}" alt="">
    <div class="heading">
        <div class="fund">{{ $fields['fund'] }}</div>
        <div class="administration">{{ $fields['administration'] }}</div>
    </div>
    <div class="details" data-ata-pagination>ATA: {{ $fields['number'] }} - Processo: {{ $fields['process'] }}</div>
    <div class="separator"></div>
</header>
<footer><img src="{{ $footer }}" alt=""></footer>
<h1 class="center"><strong>ATA DE REGISTRO DE PREÇOS Nº {{ $fields['number'] }}</strong></h1>
<p class="spaced left"><strong>{{ $fields['bidding'] }}</strong> {{ $fields['bidding_number'] }}</p>
<p class="left"><strong>PROCESSO ADMINISTRATIVO N.</strong> {{ $fields['process'] }}</p>
<p class="spaced">O <strong>{{ $fields['fund'] }}</strong>, pessoa jurídica de direito público interno, inscrita no CNPJ nº. {{ $fields['fund_document'] }}, com endereço na {{ $fields['fund_address'] }}, neste ato representado pelo(a) {{ $fields['position'] }}, Senhor(a) <strong>{{ $fields['manager'] }}</strong>, nomeado(a) pelo Decreto Municipal nº {{ $fields['decree'] }}, Matrícula Funcional nº {{ $fields['manager_registration'] }}, portador(a) do CPF nº. {{ $fields['manager_document'] }}, residente e domiciliado nesta cidade, considerando o julgamento da licitação na modalidade de {{ $fields['bidding'] }}, para <strong>REGISTRO DE PREÇOS</strong> nº {{ $fields['number'] }}, Processo Administrativo n.º {{ $fields['process'] }}, <strong>RESOLVE</strong>, registrar os preços da empresa indicada e qualificada nesta ATA, de acordo com a classificação por ela alcançada e nas quantidades cotadas, atendendo as condições previstas no Edital de licitação, sujeitando-se as partes às normas constantes na Lei nº 14.133, de 1º de abril de 2021, no Decreto n.º 11.462, de 31 de março de 2023, Decreto Municipal 107 de 30 de março de 2023 e, em conformidade com as disposições a seguir:</p>
<h2 class="spaced"><strong>1. DO OBJETO</strong></h2>
<p class="spaced"><strong>1.1</strong> - A presente Ata tem por objeto o Registro de Preços para futura e eventual aquisição de {{ $fields['object'] }}, conforme as quantidades, especificações, obrigações e demais condições expressas no Termo de Referência, que é parte integrante desta Ata, assim como a proposta vencedora, independentemente de transcrição.</p>
<h2 class="spaced"><strong>2. PREÇOS, ESPECIFICAÇÕES E QUANTITATIVOS</strong></h2>
<p class="spaced"><strong>2.1</strong> - O preço registrado, as especificações do objeto, a quantidade e as demais condições ofertadas na proposta são as que seguem:</p>
@if($fields['supplier_document_label'] === 'CPF')
    <p class="supplier spaced">FORNECEDOR: <strong>{{ $fields['supplier'] }}</strong>, com endereço na {{ $fields['supplier_address'] }}, CPF nº {{ $fields['supplier_document'] }}, RG nº {{ $fields['representative_rg'] }}, e-mail: {{ $fields['supplier_email'] }}, telefone para contato nº {{ $fields['supplier_phone'] }}.</p>
@else
<p class="spaced supplier">FORNECEDOR: <strong>{{ $fields['supplier'] }}</strong>, com sede na {{ $fields['supplier_address'] }}, inscrita no CNPJ/MF sob o nº {{ $fields['supplier_document'] }}, e-mail: {{ $fields['supplier_email'] }}, telefone para contato nº {{ $fields['supplier_phone'] }}, neste ato representada por seu sócio proprietário, Sr(a). <strong>{{ $fields['representative'] }}</strong>, RG nº {{ $fields['representative_rg'] }}, CPF nº {{ $fields['representative_cpf'] }}.</p>
@endif
@if(($fields['presentation']['products_layout'] ?? 'continuous') === 'parallel')
    @forelse($fields['products'] as $product)
        <table class="products parallel-item{{ $loop->first ? ' parallel-first' : '' }}">
            <thead><tr>
                <th style="width: 7%">Item</th><th style="width: 10%">Código</th><th style="width: 10%">UND</th>
                @if($fields['special_quantity_name'] !== '')
                    <th style="width: 11%">{{ $fields['special_quantity_name'] }}</th>
                @endif
                <th style="width: 12%">QTD</th><th>Marca</th>
                <th style="width: 15%">VL. Unitário</th><th style="width: 16%">VL. Total</th>
            </tr></thead>
            <tbody>
                <tr class="item-values">
                    <td class="center">{{ $product['item'] }}</td><td class="center">{{ $product['code'] }}</td><td class="center">{{ $product['unit'] }}</td>
                    @if($fields['special_quantity_name'] !== '')
                        <td class="center">{{ $product['special_quantity'] }}</td>
                    @endif
                    <td class="center">{{ $product['quantity'] }}</td><td class="center">{{ $product['brand'] }}</td>
                    <td class="number">{{ $product['unit_price'] }}</td><td class="number">{{ $product['total'] }}</td>
                </tr>
                <tr><td class="description" colspan="{{ $fields['special_quantity_name'] !== '' ? 8 : 7 }}">{{ $product['description'] }}</td></tr>
            </tbody>
        </table>
    @empty
        <p class="spaced">Nenhum produto registrado.</p>
    @endforelse
@else
<table class="products">
    <thead>
        <tr>
            <th style="width: 6%">Item</th><th style="width: 8%">Código</th><th>Produto</th><th style="width: 6%">UND</th>
            @if($fields['special_quantity_name'] !== '')
                <th style="width: 11%">{{ $fields['special_quantity_name'] }}</th>
            @endif
            <th style="width: 10%">QTD</th><th style="width: 10%">Marca</th><th style="width: 13%">VL. Unitário</th><th style="width: 13%">VL. Total</th>
        </tr>
    </thead>
    <tbody>
        @forelse($fields['products'] as $product)
            <tr>
                <td class="center">{{ $product['item'] }}</td><td class="center">{{ $product['code'] }}</td><td>{{ $product['description'] }}</td><td class="center">{{ $product['unit'] }}</td>
                @if($fields['special_quantity_name'] !== '')
                    <td class="center">{{ $product['special_quantity'] }}</td>
                @endif
                <td class="center">{{ $product['quantity'] }}</td><td class="center">{{ $product['brand'] }}</td>
                <td class="number">{{ $product['unit_price'] }}</td><td class="number">{{ $product['total'] }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ $fields['special_quantity_name'] !== '' ? 9 : 8 }}">Nenhum produto registrado.</td></tr>
        @endforelse
    </tbody>
</table>
@endif
<p class="spaced"><strong>2.2</strong> - A listagem do cadastro de reserva referente ao presente registro de preços consta como anexo a esta Ata. (CASO TENHA INTERESSADO).</p>
<h2 class="spaced"><strong>3. ÓRGÃO(S) GERENCIADOR E PARTICIPANTE(S)</strong></h2>
<p class="spaced"><strong>3.1</strong> - O órgão gerenciador será o {{ $fields['fund'] }}.</p>
@if($fields['participants'])
    <p class="spaced"><strong>3.2</strong> - Além do gerenciador, participam deste registro de preços:</p>
    <ul>
        @foreach($fields['participants'] as $participant)
            <li>{{ $participant }}</li>
        @endforeach
    </ul>
@else
    <p class="spaced"><strong>3.2</strong> Além do gerenciador, não há participantes neste registro de preços</p>
@endif
<h2 class="spaced"><strong>4. ADESÃO À ATA DE REGISTRO DE PREÇOS</strong></h2>
<p class="spaced"><strong>4.1</strong> - Será admitida a adesão à ata de registro de preços decorrente desta licitação conforme Decreto Federal nº 11.462/23, art. 31.</p>
<p class="spaced"><strong>4.2</strong> - Durante a vigência da ata, os órgãos e as entidades da Administração pública municipal que não participaram do procedimento de IRP poderão aderir à ata de registro de preços na condição de não participantes, observados os seguintes requisitos:</p>
<p class="spaced subclause"><strong>4.2.1</strong> - Apresentação de justificativa da vantagem da adesão, inclusive em situações de provável desabastecimento ou descontinuidade de serviço público.</p>
<p class="spaced subclause"><strong>4.2.2</strong> - Demonstração de que os valores registrados estão compatíveis com os valores praticados pelo mercado na forma do art. 23 da Lei nº 14.133, de 2021; e</p>
<p class="spaced subclause"><strong>4.2.3</strong> - Consulta e aceitação prévias do órgão ou da entidade gerenciadora e do fornecedor.</p>
<p class="spaced"><strong>4.3</strong> - A autorização do órgão ou entidade gerenciadora apenas será realizada após a aceitação da adesão pelo fornecedor.</p>
<p class="spaced subclause"><strong>4.3.1</strong> - O órgão ou entidade gerenciadora poderá rejeitar adesões caso elas possam acarretar prejuízo à execução de seus próprios contratos ou à sua capacidade de gerenciamento.</p>
<p class="spaced"><strong>4.4</strong> - Após a autorização do órgão ou da entidade gerenciadora, o órgão ou entidade não participante deverá efetivar a aquisição ou a contratação solicitada em até noventa dias, observado o prazo de vigência da ata.</p>
<p class="spaced"><strong>4.5</strong> - O prazo de que trata o subitem anterior, relativo à efetivação da contratação, poderá ser prorrogado excepcionalmente, mediante solicitação do órgão ou da entidade não participante aceita pelo órgão ou pela entidade gerenciadora, desde que respeitado o limite temporal de vigência da ata de registro de preços.</p>
<p class="spaced"><strong>4.6</strong> - O órgão ou a entidade poderá aderir a item da ata de registro de preços da qual seja integrante, na qualidade de não participante, para aqueles itens para os quais não tenha quantitativo registrado.</p>
<p class="spaced"><u><strong>Dos limites para Adesões</strong></u></p>
<p class="spaced"><strong>4.7</strong> - As aquisições ou contratações adicionais não poderão exceder, por órgão ou entidade, a cinquenta por cento dos quantitativos dos itens do instrumento convocatório registrados na ata de registro de preços para o gerenciador e para os participantes.</p>
<p class="spaced"><strong>4.8</strong> - O quantitativo decorrente das adesões não poderá exceder, na totalidade, ao dobro do quantitativo de cada item registrado na ata de registro de preços para o gerenciador e os participantes, independentemente do número de órgãos ou entidades não participantes que aderirem à ata de registro de preços.</p>
<p class="spaced"><strong>4.9</strong> - A adesão à ata de registro de preços por órgãos e entidades da administração municipal poderá ser exigida para fins de transferências voluntárias, não ficando sujeita ao limite de que trata o item 4.7, desde que seja destinada à execução descentralizada de programa ou projeto federal e comprovada a compatibilidade dos preços registrados com os valores praticados no mercado na forma do art. 23 da Lei nº 14.133, de 2021.</p>
<p class="spaced"><u><strong>Vedação a acréscimos do quantitativo</strong></u></p>
<p class="spaced"><strong>4.10</strong> - É vedado efetuar acréscimos nos quantitativos fixados na ata de registro de preços.</p>
<h2 class="spaced"><strong>5. VALIDADE, FORMALIZAÇÃO DA ATA DE REGISTRO DE PREÇOS CADASTRO RESERVA E RENOVAÇÃO</strong> <strong>DO QUANTITATIVO</strong></h2>
<p class="spaced"><strong>5.1</strong> - A validade da Ata de Registro de Preços será de {{ $fields['period'] }}, podendo ser prorrogada por igual período, mediante a anuência do fornecedor, desde que comprovado o preço vantajoso.</p>
<p class="spaced subclause"><strong>5.1.1</strong> - Em caso de prorrogação da ata, poderá ser renovado o quantitativo originalmente registrado.</p>
<p class="spaced"><strong>5.2</strong> - A renovação dos quantitativos estará limitada ao quantitativo originalmente registrado, não sendo admitido acréscimo superior ao previsto inicialmente.</p>
<p class="spaced"><strong>5.3</strong> - A renovação dos quantitativos deverá respeitar o planejamento anual das contratações, conforme estabelecido no artigo 12, §1º, da Lei nº 14.133/2021.</p>
<p class="spaced"><strong>5.4</strong> - A renovação dos quantitativos não constitui direito subjetivo do fornecedor registrado e dependerá de decisão motivada da Administração.</p>
<p class="spaced"><strong>5.5</strong> - A eventual renovação dos quantitativos destinados ao órgão gerenciador e aos órgãos participantes não implicará renovação, recomposição ou reinício dos limites de adesão aplicáveis aos órgãos ou entidades não participantes, os quais permanecerão submetidos aos limites previstos no art. 86 da Lei nº 14.133/2021.</p>
<p class="spaced"><strong>5.6</strong> - Uma vez atingidos os limites legais destinados às adesões de órgãos ou entidades não participantes, a prorrogação da Ata, ainda que acompanhada da renovação dos quantitativos do órgão gerenciador e dos participantes, não autorizará nova margem quantitativa para adesões.</p>
<p class="spaced"><strong>5.7</strong> - A prorrogação e/ou renovação deverá ser formalizada por meio de termo aditivo, observando-se as disposições legais pertinentes.</p>
<p class="spaced"><strong>5.8</strong> - O contrato decorrente da ata de registro de preços terá sua vigência estabelecida no próprio instrumento contratual e observará no momento da contratação e a cada exercício financeiro a disponibilidade de créditos orçamentários, bem como a previsão no plano plurianual, quando ultrapassar 1 (um) exercício financeiro.</p>
<p class="spaced"><strong>5.9</strong> - Na formalização do contrato ou do instrumento substituto deverá haver a indicação da disponibilidade dos créditos orçamentários respectivos.</p>
<p class="spaced"><strong>5.10</strong> - A contratação com os fornecedores registrados na ata será formalizada pelo órgão ou pela entidade interessada por intermédio de instrumento contratual, emissão de nota de empenho de despesa, autorização de compra ou outro instrumento hábil, conforme o art. 95 da Lei nº 14.133, de 2021.</p>
<p class="spaced subclause"><strong>5.10.1</strong> - O instrumento contratual de que trata o item 5.8. deverá ser assinado no prazo de validade da ata de registro de preços.</p>
<p class="spaced"><strong>5.11</strong> - Os contratos decorrentes do sistema de registro de preços poderão ser alterados, observado o art. 124 da Lei nº 14.133, de 2021.</p>
<p class="spaced"><strong>5.12</strong> - Após a homologação da licitação, deverão ser observadas as seguintes condições para formalização da ata de registro de preços:</p>
<p class="spaced subclause"><strong>5.12.1</strong> - Serão registrados na ata os preços e os quantitativos do adjudicatário, devendo ser observada a possibilidade de o licitante oferecer ou não proposta em quantitativo inferior ao máximo previsto no edital e se obrigar nos limites dela.</p>
<p class="spaced subclause"><strong>5.12.2</strong> - Será incluído na ata, na forma de anexo, o registro dos licitantes ou dos fornecedores que:</p>
<p class="spaced subclause-deep"><strong>5.12.2.1</strong> - Aceitarem cotar os bens, as obras ou os serviços com preços iguais aos do adjudicatário, observada a classificação da licitação; e</p>
<p class="spaced subclause-deep"><strong>5.12.2.2</strong> - Mantiverem sua proposta original.</p>
<p class="spaced subclause"><strong>5.12.3</strong> -Será respeitada, nas contratações, a ordem de classificação dos licitantes ou dos fornecedores registrados na ata.</p>
<p class="spaced"><strong>5.13</strong> - O registro a que se refere o item 5.12.2 tem por objetivo a formação de cadastro de reserva para o caso de impossibilidade de atendimento pelo signatário da ata.</p>
<p class="spaced"><strong>5.14</strong> - Para fins da ordem de classificação, os licitantes ou fornecedores que aceitarem reduzir suas propostas para o preço do adjudicatário antecederão aqueles que mantiverem sua proposta original.</p>
<p class="spaced"><strong>5.15</strong> - A habilitação dos licitantes que comporão o cadastro de reserva somente será efetuada quando houver necessidade de contratação dos licitantes remanescentes, nas seguintes hipóteses:</p>
<p class="spaced subclause"><strong>5.15.1</strong> - Quando o licitante vencedor não assinar a ata de registro de preços, no prazo e nas condições estabelecidos no edital; e</p>
<p class="spaced subclause"><strong>5.15.2</strong> -Quando houver o cancelamento do registro do licitante ou do registro de preços nas hipóteses previstas no item 9.</p>
<p class="spaced"><strong>5.16</strong> - O preço registrado com indicação dos licitantes e fornecedores será divulgado no PNCP e ficará disponibilizado durante a vigência da ata de registro de preços.</p>
<p class="spaced"><strong>5.17</strong> - Após a homologação da licitação ou da contratação direta, o licitante mais bem classificado ou o fornecedor, no caso da contratação direta, será convocado para assinar a ata de registro de preços, no prazo e nas condições estabelecidos no edital de licitação ou no aviso de contratação direta, sob pena de decair o direito, sem prejuízo das sanções previstas na Lei nº 14.133, de 2021.</p>
<p class="spaced subclause"><strong>5.17.1</strong> - O prazo de convocação poderá ser prorrogado 1 (uma) vez, por igual período, mediante solicitação do licitante ou fornecedor convocado, desde que apresentada dentro do prazo, devidamente justificada, e que a justificativa seja aceita pela Administração.</p>
<p class="spaced"><strong>5.18</strong> - Quando o convocado não assinar a ata de registro de preços no prazo e nas condições estabelecidos no edital, e observado o disposto no item no item 5.12 e subitens, fica facultado à Administração convocar os licitantes remanescentes do cadastro de reserva, na ordem de classificação, para fazê-lo em igual prazo e nas condições propostas pelo primeiro classificado.</p>
<p class="spaced"><strong>5.19</strong> - Na hipótese de nenhum dos licitantes que trata o item 5.12.2.1, aceitar a contratação nos termos do item anterior, a Administração, observados o valor estimado e sua eventual atualização nos termos do edital, poderá:</p>
<p class="spaced subclause"><strong>5.19.1</strong> - Convocar para negociação os demais licitantes ou fornecedores remanescentes cujos preços foram registrados sem redução, observada a ordem de classificação, com vistas à obtenção de preço melhor, mesmo que acima do preço do adjudicatário; ou</p>
<p class="spaced subclause"><strong>5.19.2</strong> - Adjudicar e firmar o contrato nas condições ofertadas pelos licitantes ou fornecedores remanescentes, atendida a ordem classificatória, quando frustrada a negociação de melhor condição.</p>
<p class="spaced"><strong>5.20</strong> - A existência de preços registrados implicará compromisso de fornecimento nas condições estabelecidas, mas não obrigará a Administração a contratar, facultada a realização de licitação específica para a aquisição pretendida, desde que devidamente justificada.</p>
<h2 class="spaced"><strong>6. ALTERAÇÃO OU ATUALIZAÇÃO DOS PREÇOS REGISTRADOS</strong></h2>
<p class="spaced"><strong>6.1</strong> - Os preços registrados serão fixos e irreajustáveis durante a vigência da Ata de Registro de Preços.</p>
<p class="spaced"><strong>6.2</strong> - Os preços registrados poderão ser alterados ou atualizados em decorrência de eventual redução dos preços praticados no mercado ou de fato que eleve o custo dos bens, das obras ou dos serviços registrados, nas seguintes situações:</p>
<p class="spaced subclause"><strong>6.2.1</strong> - Em caso de força maior, caso fortuito ou fato do príncipe ou em decorrência de fatos imprevisíveis ou previsíveis de consequências incalculáveis, que inviabilizem a execução da ata tal como pactuada, nos termos da alínea “d” do inciso II do caput do art. 124 da Lei nº 14.133, de 2021.</p>
<p class="spaced subclause"><strong>6.2.2</strong> - Em caso de criação, alteração ou extinção de quaisquer tributos ou encargos legais ou a superveniência de disposições legais, com comprovada repercussão sobre os preços registrados.</p>
<p class="spaced subclause"><strong>6.2.3</strong> - O valor registrado poderá ser reajustado no caso de prorrogação da ata de registro respeitada a contagem da anualidade e a variação do IPCA/IBGE acumulado no período de 12 meses a contar da data do orçamento estimado.</p>
<h2 class="spaced"><strong>7. NEGOCIAÇÃO DE PREÇOS REGISTRADOS</strong></h2>
<p class="spaced"><strong>7.1</strong> - Na hipótese de o preço registrado tornar-se superior ao preço praticado no mercado por motivo superveniente, o órgão ou entidade gerenciadora convocará o fornecedor para negociar a redução do preço registrado.</p>
<p class="spaced subclause"><strong>7.1.1</strong> - Caso não aceite reduzir seu preço aos valores praticados pelo mercado, o fornecedor será liberado do compromisso assumido quanto ao item registrado, sem aplicação de penalidades administrativas.</p>
<p class="spaced subclause"><strong>7.1.2</strong> - Na hipótese prevista no item anterior, o gerenciador convocará os fornecedores do cadastro de reserva, na ordem de classificação, para verificar se aceitam reduzir seus preços aos valores de mercado e não convocará os licitantes ou fornecedores que tiveram seu registro cancelado.</p>
<p class="spaced subclause"><strong>7.1.3</strong> - Se não obtiver êxito nas negociações, o órgão ou entidade gerenciadora procederá ao cancelamento da ata de registro de preços, adotando as medidas cabíveis para obtenção de contratação mais vantajosa.</p>
<p class="spaced subclause"><strong>7.1.4</strong> - Na hipótese de redução do preço registrado, o gerenciador comunicará aos órgãos e às entidades que tiverem firmado contratos decorrentes da ata de registro de preços para que avaliem a conveniência e a oportunidade de diligenciarem negociação com vistas à alteração contratual, observado o disposto no art. 124 da Lei nº 14.133, de 2021.</p>
<p class="spaced"><strong>7.2</strong> - Na hipótese de o preço de mercado tornar-se superior ao preço registrado e o fornecedor não poder cumprir as obrigações estabelecidas na ata, será facultado ao fornecedor requerer ao gerenciador a alteração do preço registrado, mediante comprovação de fato superveniente que supostamente o impossibilite de cumprir o compromisso.</p>
<p class="spaced subclause"><strong>7.2.1</strong> - Documentação comprobatória ou planilha de custos que demonstre a inviabilidade do preço registrado em relação às condições inicialmente pactuadas.</p>
<p class="spaced subclause"><strong>7.2.2</strong> - Não hipótese de não comprovação da existência de fato superveniente que inviabilize o preço registrado, o pedido será indeferido pelo órgão ou entidade gerenciadora e o fornecedor deverá cumprir as obrigações estabelecidas na ata, sob pena de cancelamento do seu registro, nos termos do item 9.1, sem prejuízo das sanções previstas na Lei nº 14.133, de 2021, e na legislação aplicável.</p>
<p class="spaced subclause"><strong>7.2.3</strong> - Na hipótese de cancelamento do registro do fornecedor, nos termos do item anterior, o gerenciador convocará os fornecedores do cadastro de reserva, na ordem de classificação, para verificar se aceitam manter seus preços registrados, observado o disposto no item 5.7.</p>
<p class="spaced subclause"><strong>7.2.4</strong> - Se não obtiver êxito nas negociações, o órgão ou entidade gerenciadora procederá ao cancelamento da ata de registro de preços, nos termos do item 9.4, e adotará as medidas cabíveis para a obtenção da contratação mais vantajosa.</p>
<p class="spaced subclause"><strong>7.2.5</strong> - Na hipótese de comprovação da majoração do preço de mercado que inviabilize o preço registrado, conforme previsto no item 7.2 e no item 7.2.1, o órgão ou entidade gerenciadora atualizará o preço registrado, de acordo com a realidade dos valores praticados pelo mercado.</p>
<p class="spaced subclause"><strong>7.2.6</strong> - O órgão ou entidade gerenciadora comunicará aos órgãos e às entidades que tiverem firmado contratos decorrentes da ata de registro de preços sobre a efetiva alteração do preço registrado, para que avaliem a necessidade de alteração contratual, observado o disposto no art. 124 da Lei nº 14.133, de 2021.</p>
<h2 class="spaced"><strong>8. REMANEJAMENTO DAS QUANTIDADES REGISTRADAS NA ATA DE REGISTRO DE PREÇOS</strong></h2>
<p class="spaced"><strong>8.1</strong> - As quantidades previstas para os itens com preços registrados nas atas de registro de preços poderão ser remanejadas pelo órgão ou entidade gerenciadora entre os órgãos ou as entidades participantes e não participantes do registro de preços.</p>
<p class="spaced"><strong>8.2</strong> - O remanejamento somente poderá ser feito:</p>
<p class="spaced subclause"><strong>8.2.1</strong> - De órgão ou entidade participante para órgão ou entidade participante; ou</p>
<p class="spaced subclause"><strong>8.2.2</strong> - De órgão ou entidade participante para órgão ou entidade não participante.</p>
<p class="spaced"><strong>8.3</strong> - O órgão ou entidade gerenciadora que tiver estimado as quantidades que pretende contratar será considerado participante para efeito do remanejamento.</p>
<p class="spaced"><strong>8.4</strong> - Na hipótese de remanejamento de órgão ou entidade participante para órgão ou entidade não participante, serão observados os limites previstos no art. 32 do Decreto nº 11.462, de 2023.</p>
<p class="spaced"><strong>8.5</strong> - Competirá ao órgão ou à entidade gerenciadora autorizar o remanejamento solicitado, com a redução do quantitativo inicialmente informado pelo órgão ou pela entidade participante, desde que haja prévia anuência do órgão ou da entidade que sofrer redução dos quantitativos informados.</p>
<p class="spaced"><strong>8.6</strong> - Caso o remanejamento seja feito entre órgãos ou entidades dos Estados, do Distrito Federal ou de Municípios distintos, caberá ao fornecedor beneficiário da ata de registro de preços, observadas as condições nela estabelecidas, optar pela aceitação ou não do fornecimento decorrente do remanejamento dos itens.</p>
<p class="spaced"><strong>8.7</strong> - Na hipótese da compra centralizada, não havendo indicação pelo órgão ou pela entidade gerenciadora, dos quantitativos dos participantes da compra centralizada, nos termos do item 8.3, a distribuição das quantidades para a execução descentralizada será por meio do remanejamento.</p>
<h2 class="spaced"><strong>9. CANCELAMENTO DO REGISTRO DO LICITANTE VENCEDOR E DOS PREÇOS REGISTRADOS</strong></h2>
<p class="spaced subclause"><strong>9.1</strong> - O registro do fornecedor será cancelado pelo gerenciador, quando o fornecedor:</p>
<p class="spaced subclause"><strong>9.1.1</strong> - Descumprir as condições da ata de registro de preços, sem motivo justificado.</p>
<p class="spaced subclause"><strong>9.1.2</strong> - Não retirar a nota de empenho, ou instrumento equivalente, no prazo estabelecido pela Administração sem justificativa razoável.</p>
<p class="spaced subclause"><strong>9.1.3</strong> - Não aceitar manter seu preço registrado, na hipótese prevista no artigo 27, § 2º, do Decreto nº 11.462, de 2023; ou</p>
<p class="spaced subclause"><strong>9.1.4</strong> - Sofrer sanção prevista nos incisos III ou IV do caput do art. 156 da Lei nº 14.133, de 2021.</p>
<p class="spaced subclause-deep"><strong>9.1.4.1</strong> - Na hipótese de aplicação de sanção prevista nos incisos III ou IV do caput do art. 156 da Lei nº 14.133, de 2021, caso a penalidade aplicada ao fornecedor não ultrapasse o prazo de vigência da ata de registro de preços, poderá o órgão ou a entidade gerenciadora poderá, mediante decisão fundamentada, decidir pela manutenção do registro de preços, vedadas contratações derivadas da ata enquanto perdurarem os efeitos da sanção.</p>
<p class="spaced"><strong>9.2</strong> - O cancelamento de registros nas hipóteses previstas no item 9.1 será formalizado por despacho do órgão ou da entidade gerenciadora, garantidos os princípios do contraditório e da ampla defesa.</p>
<p class="spaced"><strong>9.3</strong> - Na hipótese de cancelamento do registro do fornecedor, o órgão ou a entidade gerenciadora poderá convocar os licitantes que compõem o cadastro de reserva, observada a ordem de classificação.</p>
<p class="spaced"><strong>9.4</strong> - O cancelamento dos preços registrados poderá ser realizado pelo gerenciador, em determinada ata de registro de preços, total ou parcialmente, nas seguintes hipóteses, desde que devidamente comprovadas e justificadas:</p>
<p class="spaced subclause"><strong>9.4.1</strong> - Por razão de interesse público.</p>
<p class="spaced subclause"><strong>9.4.2</strong> - A pedido do fornecedor, decorrente de caso fortuito ou força maior; ou</p>
<p class="spaced subclause"><strong>9.4.3</strong> - Se não houver êxito nas negociações, nas hipóteses em que o preço de mercado se tornar superior ou inferior ao preço registrado, nos termos dos artigos 26, § 3º e 27, § 4º, ambos do Decreto nº 11.462, de 2023.</p>
<h2 class="spaced"><strong>10. DAS PENALIDADES</strong></h2>
<p class="spaced"><strong>10.1</strong> - O descumprimento da Ata de Registro de Preços ensejará aplicação das penalidades estabelecidas no edital.</p>
<p class="spaced subclause"><strong>10.1.1</strong> - As sanções também se aplicam aos integrantes do cadastro de reserva no registro de preços que, convocados, não honrarem o compromisso assumido injustificadamente após terem assinado a ata.</p>
<p class="spaced"><strong>10.2</strong> - É da competência do gerenciador a aplicação das penalidades decorrentes do descumprimento do pactuado nesta ata de registro de preço (art. 7º, inc. XIV, do Decreto nº 11.462, de 2023), exceto nas hipóteses em que o descumprimento disser respeito às contratações dos órgãos ou entidade participante, caso no qual caberá ao respectivo órgão participante a aplicação da penalidade (art. 8º, inc. IX, do Decreto nº 11.462, de 2023).</p>
<p class="spaced"><strong>10.3</strong> - O órgão ou entidade participante deverá comunicar ao órgão gerenciador qualquer das ocorrências previstas no item 9.1, dada a necessidade de instauração de procedimento para cancelamento do registro do fornecedor.</p>
<h2 class="spaced"><strong>11. CONDIÇÕES GERAIS</strong></h2>
<p class="spaced"><strong>11.1</strong> - As condições gerais de execução do objeto, tais como os prazos para entrega e recebimento, as obrigações da Administração e do fornecedor registrado, penalidades e demais condições do ajuste, encontram-se definidos no Termo de Referência, ANEXO AO EDITAL.</p>
<p class="spaced"><strong>11.2</strong> - No caso de adjudicação por preço global de grupo de itens, só será admitida a contratação de parte de itens do grupo se houver prévia pesquisa de mercado e demonstração de sua vantagem para o órgão ou a entidade.</p>
<p class="spaced"><strong>11.3</strong> - A presente Ata se vincula ao Termo de Referência do Gerenciador e à proposta da Detentora.</p>
<p class="spaced">Para firmeza e validade do pactuado, a presente Ata foi lavrada em (02) duas vias de igual teor, que, depois de lida e achada em ordem, vai assinada pelas partes e encaminhada cópia aos demais órgãos participantes, se houver.</p>
<p class="closing-date">Niquelândia – Goiás, {{ $fields['date'] }}.</p>
<table class="signatures"><tbody><tr><td>
<p class="center"><strong>ÓRGÃO GERENCIADOR</strong></p>
<p class="center signature-line">___________________________________</p>
<p class="center"><strong>{{ $fields['fund'] }}</strong></p>
<p class="center"><strong>{{ $fields['manager'] }}</strong></p>
<p class="center"><strong>CPF:</strong> {{ $fields['manager_document'] }}</p>
</td><td>
<p class="center"><strong>DETENTOR(A)</strong></p>
<p class="center signature-line">___________________________________</p>
<p class="center"><strong>{{ $fields['supplier'] }}</strong></p>
<p class="center">{{ $fields['supplier_document_label'] }}: {{ $fields['supplier_document'] }}</p>
@if($fields['supplier_document_label'] === 'CNPJ')
<p class="center"><strong>{{ $fields['representative'] }}</strong></p>
<p class="center"><strong>CPF:</strong> {{ $fields['representative_cpf'] }}</p>
@endif
</td></tr></tbody></table>
<p class="witnesses"><strong>TESTEMUHAS</strong>:</p>
<p class="spaced">1. ____________________________________________________</p>
<p>CPF. ___________________________________________</p>
<p class="spaced">2. ____________________________________________________</p>
<p>CPF. ___________________________________________</p>
</body>
</html>
