<?php

namespace App\Http\Controllers;

use App\Infrastructure\Documents\SupplyContractPdf;
use App\Models\Contract;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ContractDocumentController extends Controller
{
    public function show(Request $request, Contract $contract, SupplyContractPdf $document)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        // The service resolves the saved type; HTTP input cannot choose a different model.
        try {
            $bytes = $document->generate($request->user(), $contract->id, (int) $data['version']);
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 422 || $request->expectsJson()) {
                throw $error;
            }

            return response()->view('errors.localized', ['code' => 422, 'title' => 'Revise os dados do contrato',
                'description' => $error->getMessage(), 'returnUrl' => route('contracts.edit', $contract),
                'returnLabel' => 'Voltar ao contrato'], 422)->header('Cache-Control', 'private, no-store')
                ->header('X-Content-Type-Options', 'nosniff');
        }

        $name = match (SupplyContractPdf::modelFor($contract->tipo)) {
            'property-lease' => 'locacao-imovel',
            'services' => 'servicos',
            default => 'fornecimento',
        };

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="contrato-'.$name.'-'.$contract->id.'.pdf"']);
    }
}
