<?php

namespace App\Http\Controllers;

use App\Application\Security\ActorIdentity;
use App\Infrastructure\Documents\SignedDocuments;
use App\Infrastructure\Http\ApplicationErrors;
use App\Infrastructure\Security\LockActor;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SignedDocumentController extends Controller
{
    public function index(Request $request, LockActor $actors)
    {
        $actor = $actors->current($request->user());
        Gate::forUser($actor)->authorize('viewAny', Contract::class);

        return redirect()->route('reports.index')->header('Cache-Control', 'private, no-store');
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
            return redirect()->route('contracts.show', $contract)->withErrors(['file' => $error->getMessage()]);
        }

        return redirect()->route('contracts.show', $contract)->with('status', 'PDF enviado e vinculado ao contrato.');
    }

    public function download(Request $request, Contract $contract, int $document, SignedDocuments $documents)
    {
        return $documents->download($request->user(), (int) ($request->attributes->get('selected_fund')['id'] ?? 0), $contract->id, $document);
    }
}
