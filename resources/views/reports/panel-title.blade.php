@php
                    $appliedFilters = [];
                    if ($reportCurrent) {
                        $appliedFilters[] = 'Somente contratos vigentes';
                    }
                    if ($reportDepartmentId !== null) {
                        $appliedFilters[] = 'Secretaria: '.$reportDepartments->firstWhere('id', $reportDepartmentId)->name;
                    }
                    foreach ([
                        'Processo contém' => $reportProcess,
                        'Licitação contém' => $supplierFilters['report_bidding'],
                        'Nº da licitação contém' => $supplierFilters['report_bidding_number'],
                        'Nome do fornecedor contém' => $supplierFilters['report_supplier'],
                        'Tipo de contrato' => $reportType,
                    ] as $label => $value) {
                        if ($value !== '') {
                            $appliedFilters[] = $label.': '.$value;
                        }
                    }
                    if ($supplierFilters['report_person'] !== '') {
                        $appliedFilters[] = 'Pessoa do fornecedor: '.($supplierFilters['report_person'] === 'PF' ? 'Pessoa física (PF)' : 'Pessoa jurídica (PJ)');
                    }
                    if ($reportEndFrom !== null) {
                        $appliedFilters[] = 'Final a partir de: '.\Carbon\Carbon::parse($reportEndFrom)->format('d/m/Y');
                    }
                    if ($reportEndTo !== null) {
                        $appliedFilters[] = 'Final até: '.\Carbon\Carbon::parse($reportEndTo)->format('d/m/Y');
                    }
                @endphp
                Relatório de {{ $appliedFilters === [] ? 'todos os contratos' : 'contratos' }} do {{ $reportFundName }} {{ $reportEndActive || $reportYear === null ? 'referente a todos os anos' : 'referente ao ano de '.$reportYear }}.@if($appliedFilters !== []) Filtros aplicados: {{ implode('; ', $appliedFilters) }}.@endif
