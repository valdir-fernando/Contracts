<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 24px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #222; }
        h1 { font-size: 13px; margin: 0 0 12px; text-align: left; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #ccc; padding: 5px; vertical-align: top; overflow-wrap: break-word; }
        th { background: #eee; }
        thead { display: table-header-group; }
        .amount { text-align: right; }
    </style>
</head>
<body>
    <h1>{!! $title !!}</h1>
    <p>Total de Contratos: {{ number_format(count($rows), 0, ',', '.') }}</p>
    <table>
        <thead><tr>
            <th style="width: 7%">Contrato</th><th style="width: 10%">Processo</th>
            <th style="width: 24%">Fornecedor / Licitação</th><th style="width: 33%">Objeto</th>
            <th style="width: 8%">Início</th><th style="width: 8%">Final</th><th style="width: 10%">VL_Atual</th>
        </tr></thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row['number'] }}</td><td>{{ $row['process'] }}</td>
                    <td>{{ $row['bidding'] }}<br><strong>{{ $row['supplier'] }}</strong>
                        @if($row['amendment'] !== '' && $row['amendment'] !== '0')
                            <br>Aditivo {{ $row['amendment'] }}@if($row['amendment_process'] !== '') · Processo {{ $row['amendment_process'] }}@endif
                        @endif
                        @if($row['fund'] !== null)
                            <br>Fundo: {{ $row['fund'] }}
                            @if($row['department'])<br>Secretaria: {{ $row['department'] }}@endif
                        @endif
                    </td>
                    <td>{{ $row['object'] }}
                        @foreach($row['service_orders'] as $serviceOrder)
                            <br><small>OS {{ $serviceOrder['number'] }} · {{ $serviceOrder['issued_on'] }} · Período da Obra/Serviço: {{ $serviceOrder['period'] }}</small>
                        @endforeach
                    </td><td>{{ $row['starts'] }}</td><td>{{ $row['ends'] }}</td>
                    <td class="amount">{{ $row['amount'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7">Nenhum contrato encontrado para os filtros selecionados.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
