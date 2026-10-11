<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractAttachment;
use App\Models\ContractAudit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContractAttachmentController extends Controller
{
    public function store(Request $request, Contract $contract)
    {
        Gate::authorize('update', $contract);
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|extensions:pdf,jpg,jpeg,png|max:10240',
        ]);
        $file = $request->file('file');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages(['file' => 'O conteúdo deve ser um PDF, JPEG ou PNG válido.']);
        }
        $path = $contract->id.'/'.Str::uuid().'.'.$file->extension();
        $disk = Storage::disk('contract_documents');
        try {
            DB::transaction(function () use ($request, $contract, $data, $file, $path, $disk, $mime) {
                [$actor, $contract] = $this->authorizeLocked($request, $contract, 'update');
                $disk->putFileAs(dirname($path), $file, basename($path));
                $attachment = ContractAttachment::create([
                    'contract_id' => $contract->id, 'uploaded_by' => $actor->id,
                    'name' => $data['name'], 'path' => $path, 'mime_type' => $mime,
                    'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
                ]);
                $this->audit($actor, $contract, $attachment, 'attachment_uploaded', 'Documento anexado ao contrato.');
            });
        } catch (\Throwable $error) {
            $disk->delete($path);
            throw $error;
        }

        return redirect()->route('contracts.show', $contract)->with('status', 'Documento anexado.');
    }

    public function download(Request $request, Contract $contract, int $attachment)
    {
        return DB::transaction(function () use ($request, $contract, $attachment) {
            [$actor, $contract] = $this->authorizeLocked($request, $contract, 'view');
            $document = ContractAttachment::where('contract_id', $contract->id)->findOrFail($attachment);
            $disk = Storage::disk('contract_documents');
            abort_unless($disk->exists($document->path), 404, 'Arquivo indisponível.');
            abort_unless(hash_equals($document->sha256, hash_file('sha256', $disk->path($document->path))), 409, 'A integridade do documento precisa ser verificada.');
            $this->audit($actor, $contract, $document, 'attachment_downloaded', 'Download de documento privado.');

            return response()->download($disk->path($document->path),
                'documento-'.$document->id.'.'.pathinfo($document->path, PATHINFO_EXTENSION),
                ['Content-Type' => $document->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        });
    }

    public function destroy(Request $request, Contract $contract, int $attachment)
    {
        return $this->change($request, $contract, $attachment, false);
    }

    public function restore(Request $request, Contract $contract, int $attachment)
    {
        return $this->change($request, $contract, $attachment, true);
    }

    private function change(Request $request, Contract $contract, int $attachment, bool $restore)
    {
        DB::transaction(function () use ($request, $contract, $attachment, $restore) {
            [$actor, $contract] = $this->authorizeLocked($request, $contract, 'delete');
            $query = ContractAttachment::where('contract_id', $contract->id)->lockForUpdate();
            $document = ($restore ? $query->onlyTrashed() : $query)->findOrFail($attachment);
            $data = $request->validate(['reason' => 'required|string|min:5|max:1000',
                'confirmation' => ['required', Rule::in([(string) $document->id])]]);
            $restore ? $document->restore() : $document->delete();
            $this->audit($actor, $contract, $document, $restore ? 'attachment_restored' : 'attachment_deleted', $data['reason']);
        });

        return redirect()->route('contracts.show', $contract)->with('status', $restore ? 'Documento restaurado.' : 'Documento removido da lista ativa. Histórico preservado.');
    }

    private function authorizeLocked(Request $request, Contract $contract, string $ability): array
    {
        $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->is_active && (int) $request->session()->get('auth_version', 0) === $actor->session_version, 403);
        $contract = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
        Gate::forUser($actor)->authorize($ability, $contract);

        return [$actor, $contract];
    }

    private function audit(User $actor, Contract $contract, ContractAttachment $attachment, string $action, string $reason): void
    {
        ContractAudit::create(['contract_id' => $contract->id, 'actor_id' => $actor->id,
            'action' => $action, 'reason' => $reason, 'created_at' => now(),
            'changes' => ['documento' => ['before' => null, 'after' => (string) $attachment->id]],
        ]);
    }
}
