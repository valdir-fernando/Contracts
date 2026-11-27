<?php

namespace App\Infrastructure\Atas;

use App\Domain\Atas\AtaTerm;
use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\ActivityEvent;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class RemoveAtaAmendment
{
    public function __construct(private LockActor $actors, private SelectedFund $funds, private AtaWritePolicy $policy) {}

    public function remove(User $expected, int $ataId, int $number, array $input, ?int $sessionFundId): array
    {
        return DB::transaction(function () use ($expected, $ataId, $number, $input, $sessionFundId) {
            $actor = $this->actors->lock($expected);
            abort_unless($sessionFundId !== null && $this->funds->allows($actor, $sessionFundId), 403);
            $ata = DB::table('tb_ATA')->where('ID', $ataId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($ata, 404);
            if ($ata->fund_id === null) {
                abort_unless($actor->isAdmin(), 403);
            } else {
                $this->policy->authorize($actor, (int) $ata->fund_id);
            }
            $this->policy->ensureEditable($ata);
            $data = Validator::make($input, ['version' => 'required|integer|min:1', 'instrument' => 'sometimes|required|in:apostille,amendment'])->validate();
            abort_if((int) $ata->version !== (int) $data['version'], 409, 'A ATA foi alterada. Consulte novamente antes de excluir.');

            $apostille = ($data['instrument'] ?? null) === 'apostille';
            $subject = $apostille ? 'apostilamento' : 'aditivo';
            $amendments = $this->legacyAmendments($ata, $apostille);
            abort_unless($number > 0 && $amendments->contains('number', $number), 404, ucfirst($subject).' ativo não encontrado nesta ATA.');
            abort_if($amendments->contains(fn ($amendment) => (int) $amendment->number > $number), 409,
                "Exclua primeiro os {$subject}s posteriores a este.");

            $amendmentIds = $amendments->where('number', $number)->pluck('ID')->all();
            $items = DB::table('tb_PRODUTOS_ATA')->whereNull('deleted_at')
                ->where(fn ($query) => $query->where('ID_ATA', $ataId)->orWhereNull('ID_ATA'))
                ->where(fn ($query) => $query->whereIn('amendment_id', $amendmentIds)->when(! $apostille, fn ($query) => $query
                    ->orWhere(fn ($unlinked) => $unlinked->whereNull('amendment_id')->where('ID_ATA', $ataId)->where('ADITIVO', $number))))
                ->orderBy('ID')->lockForUpdate()->get(['ID', 'ID_ATA', 'VL_TOTAL']);
            $removedAmount = '0.0000';
            foreach ($items->where('ID_ATA', $ataId) as $item) {
                $removedAmount = bcadd($removedAmount, DecimalAmount::normalize((string) $item->VL_TOTAL) ?? '0.0000', 4);
            }

            $version = (int) $ata->version + 1;
            $validity = $apostille ? [] : $this->validityChanges($ata, $amendments->where('number', $number)->pluck('end_date')->all(),
                $amendments->where('number', '<', $number)->groupBy('number')->sortKeysDesc()->map(fn ($rows) => $rows->pluck('end_date')->all())->all(),
                $number, $version);
            $now = now();
            TransactionGuard::requireActive();
            DB::table('tb_ADT_ATA')->whereIn('ID', $amendmentIds)->update(['deleted_at' => $now]);
            DB::table('tb_PRODUTOS_ATA')->whereIn('ID', $items->pluck('ID'))->update(['deleted_at' => $now, 'updated_at' => $now]);
            DB::table('tb_ATA')->where('ID', $ataId)->update($validity + ['version' => $version, 'updated_at' => $now]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => $apostille ? 'ata_apostille_deleted' : 'ata_amendment_deleted', 'created_at' => $now,
                'metadata' => ['ata_id' => $ataId, 'amendment_number' => $number,
                    'amendment_ids' => $amendmentIds, 'product_ids' => $items->pluck('ID')->all(), 'removed_amount' => $removedAmount,
                    'before_version' => (int) $ata->version, 'after_version' => $version, 'before_end' => $ata->FINAL_ATA,
                    'after_end' => array_key_exists('FINAL_ATA', $validity) ? $validity['FINAL_ATA'] : $ata->FINAL_ATA,
                    'before_validity_basis' => $ata->validity_basis,
                    'after_validity_basis' => array_key_exists('validity_basis', $validity) ? $validity['validity_basis'] : $ata->validity_basis]]);

            return ['id' => $ataId, 'number' => $ata->ATA, 'version' => $version];
        }, 3);
    }

    private function legacyAmendments(object $ata, bool $apostille)
    {
        $identity = new AtaAmendmentReader;
        $ataKey = $identity->key($ata->ATA);
        $process = trim($ata->PROCESSO);
        $records = DB::table('tb_ADT_ATA')->whereNull('deleted_at')->where('ADT', '>', 0)
            ->where(fn ($query) => $query->where('ID_ATA', $ata->ID)->orWhere(fn ($legacy) => $legacy->whereNull('ID_ATA')->whereNull('source_path')->whereRaw('TRIM(Proc_ATA) = ?', [$process])))->orderBy('ID')->lockForUpdate()
            ->get(['ID', 'ATA', 'ADT as number', 'FINAL as end_date', 'Tipo_ADT'])
            ->filter(fn ($row) => $ataKey !== null && $process !== '' && $identity->key($row->ATA) === $ataKey
                && SaveAtaAmendment::isApostille($row->Tipo_ADT) === $apostille);
        if ($records->isNotEmpty()) {
            $ambiguous = DB::table('tb_ATA')->where('ID', '!=', $ata->ID)->whereRaw('TRIM(PROCESSO) = ?', [$process])
                ->pluck('ATA')->contains(fn ($candidate) => $identity->key($candidate) === $ataKey);
            abort_if($ambiguous, 409, 'Há atas com o mesmo número e processo. Revise os vínculos antes de excluir.');
        }

        return $records;
    }

    private function validityChanges(object $ata, array $removedEnds, array $previousEnds, int $number, int $version): array
    {
        $basis = json_decode($ata->validity_basis ?? '', true);
        $derived = ($basis['rule'] ?? null) === UpdateAtaValidity::RULE;
        $sources = $derived ? ($basis['amendments'] ?? []) : [];
        $remainingSources = array_values(array_filter($sources, fn ($source) => (int) $source['number'] !== $number));
        $removedFromBasis = count($sources) !== count($remainingSources);
        $basisMatches = $derived && $ata->INICIO_ATA === ($basis['start'] ?? null) && $ata->FINAL_ATA === ($basis['end'] ?? null);
        if ($ata->FINAL_ATA === null || (! ($basisMatches && $removedFromBasis) && ! in_array($ata->FINAL_ATA, $removedEnds, true))) {
            return $removedFromBasis ? ['validity_basis' => null] : [];
        }
        if ($basisMatches && $sources === []) {
            return [];
        }

        $end = null;
        foreach ($previousEnds as $ends) {
            $dates = array_values(array_unique(array_filter($ends)));
            abort_if(count($dates) > 1, 409, 'Há vencimentos divergentes para o aditivo anterior.');
            if ($dates !== []) {
                $end = $dates[0];
                break;
            }
        }
        $knownOriginalEnd = $end === null && $basisMatches && array_key_exists('original_end', $basis);
        if ($knownOriginalEnd) {
            $end = $basis['original_end'];
        } elseif ($end === null) {
            try {
                $start = DateTimeImmutable::createFromFormat('!Y-m-d', $ata->INICIO_ATA ?? '');
                if ($start && $start->format('Y-m-d') === $ata->INICIO_ATA) {
                    $end = AtaTerm::end($start, ($basisMatches ? ($basis['period'] ?? null) : null) ?? $ata->PERIODO_ATA ?? '')->format('Y-m-d');
                }
            } catch (InvalidArgumentException) {
                // Keep the write atomic when the original term cannot be recovered.
            }
        }
        abort_if(($end === null && ! $knownOriginalEnd) || ($end !== null && $ata->INICIO_ATA !== null && $end < $ata->INICIO_ATA), 409,
            'Não foi possível determinar o vencimento da ATA após a exclusão.');

        $basis = $basisMatches && ($remainingSources === [] ? $previousEnds === [] : in_array($end, array_column($remainingSources, 'end'), true))
            ? array_replace($basis, ['end' => $end, 'amendments' => $remainingSources, 'applied_version' => $version])
            : null;

        return ['FINAL_ATA' => $end, 'validity_basis' => $basis === null ? null : json_encode($basis, JSON_THROW_ON_ERROR)];
    }
}
