<?php

namespace App\Http\Controllers;

use App\Infrastructure\Atas\AtaAmendmentSignedDocuments;
use App\Infrastructure\Atas\AtaSignedDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\HeaderUtils;

final class AtaSignedDocumentController
{
    private const FILE_RULES = 'required|file|mimes:pdf|extensions:pdf|max:12288';

    public function __construct(private AtaAmendmentSignedDocuments $amendments) {}

    public function preview(Request $request, int $ata, AtaSignedDocuments $documents, ?int $amendment = null)
    {
        $mode = $request->validate(['mode' => 'sometimes|in:pair,stored'])['mode'] ?? 'pair';
        $input = $request->validate(['version' => 'required|integer|min:1',
            'pdf' => $mode === 'stored' ? 'prohibited' : self::FILE_RULES]);

        $upload = $mode === 'stored' ? null : $input['pdf']->get();
        $result = $amendment === null ? $documents->preview($request->user(), $ata, (int) $input['version'], $upload)
            : $this->amendments->preview($request->user(), $ata, $amendment, (int) $input['version'], $upload);

        return response()->json($result,
            200, ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, int $ata, AtaSignedDocuments $documents, ?int $amendment = null)
    {
        $mode = $request->validate(['mode' => 'sometimes|in:pair,original,stored'])['mode'] ?? 'pair';
        if ($mode === 'original') {
            $input = $request->validate(['version' => 'required|integer|min:1', 'pdf' => self::FILE_RULES]);

            $result = $amendment === null ? $documents->storeOriginal($request->user(), $ata, (int) $input['version'], $input['pdf']->get())
                : $this->amendments->storeOriginal($request->user(), $ata, $amendment, (int) $input['version'], $input['pdf']->get());

            return response()->json($result, 201, ['Cache-Control' => 'private, no-store']);
        }
        $input = $request->validate([
            'version' => 'required|integer|min:1',
            'pdf' => $mode === 'stored' ? 'prohibited' : self::FILE_RULES,
            'sha256' => 'required|regex:/^[a-f0-9]{64}$/D',
            'reviewed' => 'accepted',
            'pages' => 'required|integer|between:1,24',
            'rectangles' => 'sometimes|array|list|max:120',
            'rectangles.*' => 'required|array:page,left,top,right,bottom',
            'rectangles.*.page' => 'required|integer|between:1,24',
            'rectangles.*.left' => 'required|numeric|between:0,1',
            'rectangles.*.top' => 'required|numeric|between:0,1',
            'rectangles.*.right' => 'required|numeric|between:0,1',
            'rectangles.*.bottom' => 'required|numeric|between:0,1',
        ]);

        $upload = $mode === 'stored' ? null : $input['pdf']->get();
        $result = $amendment === null ? $documents->store($request->user(), $ata, $input, $upload)
            : $this->amendments->store($request->user(), $ata, $amendment, $input, $upload);

        return response()->json($result, 201, ['Cache-Control' => 'private, no-store']);
    }

    public function destroy(Request $request, int $ata, AtaSignedDocuments $documents, ?int $amendment = null)
    {
        $input = $request->validate(['version' => 'required|integer|min:1', 'copy' => ['sometimes', Rule::in(AtaSignedDocuments::COPIES)]]);

        $result = $amendment === null ? $documents->remove($request->user(), $ata, (int) $input['version'], $input['copy'] ?? null)
            : $this->amendments->remove($request->user(), $ata, $amendment, (int) $input['version'], $input['copy'] ?? null);

        return response()->json($result, 200, ['Cache-Control' => 'private, no-store']);
    }

    public function download(Request $request, int $ata, string $copy, AtaSignedDocuments $documents)
    {
        $original = $copy === 'original';

        return $this->downloadResponse($documents->download($request->user(), $ata, $original), $ata, $original);
    }

    public function downloadAmendment(Request $request, int $ata, int $amendment, string $copy)
    {
        $original = $copy === 'original';

        return $this->downloadResponse($this->amendments->download($request->user(), $ata, $amendment, $original), $ata, $original);
    }

    private function downloadResponse(array $document, int $ata, bool $original)
    {
        $number = $this->filenamePart($document['number'], 40) ?: (string) $ata;
        $supplier = $this->filenamePart($document['supplier'], 160) ?: 'Fornecedor';
        $suffix = $original ? '_Assinado.pdf' : '_Assinado_Tarjado.pdf';
        $instrument = ($document['apostille'] ?? false) ? 'Apostilamento' : 'Aditivo';
        $name = isset($document['amendment_number'])
            ? "{$instrument}_{$document['amendment_number']}_ATA_{$number}_{$supplier}"
            : "Ata_{$number}_{$supplier}";
        $fallback = $this->filenamePart(Str::ascii($name), 205);

        return response($document['bytes'], 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $name.$suffix, $fallback.$suffix)]);
    }

    private function filenamePart(?string $value, int $limit): string
    {
        $safe = preg_replace('/[^\p{L}\p{M}\p{N}-]+/u', '_', $value ?? '') ?? '';

        return trim(mb_strcut($safe, 0, $limit, 'UTF-8'), '_-');
    }
}
