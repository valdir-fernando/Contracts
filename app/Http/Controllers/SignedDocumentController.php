<?php

namespace App\Http\Controllers;

use App\Application\Security\ActorIdentity;
use App\Infrastructure\Documents\SignedDocuments;
use App\Infrastructure\Http\ApplicationErrors;
use App\Infrastructure\Security\LockActor;
use App\Models\Contract;
use App\Models\ContractAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SignedDocumentController extends Controller
{
    public function index(Request $request, LockActor $actors)
    {
        $actor = $actors->current($request->user());
        $fundId = (int) ($request->attributes->get('selected_fund')['id'] ?? 0);
        abort_unless($fundId, 403, 'Selecione um Fundo ao entrar no sistema.');
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'contract' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1', 'documents_page' => 'nullable|integer|min:1']);
        $query = Contract::visibleTo($actor)->where('fund_id', $fundId);
        $selected = empty($filters['contract']) ? null : (clone $query)->whereKey($filters['contract'])
            ->firstOrFail(['id', 'numero', 'processo', 'exercicio', 'version', ...Contract::SIGNED_DOCUMENT_COLUMNS]);
        if (! empty($filters['q'])) {
            $query->where(fn ($q) => $q->where('numero', 'like', '%'.$filters['q'].'%')->orWhere('processo', 'like', '%'.$filters['q'].'%'));
        }
        $contracts = $query->orderByDesc('exercicio')->orderByDesc('id')->paginate(10, ['id', 'numero', 'processo', 'exercicio'])->withQueryString();
        $documents = $selected ? ContractAttachment::where('contract_id', $selected->id)
            ->whereIn('signed_category', array_keys(SignedDocuments::CATEGORIES))->latest('id')
            ->paginate(10, ['id', 'name', 'size', 'signed_category', 'created_at'], 'documents_page')->withQueryString() : null;

        return response()->view('signed-documents.index', ['contracts' => $contracts, 'selected' => $selected,
            'documents' => $documents, 'categories' => SignedDocuments::CATEGORIES])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Contract $contract, SignedDocuments $documents)
    {
        Gate::authorize('update', $contract);
        $request->validate(['file' => 'required|file']);
        try {
            ApplicationErrors::translate(fn () => $documents->upload(
                new ActorIdentity($request->user()->id, $request->user()->session_version),
                (int) ($request->attributes->get('selected_fund')['id'] ?? 0), $contract->id,
                $request->only(['category', 'version', 'replace_confirmation']), $request->file('file')));
        } catch (ConflictHttpException $error) {
            return redirect()->route('publication.index', ['contract' => $contract->id])->withErrors(['file' => $error->getMessage()]);
        }

        return redirect()->route('publication.index', ['contract' => $contract->id])->with('status', 'PDF enviado e vinculado ao contrato.');
    }

    public function download(Request $request, Contract $contract, int $document, SignedDocuments $documents)
    {
        return $documents->download($request->user(), (int) ($request->attributes->get('selected_fund')['id'] ?? 0), $contract->id, $document);
    }
}
