<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AtaAmendmentSignedDocuments
{
    public function __construct(private LockActor $actors, private AtaWritePolicy $policy, private AtaSignedPdfRedaction $redaction) {}

    public function preview(User $actor, int $ata, int $amendment, int $version, ?string $upload): array
    {
        $source = $this->source($actor, $ata, $amendment, $version, $upload);
        $hash = hash('sha256', $source);
        $pages = $this->redaction->preview($source);
        abort_unless(hash_equals($hash, hash('sha256', $this->source($actor, $ata, $amendment, $version, $upload))), 409,
            'O PDF mudou. Abra uma nova prévia.');

        return ['sha256' => $hash, 'pages' => $pages];
    }

    public function storeOriginal(User $actor, int $ata, int $amendment, int $version, string $upload): array
    {
        $this->source($actor, $ata, $amendment, $version, $upload);
        $this->redaction->validate($upload);

        return $this->save($actor, $ata, $amendment, $version, $upload, null, false);
    }

    public function store(User $actor, int $ata, int $amendment, array $review, ?string $upload): array
    {
        $version = (int) $review['version'];
        $source = $this->source($actor, $ata, $amendment, $version, $upload);
        abort_unless(hash_equals(hash('sha256', $source), $review['sha256']), 409, 'O PDF mudou. Abra uma nova prévia.');
        $redacted = $this->redaction->redact($source, $review['rectangles'] ?? [], (int) $review['pages']);

        return $this->save($actor, $ata, $amendment, $version, $source, $redacted, $upload === null);
    }

    public function remove(User $expected, int $ata, int $amendment, int $version, ?string $copy): array
    {
        return DB::transaction(function () use ($expected, $ata, $amendment, $version, $copy) {
            $actor = $this->actors->lock($expected);
            $this->authorize($actor, $ata, $amendment, $version);
            $query = self::active($ata, $amendment);
            $record = $query->first(['original_sha256', 'redacted_sha256', 'removed_copy']);
            $copies = AtaSignedDocuments::availableCopies($record);
            abort_if($copies === [] || ($copy !== null && ! in_array($copy, $copies, true)), 404, 'O PDF selecionado não está disponível.');
            $remaining = $copy === null ? [] : array_values(array_diff($copies, [$copy]));
            TransactionGuard::requireActive();
            $query->update($remaining === [] ? ['deleted_at' => now()] : ['removed_copy' => $copy]);

            return $this->updated($actor, $ata, $amendment, $version, $remaining, 'removed', ['copy' => $copy ?? 'both', ...(array) $record]);
        }, 3);
    }

    /** @return array{bytes: string, number: ?string, supplier: ?string, amendment_number: int} */
    public function download(User $expected, int $ata, int $amendment, bool $original): array
    {
        return DB::transaction(function () use ($expected, $ata, $amendment, $original) {
            $actor = $this->actors->lock($expected);
            $target = $this->authorize($actor, $ata, $amendment);
            $kind = $original ? 'original' : 'redacted';
            $record = self::active($ata, $amendment)->first(['original_sha256', 'redacted_sha256', 'removed_copy', $kind.'_pdf']);
            abort_unless(in_array($kind, AtaSignedDocuments::availableCopies($record), true), 404);
            $bytes = $this->verifiedBytes($record, $kind);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_amendment_signed_document_downloaded', 'created_at' => now(),
                'metadata' => ['ata_id' => $ata, 'amendment_id' => $amendment, 'copy' => $kind]]);

            return ['bytes' => $bytes, 'number' => $target->ATA, 'supplier' => $target->FORNECEDOR, 'amendment_number' => (int) $target->amendment_number];
        });
    }

    private function save(User $expected, int $ata, int $amendment, int $version, string $source, ?string $redacted, bool $stored): array
    {
        $hash = hash('sha256', $source);
        $redactedHash = $redacted === null ? null : hash('sha256', $redacted);

        return DB::transaction(function () use ($expected, $ata, $amendment, $version, $source, $redacted, $stored, $hash, $redactedHash) {
            $actor = $this->actors->lock($expected);
            $this->authorize($actor, $ata, $amendment, $version);
            $current = $this->original($ata, $amendment, $stored ? null : $source);
            abort_unless(hash_equals($hash, hash('sha256', $current)), 409, 'O PDF mudou. Abra uma nova prévia.');
            TransactionGuard::requireActive();
            if ($stored) {
                self::active($ata, $amendment)->update(['deleted_at' => now()]);
            }
            DB::table('ata_amendment_signed_documents')->insert(['ata_id' => $ata, 'amendment_id' => $amendment,
                'original_pdf' => $source, 'redacted_pdf' => $redacted, 'original_sha256' => $hash, 'redacted_sha256' => $redactedHash]);
            $copies = $redacted === null ? ['original'] : AtaSignedDocuments::COPIES;

            return $this->updated($actor, $ata, $amendment, $version, $copies, 'stored',
                ['original_sha256' => $hash, 'redacted_sha256' => $redactedHash]);
        }, 3);
    }

    private function source(User $expected, int $ata, int $amendment, int $version, ?string $upload): string
    {
        if ($upload !== null && ! str_starts_with($upload, '%PDF-')) {
            throw ValidationException::withMessages(['pdf' => 'Selecione um arquivo PDF válido.']);
        }

        return DB::transaction(function () use ($expected, $ata, $amendment, $version, $upload) {
            $this->authorize($this->actors->lock($expected), $ata, $amendment, $version);

            return $this->original($ata, $amendment, $upload);
        });
    }

    private function original(int $ata, int $amendment, ?string $upload): string
    {
        $record = self::active($ata, $amendment)->first(['original_sha256', 'redacted_sha256', 'removed_copy']);
        if ($upload !== null) {
            abort_if($record, 409, 'Este aditivo já tem um PDF armazenado.');

            return $upload;
        }
        $copies = AtaSignedDocuments::availableCopies($record);
        abort_unless(in_array('original', $copies, true), 404, 'O PDF original não está disponível.');
        abort_unless($copies === ['original'], 409, 'Este aditivo já tem uma cópia tarjada.');

        return $this->verifiedBytes($record, 'original', self::active($ata, $amendment)->value('original_pdf'));
    }

    private function verifiedBytes(object $record, string $kind, ?string $bytes = null): string
    {
        $bytes ??= $record->{$kind.'_pdf'} ?? null;
        abort_unless(is_string($bytes) && hash_equals($record->{$kind.'_sha256'}, hash('sha256', $bytes)), 409,
            'O PDF armazenado não passou na verificação de integridade.');

        return $bytes;
    }

    private function authorize(User $actor, int $ata, int $amendment, ?int $version = null): object
    {
        abort_unless($actor->isAdmin(), 403);
        $target = DB::table('tb_ATA')->where('ID', $ata)->whereNull('deleted_at')->lockForUpdate()
            ->first(['ATA', 'PROCESSO', 'fund_id', 'version', 'FORNECEDOR']);
        abort_unless($target, 404);
        if ($target->fund_id !== null) {
            $this->policy->authorize($actor, (int) $target->fund_id);
        }
        abort_if($version !== null && (int) $target->version !== $version, 409, 'A ATA foi alterada. Consulte novamente antes de continuar.');
        $identity = new AtaAmendmentReader;
        $key = $identity->key($target->ATA);
        $process = trim($target->PROCESSO ?? '');
        $record = DB::table('tb_ADT_ATA')->where('ID', $amendment)->whereNull('deleted_at')
            ->whereRaw('TRIM(Proc_ATA) = ?', [$process])->lockForUpdate()->first(['ATA', 'ADT']);
        abort_unless($record && $key !== null && $process !== '' && $identity->key($record->ATA) === $key, 404);
        abort_if(DB::table('tb_ATA')->where('ID', '!=', $ata)->whereRaw('TRIM(PROCESSO) = ?', [$process])
            ->pluck('ATA')->contains(fn ($number) => $identity->key($number) === $key), 409, 'A identificação da ATA é ambígua.');
        $target->amendment_number = $record->ADT;

        return $target;
    }

    private static function active(int $ata, int $amendment): Builder
    {
        return DB::table('ata_amendment_signed_documents')->where('ata_id', $ata)->where('amendment_id', $amendment)->whereNull('deleted_at');
    }

    private function updated(User $actor, int $ata, int $amendment, int $version, array $copies, string $action, array $metadata): array
    {
        DB::table('tb_ATA')->where('ID', $ata)->update(['version' => $version + 1, 'updated_at' => now()]);
        ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'ata_amendment_signed_documents_'.$action, 'created_at' => now(),
            'metadata' => ['ata_id' => $ata, 'amendment_id' => $amendment, 'before_version' => $version, 'after_version' => $version + 1, ...$metadata]]);

        return ['version' => $version + 1, 'signed_document_copies' => $copies, 'can_create_redacted_copy' => $copies === ['original']];
    }
}
