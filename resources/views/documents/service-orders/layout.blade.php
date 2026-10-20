@php
    $fields = $document['fields'];
    $details = $document['details'];
    $text = fn ($value) => filled($value) ? $value : 'Não informado';
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>@yield('title') — {{ $fields['Nº OS'] }}</title>
    <style>
        @page { margin: 12mm 19mm 16mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #25354a; font-size: 9pt; line-height: 1.35; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .institution { border-bottom: 3px solid #173d63; padding-bottom: 10px; }
        .institution td { vertical-align: middle; }
        .institution .logo-cell { width: 36%; text-align: right; }
        .institution .municipal-logo { width: 48mm; height: auto; }
        .institution-name { font-size: 12pt; font-weight: bold; color: #173d63; margin: 0; }
        .institution-detail { margin: 3px 0 0; font-size: 7.5pt; color: #607087; }
        .administration { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 1px; }
        .hero { margin: 12px 0 9px; }
        h1 { margin: 0 0 4px; color: #173d63; font-size: 15pt; line-height: 1.25; }
        .subtitle { color: #65748a; font-size: 8pt; margin: 0; }
        .label { display: block; color: #607087; text-transform: uppercase; font-size: 6.5pt; letter-spacing: .5px; margin-bottom: 3px; }
        .intro { margin: 8px 0 10px; text-align: justify; }
        h2 { color: #173d63; font-size: 9pt; margin: 5px 0 6px; padding-bottom: 4px; border-bottom: 1px solid #cad5e1; }
        .grid td { vertical-align: top; padding: 4px 8px; border: 1px solid #d9e1eb; word-wrap: break-word; }
        .grid .value { font-weight: bold; font-size: 8.5pt; }
        .object { padding: 9px 13px; background: #f3f6fa; border-left: 3px solid #557d9e; white-space: pre-wrap; word-wrap: break-word; text-align: justify; }
        .paragraph { margin: 6px 0; text-align: justify; }
        .schedule td { text-align: center; }
        .schedule .value { font-size: 10pt; color: #173d63; }
        .notes { white-space: pre-wrap; word-wrap: break-word; }
        .signatures { margin-top: 6px; page-break-inside: avoid; }
        .signatures td { padding: 0 12px; text-align: center; vertical-align: top; }
        .electronic-signature-space { height: 31mm; }
        .signature-line { border-top: 1px solid #728198; padding-top: 6px; font-size: 8pt; font-weight: bold; }
        .signature-role { font-size: 7pt; color: #607087; margin-top: 2px; }
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; border-top: 1px solid #cad5e1; padding-top: 5px; color: #6b788b; font-size: 6.5pt; }
        .footer .page:after { content: counter(page); }
    </style>
</head>
<body>
    <div class="footer">{{ $text($fields['Fundo']) }} · Contrato {{ $fields['Contrato'] }} · OS {{ $fields['Nº OS'] }} <span class="page"> · Página </span></div>
    <header class="institution">
        @if($document['preview'] ?? false)<p class="administration">Modelo ilustrativo · Dados fictícios</p>@endif
        <table><tr><td>
        <p class="institution-name">{{ $text($fields['Fundo']) }}</p>
        <p class="institution-detail">CNPJ: {{ $text($details['fund_document']) }} · Administração {{ $details['administration'] }}</p>
        @if(filled($details['fund_address']))<p class="institution-detail">{{ $details['fund_address'] }}</p>@endif
        </td><td class="logo-cell"><img class="municipal-logo" src="{{ $logo }}" alt="Prefeitura de Niquelândia"></td></tr></table>
    </header>
    <div class="hero"><h1>@yield('title') Nº {{ $fields['Nº OS'] }}</h1><p class="subtitle">@yield('category')</p></div>
    <p class="intro">@yield('authorization')</p>
    <h2>01 · Identificação da contratação</h2>
    <table class="grid">
        <tr><td><span class="label">Contrato</span><span class="value">{{ $text($fields['Contrato']) }}</span></td><td><span class="label">Processo</span><span class="value">{{ $text($details['process']) }}</span></td><td><span class="label">Data OS</span><span class="value">{{ $text($fields['Data OS']) }}</span></td></tr>
        <tr><td colspan="2"><span class="label">Modalidade e número da licitação</span>{{ $text($details['procurement']) }} · {{ $text($details['bid']) }}</td><td><span class="label">Vínculo</span>{{ $details['amendment'] ?: 'Contrato original' }}</td></tr>
        <tr><td colspan="2"><span class="label">Contratada</span><span class="value">{{ $text($fields['Fornecedor']) }}</span></td><td><span class="label">CPF/CNPJ</span>{{ $text($details['supplier_document']) }}</td></tr>
    </table>
    <h2>02 · Objeto da execução</h2>
    <div class="object">{{ $text($fields['Objeto']) }}</div>
    @if(filled($details['location']))<p class="paragraph"><strong>Local de execução:</strong> {{ $details['location'] }}</p>@endif
    <h2>03 · Prazos desta ordem</h2>
    <table class="grid schedule"><tr><td><span class="label">Início</span><span class="value">{{ $text($fields['Início']) }}</span></td><td><span class="label">Final</span><span class="value">{{ $text($fields['Final']) }}</span></td><td><span class="label">Período</span><span class="value">{{ $text($fields['Período']) }}</span></td></tr></table>
    <h2>04 · @yield('execution-title')</h2>
    <p class="paragraph">@yield('execution')</p>
    @if(filled($details['inspector']))<p class="paragraph"><strong>Fiscal do contrato:</strong> {{ $details['inspector'] }}</p>@endif
    @if(filled($fields['Observações']))<p class="paragraph notes"><strong>Observações:</strong> {{ $fields['Observações'] }}</p>@endif
    <p class="paragraph">As demais condições permanecem conforme o instrumento contratual e os aditivos formalizados.</p>
    <table class="signatures"><tr>
        <td><div class="electronic-signature-space"></div><div class="signature-line">{{ $details['manager'] ?: 'Responsável pelo contratante' }}</div><div class="signature-role">{{ $details['manager_role'] ?: 'Contratante' }}</div></td>
        <td><div class="electronic-signature-space"></div><div class="signature-line">{{ $details['supplier_signatory'] ?: $text($fields['Fornecedor']) }}</div><div class="signature-role">Ciência da contratada</div></td>
    </tr></table>
</body>
</html>
