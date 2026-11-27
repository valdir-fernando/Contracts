<?php

namespace App\Http\Controllers;

use App\Infrastructure\Atas\AtaAmendmentPdf;
use App\Infrastructure\Atas\AtaAmendmentWord;
use App\Infrastructure\Atas\AtaContractor;
use App\Infrastructure\Atas\AtaEditorQuery;
use App\Infrastructure\Atas\AtaExtractPdf;
use App\Infrastructure\Atas\AtaParticipants;
use App\Infrastructure\Atas\AtaProductLookup;
use App\Infrastructure\Atas\AtaPublicationPdf;
use App\Infrastructure\Atas\AtaRegistration;
use App\Infrastructure\Atas\AtaWithAdmissionPdf;
use App\Infrastructure\Atas\AtaWithAdmissionWord;
use App\Infrastructure\Atas\RemoveAta;
use App\Infrastructure\Atas\RemoveAtaAmendment;
use App\Infrastructure\Atas\RemoveAtaProduct;
use App\Infrastructure\Atas\RemoveAtaSpecialQuantity;
use App\Infrastructure\Atas\RemoveSavedAtaParticipant;
use App\Infrastructure\Atas\SaveAtaAmendment;
use App\Infrastructure\Atas\SaveAtaProduct;
use App\Infrastructure\Atas\SaveAtaValidity;
use App\Infrastructure\Atas\SavedAtaParticipants;
use App\Infrastructure\Atas\UpdateAta;
use App\Infrastructure\Atas\UpdateAtaAdmission;
use App\Infrastructure\Atas\UpdateAtaSpecialQuantity;
use App\Infrastructure\Security\LockActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

class AtaController extends Controller
{
    public function update(Request $request, int $ata, UpdateAta $editor)
    {
        return response()->json($editor->update($request->user(), $ata, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null), 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function destroy(Request $request, int $ata, RemoveAta $editor)
    {
        return response()->json($editor->remove($request->user(), $ata, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null), 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function storeAmendment(Request $request, int $ata, SaveAtaAmendment $editor)
    {
        return response()->json($editor->save($request->user(), $ata, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null), 201,
            ['Cache-Control' => 'private, no-store']);
    }

    public function updateAmendment(Request $request, int $ata, int $amendment, SaveAtaAmendment $editor)
    {
        return response()->json($editor->save($request->user(), $ata, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null, $amendment), 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function destroyAmendment(Request $request, int $ata, int $amendment, RemoveAtaAmendment $editor)
    {
        return response()->json($editor->remove($request->user(), $ata, $amendment, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null), 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function amendmentDocument(Request $request, int $ata, int $amendment, AtaAmendmentWord $document)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'products_layout' => 'sometimes|required|in:continuous,parallel',
            'format' => 'sometimes|required|in:pdf,docx', 'instrument' => 'sometimes|required|in:apostille,amendment']);
        $format = $data['format'] ?? 'docx';
        $apostille = ($data['instrument'] ?? null) === 'apostille';
        $renderer = $format === 'pdf' ? resolve(AtaAmendmentPdf::class) : $document;
        $issued = $renderer->generateForAmendment($request->user(), $ata, (int) $data['version'], $amendment, $data['products_layout'] ?? 'continuous', $apostille);
        $part = static fn (string $value, int $limit) => trim(mb_strcut(preg_replace('/[^\p{L}\p{M}\p{N}-]+/u', '_', $value), 0, $limit, 'UTF-8'), '_');
        $supplier = $part($issued['supplier'], 160) ?: 'Fornecedor';
        $number = $part(preg_replace('~\s*/\s*~', '-', $issued['number']), 40) ?: (string) $ata;
        $ordinal = str_pad((string) $amendment, 3, '0', STR_PAD_LEFT);
        $suffix = "_ATA_{$number}.{$format}";
        $prefix = ($apostille ? 'APOST' : 'ADT').'_'.$ordinal.'_';
        $filename = $prefix.$supplier.$suffix;
        $fallback = $prefix.($part(Str::ascii($supplier), 160) ?: 'Fornecedor').$suffix;

        return response($issued['bytes'], 200, ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $filename, $fallback)]);
    }

    public function saveValidity(Request $request, int $ata, SaveAtaValidity $validity)
    {
        return response()->json($validity->save($request->user(), $ata, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null), 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function publication(Request $request, int $ata, AtaPublicationPdf $publication)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $bytes = $publication->generate($request->user(), $ata, (int) $data['version']);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="publicacao-ata-'.$ata.'.pdf"']);
    }

    public function document(Request $request, int $ata, AtaWithAdmissionPdf $document)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'format' => 'sometimes|required|in:pdf,docx',
            'products_layout' => 'sometimes|required|in:continuous,parallel']);
        $word = ($data['format'] ?? 'pdf') === 'docx';
        $renderer = $word ? resolve(AtaWithAdmissionWord::class) : $document;
        $bytes = $renderer->generateWithLayout($request->user(), $ata, (int) $data['version'], $data['products_layout'] ?? 'continuous');

        return response($bytes, 200, ['Content-Type' => $word ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => ($word ? 'attachment' : 'inline').'; filename="ata-'.$ata.'.'.($word ? 'docx' : 'pdf').'"']);
    }

    public function extract(Request $request, int $ata, AtaExtractPdf $extract)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $bytes = $extract->generate($request->user(), $ata, (int) $data['version']);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="extrato-ata-'.$ata.'.pdf"']);
    }

