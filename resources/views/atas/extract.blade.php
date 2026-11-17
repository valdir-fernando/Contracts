<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4; margin: 100pt 45pt 80pt; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; line-height: 1.3; color: #000; }
        header { position: fixed; top: -80pt; left: 0; right: 0; height: 65pt; }
        .logo { width: 160pt; float: left; }
        .organization { margin-left: 175pt; text-align: center; font-weight: bold; color: #082b87; }
        .administration { margin-top: 4pt; font-size: 10pt; }
        footer { position: fixed; bottom: -60pt; left: 0; right: 0; height: 72pt; }
        footer img { display: block; width: 100%; height: 72pt; }
        .footer-administration { position: absolute; display: block; left: 318pt; top: 44pt; width: 91pt; height: 15pt; background: #fff; color: #082b87; font-size: 8pt; line-height: 12pt; text-align: center; }
        h1 { font-size: 11pt; text-align: center; margin: 10pt 0 18pt; }
        p { margin: 0 0 12pt; text-align: justify; }
        .bidding, .process { font-weight: bold; text-align: center; margin-bottom: 4pt; }
        .object { margin-top: 16pt; }
        .date { margin-top: 18pt; text-align: right; }
        .signature { margin-top: 30pt; text-align: center; }
        .signature p { text-align: center; margin: 0; }
    </style>
</head>
<body>
    <header>
        <img class="logo" src="{{ $header }}" alt="Prefeitura de Niquelândia">
        <div class="organization">{{ $fields['fund'] }}<div class="administration">ADM: {{ $fields['administration'] }}</div></div>
    </header>
    <footer><img src="{{ $footer }}" alt="Rodapé institucional"><span class="footer-administration">Gestão {{ str_replace('/', '–', $fields['administration']) }}</span></footer>
    <h1>EXTRATO DA ATA DE REGISTRO DE PREÇOS N.º {{ $fields['number'] }}</h1>
    <p class="bidding">{{ $fields['bidding'] }}: {{ $fields['bidding_number'] }}</p>
    <p class="process">PROCESSO: {{ $fields['process'] }}</p>
    <p class="object">{{ $fields['object'] }}</p>
    <p><strong>CONTRATANTE:</strong> {{ $fields['fund'] }}</p>
    <p><strong>CONTRATADA:</strong> {{ $fields['supplier'] }} - {{ $fields['supplier_document_label'] }}: {{ $fields['supplier_document'] }}</p>
    <p><strong>Período:</strong> {{ $fields['period'] }}</p>
    <p class="date">Niquelândia-GO, {{ $fields['date'] }}.</p>
    <div class="signature">
        <p><strong>{{ $fields['manager'] }}</strong></p>
        <p>CPF nº. {{ $fields['manager_document'] }}</p>
        <p>{{ $fields['decree'] }}</p>
        <p>{{ $fields['position'] }}</p>
    </div>
</body>
</html>
