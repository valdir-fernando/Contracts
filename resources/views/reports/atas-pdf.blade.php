<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8"><style>
    @page { margin: 24px; } body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #222; }
    h1 { font-size: 13px; margin: 0 0 12px; } table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td { border: 1px solid #ccc; padding: 5px; vertical-align: top; overflow-wrap: break-word; }
    th { background: #eee; } thead { display: table-header-group; } .amount { text-align: right; }
</style></head><body>
    <h1>@include('reports.ata-title')</h1>
    <p>Total de Atas: {{ number_format($count, 0, ',', '.') }} · Total registrado: {{ \App\Models\Contract::moneyLabel($total) }}</p>
    <table><thead><tr><th style="width:7%">Ata</th><th style="width:10%">Processo</th><th style="width:24%">Fornecedor / Licitação</th><th style="width:31%">Objeto</th><th style="width:8%">Início</th><th style="width:8%">Final</th><th style="width:12%">Valor Atualizado</th></tr></thead>
        <tbody>@forelse($rows as $row)<tr>
            <td>{{ $row['number'] }}</td><td>{{ $row['process'] }}</td><td>{{ $row['bidding'] }}<br><strong>{{ $row['supplier'] }}</strong>
                @if($row['amendment'] !== null)
                    <br>Aditivo {{ $row['amendment']['number'] }}@if($row['amendment']['process'] !== '') · Processo {{ $row['amendment']['process'] }}@endif
                @endif
            </td><td>{{ $row['object'] }}</td>
            <td>{{ $row['starts'] ? \Carbon\Carbon::parse($row['starts'])->format('d/m/Y') : 'Não informado' }}</td>
            <td>{{ $row['ends'] ? \Carbon\Carbon::parse($row['ends'])->format('d/m/Y') : 'Não informado' }}</td>
            <td class="amount">{{ \App\Models\Contract::moneyLabel($row['amount']) }}</td>
        </tr>@empty<tr><td colspan="7">Nenhuma ata encontrada para os filtros selecionados.</td></tr>@endforelse</tbody>
    </table>
</body></html>
