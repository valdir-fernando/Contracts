<?php

namespace App\Http\Controllers;

use App\Infrastructure\Documents\ContractPublicationPdf;
use App\Infrastructure\Documents\SupplyContractExtract;
use App\Models\Contract;
use Illuminate\Http\Request;

class ContractExtractController extends Controller
{
    public function show(Request $request, Contract $contract, SupplyContractExtract $extract)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $bytes = $extract->generate($request->user(), $contract->id, (int) $data['version']);
        $name = SupplyContractExtract::modelFor($contract->tipo) === 'property-lease' ? 'locacao-imovel' : 'fornecimento';

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="extrato-'.$name.'-'.$contract->id.'.pdf"']);
    }

    public function publication(Request $request, Contract $contract, ContractPublicationPdf $publication)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $bytes = $publication->generate($request->user(), $contract->id, (int) $data['version']);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="publicacao-contrato-'.$contract->id.'.pdf"']);
    }
}
