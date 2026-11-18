<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4; margin: 70.85pt 85.05pt; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; line-height: 1; color: #000; }
        p { margin: 0; text-align: justify; overflow-wrap: break-word; }
        .gap { height: 10pt; }
        .signature { page-break-inside: avoid; }
        .manager, .position { text-transform: uppercase; }
    </style>
</head>
<body>
    <p><strong>{{ $fields['fund'] }}</strong></p>
    <p><strong>ADM</strong>: {{ $fields['administration'] }}</p>
    <p><strong><u>EXTRATO DA ATA {{ $fields['number'] }}</u></strong></p>
    <p><strong>Processo/Licitação</strong>: {{ $fields['process'] ?: 'Não informado' }} - {{ $fields['bidding'] }} nº. {{ $fields['bidding_number'] ?: 'Não informado' }}</p>
    <p><strong>Fornecedor</strong>: {{ $fields['supplier_document'] }}<strong>- {{ $fields['supplier'] }}</strong></p>
    <div class="gap"></div>
    <p><strong><u>OBJETO</u></strong>:</p>
    <p>{!! nl2br(e($fields['object'])) !!}</p>
    <p><strong>VALOR TOTAL</strong>: {{ $fields['amount'] }} ({{ $fields['amount_words'] }})</p>
    <div class="gap"></div>
    <p><strong>DATA</strong>: {{ $fields['publication_date'] }}</p>
    <div class="gap"></div>
    <div class="signature">
        <p class="manager"><strong>{{ $fields['manager'] }}</strong></p>
        <p>CPF: {{ $fields['manager_document'] }}</p>
        <p class="position">{{ $fields['position'] }}</p>
    </div>
</body>
</html>
