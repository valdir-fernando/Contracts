<?php

namespace App\Infrastructure\Documents;

use App\Application\Contracts\Ports\ContractAccess;
use App\Application\Ports\TransactionManager;
use App\Application\Security\ActorIdentity;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\Contract;
use App\Models\ContractAttachment;
use App\Models\ContractAudit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SignedDocuments
{
    public const CATEGORIES = [
        'Contrato_PDF' => 'Contrato', 'Aditivo_PDF' => 'Aditivo', 'OS_PDF' => 'Ordem de Serviço',
        'Concordancia_PDF' => 'Concordância', 'Distrato_PDF' => 'Distrato', 'ATA_PDF' => 'Ata',
    ];

    public function __construct(private TransactionManager $transactions, private ContractAccess $access,
        private LockActor $actors, private SelectedFund $funds) {}

    public function upload(ActorIdentity $actor, int $fundId, int $contractId, array $input, ?UploadedFile $file): void
    {
        // Check current authorization before storing any bytes; repeat under locks before persistence.
        $expected = new User;
        $expected->forceFill(['id' => $actor->id, 'session_version' => $actor->sessionVersion]);
        $current = $this->actors->current($expected);
        $contract = Contract::findOrFail($contractId);
        Gate::forUser($current)->authorize('update', $contract);
        $this->authorizeFund($current, $fundId, $contract);
        $data = Validator::make($input + ['file' => $file], [
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))],
            'version' => 'required|integer|min:1',
            'replace_confirmation' => 'nullable|boolean',
            'file' => 'required|file|mimes:pdf|extensions:pdf|max:10240',
        ])->validate();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if ($mime !== 'application/pdf') {
            throw ValidationException::withMessages(['file' => 'Escolha um arquivo PDF válido, de até 10 MB.']);
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $size = $file->getSize();
        $path = $contractId.'/signed/'.Str::uuid().'.pdf';
        $disk = Storage::disk('contract_documents');
        try {
            if (! $disk->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private'])) {
                throw new \RuntimeException('Não foi possível armazenar o PDF.');
            }
            $this->transactions->run(function () use ($actor, $fundId, $contractId, $data, $file, $path, $hash, $size) {
                $context = $this->access->lockForSave($actor, $contractId);
                $contract = Contract::findOrFail($contractId);
                $this->authorizeFund(User::findOrFail($context->actor->id), $fundId, $contract);
                if ((int) $data['version'] !== $context->contract->version()) {
                    throw new ConflictHttpException('O contrato mudou. Recarregue a página e selecione novamente o PDF.');
                }
                $category = $data['category'];
                $before = $contract->getAttribute($category);
                if ($before !== null && empty($data['replace_confirmation'])) {
                    throw ValidationException::withMessages(['replace_confirmation' => 'Confirme a substituição do documento atual. O anterior será mantido no histórico.']);
                }
                $document = ContractAttachment::create([
                    'contract_id' => $contractId, 'uploaded_by' => $context->actor->id,
                    'name' => Str::limit($file->getClientOriginalName(), 190, ''),
                    'path' => $path, 'mime_type' => 'application/pdf', 'size' => $size,
                    'sha256' => $hash, 'signed_category' => $category,
                ]);
                $contract->setAttribute($category, $document->id);
                $contract->version = $context->contract->version() + 1;
                $contract->updated_by = $context->actor->id;
                $contract->save();
                ContractAudit::create(['contract_id' => $contractId, 'actor_id' => $context->actor->id,
                    'action' => 'signed_document_uploaded', 'reason' => 'Envio de PDF assinado: '.self::CATEGORIES[$category],
                    'changes' => [$category => ['before' => $before, 'after' => $document->id],
                        'version' => ['before' => $context->contract->version(), 'after' => $contract->version]], 'created_at' => now()]);
            });
        } catch (\Throwable $error) {
            $disk->delete($path);
            throw $error;
        }
    }

    public function download(User $expected, int $fundId, int $contractId, int $documentId)
    {
        return $this->transactions->run(function () use ($expected, $fundId, $contractId, $documentId) {
            $actor = $this->actors->lock($expected);
            $contract = Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('view', $contract);
            $this->authorizeFund($actor, $fundId, $contract);
            $document = ContractAttachment::where('contract_id', $contractId)->whereKey($documentId)
                ->whereIn('signed_category', array_keys(self::CATEGORIES))->lockForUpdate()->firstOrFail();
            $disk = Storage::disk('contract_documents');
            abort_unless($disk->exists($document->path), 404, 'PDF indisponível.');
            abort_unless(hash_equals($document->sha256, hash_file('sha256', $disk->path($document->path))), 409, 'A integridade do PDF precisa ser verificada.');
            ContractAudit::create(['contract_id' => $contractId, 'actor_id' => $actor->id,
                'action' => 'attachment_downloaded', 'reason' => 'Consulta de PDF assinado.',
                'changes' => ['documento' => ['before' => null, 'after' => $document->id]], 'created_at' => now()]);

            return response()->download($disk->path($document->path), 'documento-assinado-'.$document->id.'.pdf',
                ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        });
    }

    private function authorizeFund(User $actor, int $fundId, Contract $contract): void
    {
        abort_unless((int) $contract->fund_id === $fundId && $this->funds->allows($actor, $fundId), 403);
    }
}
