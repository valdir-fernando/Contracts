<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AtaSignedDocuments
{
    public const COPIES = ['original', 'redacted'];

    public function __construct(private LockActor $actors, private AtaWritePolicy $policy, private AtaSignedPdfRedaction $redaction) {}

    public function preview(User $expected, int $id, int $version, ?string $upload): array
    {
        $original = $this->source($expected, $id, $version, $upload);
        $this->checkPdf($original);
        $pages = $this->redaction->preview($original);
        abort_unless(hash_equals(hash('sha256', $original), hash('sha256', $this->source($expected, $id, $version, $upload))),
            409, 'O arquivo mudou. Abra uma nova prévia.');

        return ['sha256' => hash('sha256', $original), 'pages' => $pages];
    }

    public function storeOriginal(User $expected, int $id, int $version, string $original): array
    {
        $this->source($expected, $id, $version, $original);
        $this->checkPdf($original);
        $this->redaction->validate($original);

        return $this->storeNew($expected, $id, $version, $original, null);
    }

    public function store(User $expected, int $id, array $review, ?string $upload): array
    {
        $version = (int) $review['version'];
        $original = $this->source($expected, $id, $version, $upload);
        $this->checkPdf($original);
        $originalHash = hash('sha256', $original);
        abort_unless(hash_equals($originalHash, $review['sha256']), 409, 'O arquivo mudou. Abra uma nova prévia e revise as páginas.');
        $redacted = $this->redaction->redact($original, $review['rectangles'] ?? [], (int) $review['pages']);
        if ($upload !== null) {
            return $this->storeNew($expected, $id, $version, $original, $redacted);
        }

        return DB::transaction(function () use ($expected, $id, $version, $originalHash, $redacted) {
            $actor = $this->actors->lock($expected);
            $current = $this->storedOriginal($actor, $id, $version);
            abort_unless(hash_equals($originalHash, hash('sha256', $current)), 409, 'O arquivo mudou. Abra uma nova prévia.');
            TransactionGuard::requireActive();
            $redactedHash = hash('sha256', $redacted);
            $now = now();
            $this->archiveRemovedDocuments($id, $now);
            DB::table('ata_signed_document_copies')->where('ata_id', $id)->update([
                'redacted_sha256' => $redactedHash, 'redacted_pdf' => $redacted, 'removed_copy' => null,
            ]);
            DB::table('tb_ATA')->where('ID', $id)->update(['version' => $version + 1, 'updated_at' => $now]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_signed_redacted_copy_created', 'created_at' => $now,
                'metadata' => ['ata_id' => $id, 'before_version' => $version, 'after_version' => $version + 1,
                    'original_sha256' => $originalHash, 'redacted_sha256' => $redactedHash]]);

            return ['version' => $version + 1, 'signed_document_copies' => self::COPIES, 'can_create_redacted_copy' => false];
        }, 3);
    }

    private function storeNew(User $expected, int $id, int $version, string $original, ?string $redacted): array
    {
        $originalHash = hash('sha256', $original);
        $redactedHash = $redacted === null ? null : hash('sha256', $redacted);

        return DB::transaction(function () use ($expected, $id, $version, $original, $redacted, $originalHash, $redactedHash) {
            $actor = $this->actors->lock($expected);
            $this->uploadable($actor, $id, $version);
            TransactionGuard::requireActive();
            $now = now();
            $this->archiveRemovedDocuments($id, $now);
            DB::table('tb_ATA')->where('ID', $id)->update([
                'Doc_ATA' => $original, 'version' => $version + 1, 'updated_at' => $now,
            ]);
            DB::table('ata_signed_document_copies')->updateOrInsert(['ata_id' => $id], [
                'ata_id' => $id, 'original_sha256' => $originalHash,
                'redacted_sha256' => $redactedHash, 'redacted_pdf' => $redacted, 'deleted_at' => null, 'removed_copy' => null,
            ]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => $redacted === null ? 'ata_signed_original_uploaded' : 'ata_signed_documents_uploaded', 'created_at' => $now,
                'metadata' => ['ata_id' => $id, 'before_version' => $version, 'after_version' => $version + 1,
                    'original_sha256' => $originalHash, 'redacted_sha256' => $redactedHash]]);

            return ['version' => $version + 1, 'signed_document_copies' => $redacted === null ? ['original'] : self::COPIES,
                'can_create_redacted_copy' => $redacted === null];
        }, 3);
    }

    public function remove(User $expected, int $id, int $version, ?string $kind = null): array
    {
        return DB::transaction(function () use ($expected, $id, $version, $kind) {
            $actor = $this->actors->lock($expected);
            $ata = $this->authorized($actor, $id);
            abort_unless((int) $ata->version === $version, 409, 'A ata foi alterada. Consulte novamente antes de remover os PDFs.');
            $query = DB::table('ata_signed_document_copies')->where('ata_id', $id)->whereNull('deleted_at');
            $copy = $query->first(['original_sha256', 'redacted_sha256', 'removed_copy']);
            $available = self::availableCopies($copy);
            abort_if($available === [] || ($kind !== null && ! in_array($kind, $available, true)), 404, 'O PDF selecionado não está disponível.');
            TransactionGuard::requireActive();
            $now = now();
            $remaining = $kind === null ? [] : array_values(array_diff($available, [$kind]));
            $query->update($remaining === [] ? ['deleted_at' => $now] : ['removed_copy' => $kind]);
            DB::table('tb_ATA')->where('ID', $id)->update(['version' => $version + 1, 'updated_at' => $now]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_signed_documents_removed', 'created_at' => $now,
                'metadata' => ['ata_id' => $id, 'before_version' => $version, 'after_version' => $version + 1, 'copy' => $kind ?? 'both', ...((array) $copy)]]);

            return ['version' => $version + 1, 'signed_document_copies' => $remaining, 'can_create_redacted_copy' => $remaining === ['original']];
        }, 3);
    }

    /** @return array{bytes: string, number: ?string, supplier: ?string} */
    public function download(User $expected, int $id, bool $original): array
    {
        return DB::transaction(function () use ($expected, $id, $original) {
            $actor = $this->actors->lock($expected);
            $ata = $this->authorized($actor, $id);
            $kind = $original ? 'original' : 'redacted';
            $copy = DB::table('ata_signed_document_copies')->where('ata_id', $id)->whereNull('deleted_at')
                ->where(fn ($query) => $query->whereNull('removed_copy')->orWhere('removed_copy', '!=', $kind));
            $hashColumn = $original ? 'original_sha256' : 'redacted_sha256';
            $record = $copy->whereNotNull($hashColumn)->first($original ? [$hashColumn] : [$hashColumn, 'redacted_pdf']);
            abort_unless($record, 404);
            $bytes = $original ? DB::table('tb_ATA')->where('ID', $id)->value('Doc_ATA') : $record->redacted_pdf;
            abort_unless(is_string($bytes) && hash_equals($record->{$hashColumn}, hash('sha256', $bytes)), 409,
                'O arquivo armazenado não passou na verificação de integridade.');
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_signed_document_downloaded', 'created_at' => now(),
                'metadata' => ['ata_id' => $id, 'copy' => $kind]]);

            return ['bytes' => $bytes, 'number' => $ata->ATA, 'supplier' => $ata->FORNECEDOR];
        });
    }

    public static function availableCopies(?object $pair): array
    {
        if ($pair === null) {
            return [];
        }

        $copies = $pair->redacted_sha256 === null ? ['original'] : self::COPIES;

        return array_values(array_diff($copies, [$pair->removed_copy]));
    }

    private function source(User $expected, int $id, int $version, ?string $upload): string
    {
        return DB::transaction(function () use ($expected, $id, $version, $upload) {
            $actor = $this->actors->lock($expected);
            if ($upload === null) {
                return $this->storedOriginal($actor, $id, $version);
            }
            $this->uploadable($actor, $id, $version);

            return $upload;
        });
    }

    private function storedOriginal(User $actor, int $id, int $version): string
    {
        $ata = $this->authorized($actor, $id);
        abort_unless((int) $ata->version === $version, 409, 'A ata foi alterada. Consulte novamente antes de criar a cópia tarjada.');
        $pair = DB::table('ata_signed_document_copies')->where('ata_id', $id)->whereNull('deleted_at')
            ->first(['original_sha256', 'redacted_sha256', 'removed_copy']);
        $copies = self::availableCopies($pair);
        abort_unless(in_array('original', $copies, true), 404, 'O PDF original não está disponível.');
        abort_unless($copies === ['original'], 409, 'Esta ata já tem uma cópia tarjada registrada.');
        $bytes = DB::table('tb_ATA')->where('ID', $id)->value('Doc_ATA');
        abort_unless(is_string($bytes) && hash_equals($pair->original_sha256, hash('sha256', $bytes)), 409,
            'O arquivo armazenado não passou na verificação de integridade.');

        return $bytes;
    }

    private function uploadable(User $actor, int $id, int $version): void
    {
        $ata = $this->authorized($actor, $id);
        abort_unless((int) $ata->version === $version, 409, 'A ata foi alterada. Consulte novamente antes de anexar o PDF.');
        $copy = DB::table('ata_signed_document_copies')->where('ata_id', $id)->first(['deleted_at']);
        abort_if($copy ? $copy->deleted_at === null : $ata->has_original,
            409, 'Esta ata já tem um PDF armazenado.');
    }

    private function archiveRemovedDocuments(int $id, \DateTimeInterface $archivedAt): void
    {
        // Copy both binaries inside the database before reusing the current slot.
        $previous = DB::table('ata_signed_document_copies as copy')
            ->join('tb_ATA as ata', 'ata.ID', '=', 'copy.ata_id')
            ->where('copy.ata_id', $id)
            ->where(fn ($query) => $query->whereNotNull('copy.deleted_at')->orWhere('copy.removed_copy', 'redacted'))
            ->select('copy.ata_id', 'ata.Doc_ATA', 'copy.redacted_pdf', 'copy.original_sha256', 'copy.redacted_sha256')
            ->selectRaw('COALESCE(copy.deleted_at, ?) AS deleted_at', [$archivedAt]);
        DB::table('ata_signed_document_history')->insertUsing(
            ['ata_id', 'original_pdf', 'redacted_pdf', 'original_sha256', 'redacted_sha256', 'deleted_at'], $previous
        );
    }

    private function authorized(User $actor, int $id): object
    {
        abort_unless($actor->isAdmin(), 403);
        $ata = DB::table('tb_ATA')->where('ID', $id)->whereNull('deleted_at')
            ->select(['version', 'fund_id', 'ATA', 'FORNECEDOR'])->selectRaw('CASE WHEN Doc_ATA IS NULL THEN 0 ELSE 1 END AS has_original')
            ->lockForUpdate()->first();
        abort_unless($ata, 404);
        if ($ata->fund_id !== null) {
            $this->policy->authorize($actor, (int) $ata->fund_id);
        }

        return $ata;
    }

    private function checkPdf(string $original): void
    {
        if (! str_starts_with($original, '%PDF-')) {
            throw ValidationException::withMessages(['pdf' => 'Selecione um arquivo PDF válido.']);
        }
    }
}
