Atas de Registro de Preços {{ $filters['report_year'] === 'all' ? 'de todos os anos' : 'do ano '.$filters['report_year'] }}@if($filters['report_current'] === '1') · Somente vigentes @endif
@foreach(['fund' => 'Fundo', 'number' => 'Ata', 'process' => 'Processo', 'supplier' => 'Fornecedor', 'bidding' => 'Licitação', 'bidding_number' => 'Nº Licit', 'object' => 'Objeto'] as $key => $caption)@if($filters['report_'.$key] !== '') · {{ $caption }}: {{ $filters['report_'.$key] }}@endif @endforeach
@if($filters['report_end_from']) · Final de {{ \Carbon\Carbon::parse($filters['report_end_from'])->format('d/m/Y') }}@endif
@if($filters['report_end_to']) · Final até {{ \Carbon\Carbon::parse($filters['report_end_to'])->format('d/m/Y') }}@endif
