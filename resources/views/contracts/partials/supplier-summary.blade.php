{{ $contract->modalidade ?: 'Licitação' }} nº {{ $contract->n_licit }}<br>
<strong>{{ $contract->fornecedor_nome_snapshot }}</strong>
@php($amendmentNumber = trim((string) ($contract->latest_amendment_number ?? $contract->n_aditivo)))
@php($amendmentProcess = trim((string) ($contract->latest_amendment_number !== null ? $contract->latest_amendment_process : $contract->proc_aditivo)))
@if($amendmentNumber !== '' && $amendmentNumber !== '0')
    <br>Aditivo {{ $amendmentNumber }}@if($amendmentProcess !== '') · Processo {{ $amendmentProcess }}@endif
@endif
@if($showScope)
    @if(\App\Infrastructure\Managers\ManagerSignatory::isMunicipal($contractFundName ?? ''))
        <br>Secretaria: {{ $contract->secretaria_nome_snapshot ?: 'Não informada' }}
    @else
        <br>Fundo: {{ $contractFundName ?: 'Não informado' }}
    @endif
@endif
