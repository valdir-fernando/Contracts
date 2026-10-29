@php
    $fields = $document['fields'];
    $budget = $document['budget'] ?? [];
    $text = fn ($value) => filled($value) ? $value : 'Não informado';
    $date = fn ($value) => filled($value) ? (new \DateTimeImmutable($value))->format('d/m/Y') : 'Não informada';
    $months = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $issued = filled($fields['Data_DFD']) ? new \DateTimeImmutable($fields['Data_DFD']) : null;
@endphp
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><title>DFD {{ $fields['N_DFD'] }}</title>
<style>
    @page { margin: 14mm 15mm 16mm; }
    body { margin: 0; font: 8pt DejaVu Sans, sans-serif; color: #111; line-height: 1.3; }
    table { border-collapse: collapse; width: 100%; table-layout: fixed; }
    td, th { border: .6pt solid #777; padding: 3pt 4pt; vertical-align: top; word-wrap: break-word; }
    th { background: #efefec; font-weight: bold; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    .institution td { vertical-align: middle; border-bottom: 0; }
    .institution .municipality { width: 34%; text-align: center; }
    .institution .state { width: 35%; text-align: center; font-size: 12pt; font-weight: bold; }
    .institution .crest { width: 15%; text-align: center; }
    .institution .number { text-align: center; font-weight: bold; }
    .logo { width: 40mm; height: auto; }
    .state-logo { width: 20mm; height: auto; }
    .fund-name { font-size: 7pt; font-weight: bold; margin-top: 4pt; }
    .administration { font-size: 6pt; margin-top: 2pt; font-weight: normal; }
    .number-value { font-size: 12pt; color: #a00000; }
    .title { width: 70%; text-align: center; font-size: 10pt; color: #0073b9; font-weight: bold; vertical-align: middle; }
    .metadata { text-align: center; font-size: 7pt; }
    h2 { font-size: 8pt; line-height: 1.3; padding: 3pt 4pt; margin: 0; background: #efefec; border: .6pt solid #777; border-top: 0; page-break-after: avoid; }
    .content { margin: 0; padding: 4pt; border: .6pt solid #777; border-top: 0; white-space: pre-wrap; word-wrap: break-word; }
    .category { margin: 0; padding: 4pt; border: .6pt solid #777; border-top: 0; font-size: 7pt; word-wrap: break-word; }
    .products { font-size: 7pt; }
    .products .item { width: 6%; text-align: center; }
    .products .code { width: 9%; text-align: center; }
    .products .unit { width: 12%; text-align: center; }
    .products .quantity { width: 12%; text-align: center; }
    .description { white-space: pre-wrap; word-wrap: break-word; }
    .products tbody td { font-size: 7pt; }
    .info-label { padding: 3pt 4pt; border: .6pt solid #777; border-top: 0; background: #f7f7f5; font-weight: bold; page-break-after: avoid; }
    .budget { font-size: 6pt; text-align: center; }
    .budget td, .budget th { padding: 3pt 1pt; }
    .signature { border: .6pt solid #777; border-top: 0; padding: 6pt 4pt; page-break-inside: avoid; }
    .signature-space { height: 22mm; }
    .footer { position: fixed; left: 0; right: 0; bottom: -10mm; font-size: 6pt; color: #555; }
    .page { float: right; }
    .page:after { content: counter(page); }
</style></head><body>
    <div class="footer">Sistema Gestão · DFD {{ $fields['N_DFD'] }} · Impressão: {{ $document['actor']['username'] }}<span class="page">Página </span></div>
    <table class="institution"><tr>
        <td class="municipality"><img class="logo" src="{{ $logo }}" alt="Prefeitura de Niquelândia"><div class="fund-name">{{ $document['fund']['name'] }}</div><div class="administration">Administração {{ $document['settings']['administration'] }}</div></td>
        <td class="state">ESTADO DE GOIÁS</td><td class="crest"><img class="state-logo" src="{{ $crestLogo }}" alt="Brasão de Niquelândia"></td>
        <td class="number">Nº<br><span class="number-value">{{ $fields['N_DFD'] }}</span></td>
    </tr></table>
    <table><tr><td class="title" rowspan="2">DOCUMENTO DE FORMALIZAÇÃO DE DEMANDA - DFD</td><th class="metadata">PROCESSO</th><th class="metadata">DATA</th></tr><tr><td class="metadata">{{ $text($fields['Processo_DFD']) }}</td><td class="metadata">{{ $date($fields['Data_DFD']) }}</td></tr></table>
    <p class="category"><strong>Classe / Categoria:</strong> {{ $text($fields['Categoria']) }}</p>
    <h2>1 - JUSTIFICATIVA DA NECESSIDADE DA CONTRATAÇÃO</h2>
    <div class="content">{{ $text($fields['Necessidade']) }}</div>
    <h2>2 - DESCRIÇÃO SUCINTA DO OBJETO</h2>
    <div class="content">{{ $text($fields['Desricao']) }}</div>
    <h2>3 - QUANTIDADE A SER CONTRATADA CONSIDERANDO A EXPECTATIVA DE CONSUMO</h2>
    <table class="products"><thead><tr><th class="item">ITEM</th><th class="code">CÓD.</th><th class="unit">UND.</th><th class="quantity">QTD.</th><th>DISCRIMINAÇÃO</th></tr></thead><tbody>
        @forelse($document['items'] as $item)
            @foreach($item['segments'] as $segment)
                <tr><td class="item">{{ $loop->first ? $item['ITEM'] : '' }}</td><td class="code">{{ $loop->first ? $item['COD_PROD'] : '' }}</td><td class="unit">{{ $loop->first ? $item['UND'] : '' }}</td><td class="quantity">{{ $loop->first ? $item['QTD'] : '' }}</td><td class="description">{{ $segment }}</td></tr>
            @endforeach
        @empty<tr><td colspan="5">Nenhum produto cadastrado nesta DFD.</td></tr>@endforelse
    </tbody></table>
    <h2>4 - INFORMAÇÕES GERAIS</h2>
    <table><tr><th>4.1 - Data prevista para conclusão do processo de contratação</th><th>4.2 - Grau de prioridade da compra / contratação</th></tr>
        <tr><td>{{ $date($fields['Data_Conclusao']) }}</td><td>{{ $text($fields['Prioridade']) }}</td></tr>
        <tr><th>4.3 - Prazo de vigência da contratação</th><td>{{ $text($fields['Vigencia']) }}</td></tr>
        <tr><th>4.4 - Local da entrega / serviço</th><td>{{ $text($fields['Local']) }}</td></tr>
        <tr><td colspan="2">{{ $text($fields['End_Local']) }}</td></tr>
    </table>
    <h2>5 - INDICAÇÃO DE VINCULAÇÃO OU DEPENDÊNCIA COM O OBJETO DE OUTRA CONTRATAÇÃO</h2><div class="content">{{ $text($fields['Vinculacao']) }}</div>
    <h2>6 - DOTAÇÃO ORÇAMENTÁRIA</h2>
    <table class="budget"><thead><tr>@foreach(['ÓRGÃO', 'GESTÃO', 'UNIDADE', 'FUNÇÃO', 'SUBFUNÇÃO', 'PROGRAMA', 'PROJETO', 'FONTE', 'CÓDIGO', 'FICHA'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody><tr>@foreach(['Orgao', 'Gestao', 'Unidade', 'Funcao', 'SubFunc', 'Programa', 'Projeto', 'Fonte', 'Codigo'] as $key)<td>{{ $budget[$key] ?? '—' }}</td>@endforeach<td>{{ $text($fields['Ficha']) }}</td></tr></tbody></table>
    @if(filled($budget['Dotacao'] ?? null))<div class="content">{{ $budget['Dotacao'] }}</div>@endif
    <h2>7 - OBSERVAÇÕES E REQUISITOS NECESSÁRIOS PARA A CONTRATAÇÃO</h2><div class="content">{{ $text($fields['Observacoes']) }}</div>
    <h2>8 - IDENTIFICAÇÃO DA ÁREA REQUISITANTE E RESPONSÁVEL</h2>
    <div class="info-label">8.1 - Área requisitante (unidade / setor / departamento)</div><div class="content">{{ $text($fields['Requisitante']) }}</div>
    <div class="info-label">8.2 - Responsável pela demanda</div><div class="content">{{ $text($fields['Responsavel']) }}</div>
    <div class="content"><strong>Decreto:</strong> {{ $text($fields['Decreto']) }} · <strong>Cargo / Função:</strong> {{ $text($fields['Cargo']) }}@if(filled($fields['email']))
 · <strong>E-mail:</strong> {{ $fields['email'] }}@endif</div>
    <div class="content"><strong>Local e data:</strong> Niquelândia-GO, {{ $issued ? $issued->format('d').' de '.$months[(int) $issued->format('n')].' de '.$issued->format('Y') : 'data não informada' }}.</div>
    <div class="signature"><strong>Assinatura</strong><div class="signature-space"></div></div>
</body></html>