    public function removeSpecialQuantity(Request $request, int $ata, RemoveAtaSpecialQuantity $quantity)
    {
        return response()->json($quantity->remove($request->user(), $ata, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null), 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function updateSpecialQuantity(Request $request, int $ata, UpdateAtaSpecialQuantity $quantity)
    {
        return response()->json($quantity->update($request->user(), $ata, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null), 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function updateAdmission(Request $request, int $ata, UpdateAtaAdmission $admission)
    {
        return response()->json($admission->update($request->user(), $ata, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null), 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function removeProduct(Request $request, int $ata, RemoveAtaProduct $products)
    {
        return response()->json($products->remove($request->user(), $ata, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null), 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function saveProduct(Request $request, int $ata, SaveAtaProduct $products)
    {
        return response()->json($products->save($request->user(), $ata, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null), 201,
            ['Cache-Control' => 'private, no-store']);
    }

    public function productList(Request $request, int $ata, AtaProductLookup $products)
    {
        return response()->json($products->listing($request->user(), $ata, $request->query()), 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function productLookup(Request $request, int $ata, AtaProductLookup $products)
    {
        return response()->json($products->find($request->user(), $ata, $request->query()), 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function removeSavedParticipant(Request $request, int $ata, int $participant, RemoveSavedAtaParticipant $participants)
    {
        $state = $participants->remove($request->user(), $ata, $participant, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null);

        return response()->json($state, 200, ['Cache-Control' => 'private, no-store']);
    }

    public function addSavedParticipant(Request $request, int $ata, SavedAtaParticipants $participants)
    {
        $state = $participants->add($request->user(), $ata, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null);

        return response()->json($state, 201, ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, AtaRegistration $registration)
    {
        $result = $registration->save($request->user(), $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null);

        return response()->json($result, 201, ['Cache-Control' => 'private, no-store']);
    }

    public function lookup(Request $request, AtaEditorQuery $query)
    {
        $data = $request->validate(['number' => 'required|string|max:32']);

        return response()->json($query->find($request->user(), $data['number']), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function contractorDefaults(Request $request, int $fund, AtaContractor $contractor)
    {
        return response()->json(['values' => $contractor->defaults($request->user(), $fund)], 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function create(Request $request, LockActor $actors, AtaParticipants $participants)
    {
        $actors->current($request->user());
        $funds = DB::table('contract_funds')->whereNull('deleted_at')->whereRaw("TRIM(name) <> ''")
            ->orderBy('name')->orderBy('id')->get(['id', 'name']);

        $data = $request->validate(['draft' => 'sometimes|required|string|max:2048']);
        $draftToken = $data['draft'] ?? $participants->token($request->user());

        return response()->view('atas.create', ['funds' => $funds, 'draftToken' => $draftToken,
            'draftState' => $participants->state($request->user(), $draftToken)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function removeParticipant(Request $request, AtaParticipants $participants, int $participant)
    {
        $data = $request->validate(['draft_token' => 'required|string|max:2048']);
        $state = $participants->remove($request->user(), $data['draft_token'], $participant, $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null);

        return response()->json($state, 200, ['Cache-Control' => 'private, no-store']);
    }

    public function addParticipant(Request $request, AtaParticipants $participants)
    {
        $data = $request->validate(['draft_token' => 'required|string|max:2048']);
        $state = $participants->add($request->user(), $data['draft_token'], $request->all(),
            $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null);

        return response()->json($state, 201, ['Cache-Control' => 'private, no-store']);
    }
}
