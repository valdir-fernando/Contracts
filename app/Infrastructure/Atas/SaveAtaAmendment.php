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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SaveAtaAmendment
{
    public const EXTENSION = 'Prorrogação - Saldo Positivo';

    public const EQUAL_QUANTITY = 'Prorrogação/Quantitativo - Igual Período e Valor';

    public const ADVANTAGE_OPTIONS = ['Pesquisa de Preços', 'Declaração do Gestor'];

    public const SUPPORTED_TYPES = [self::EXTENSION, self::EQUAL_QUANTITY];

    public const ADVANTAGE_TYPES = self::SUPPORTED_TYPES;

    public function __construct(private LockActor $actors, private SelectedFund $funds, private AtaWritePolicy $policy) {}

    public function save(User $expected, int $ataId, array $input, ?int $sessionFundId, ?int $editingNumber = null): array
    {
        return DB::transaction(function () use ($expected, $ataId, $input, $sessionFundId, $editingNumber) {
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
            $data = Validator::make($input, [
                'version' => 'required|integer|min:1',
                'number' => 'required|integer|min:1|max:4294967295',
                'date' => 'required|date_format:Y-m-d',
                'process' => 'required|string|max:64',
                'type' => ['required', Rule::in(self::SUPPORTED_TYPES)],
                'advantage' => [Rule::excludeIf(! in_array($input['type'] ?? null, self::ADVANTAGE_TYPES, true)), 'required', 'string', Rule::in(self::ADVANTAGE_OPTIONS)],
                'start' => 'required|date_format:Y-m-d',
                'end' => 'required|date_format:Y-m-d|after_or_equal:start',
                'period' => 'required|string|max:1000',
                'excluded_product_ids' => [Rule::prohibitedIf(($input['type'] ?? null) !== self::EXTENSION), 'sometimes', 'array', 'list'],
                'excluded_product_ids.*' => 'required|integer|min:1|distinct',
                'balance_quantities' => [Rule::prohibitedIf(($input['type'] ?? null) !== self::EXTENSION), 'sometimes', 'array', 'list'],
                'balance_quantities.*' => 'required|array:product_id,quantity',
                'balance_quantities.*.product_id' => 'required|integer|min:1|distinct',
                'balance_quantities.*.quantity' => 'required|string|max:32',
            ], ['type.in' => 'Este tipo de aditivo ainda não tem regras de lançamento configuradas.'], [
                'number' => 'Nº Aditivo', 'date' => 'Data_ADT', 'process' => 'Proc_ADT',
                'type' => 'Tipo_ADT', 'start' => 'INICIO', 'end' => 'FINAL', 'period' => 'Período', 'advantage' => 'Vantajosidade',
            ])->validate();
            abort_if((int) $ata->version !== (int) $data['version'], 409, 'A ATA foi alterada. Consulte novamente antes de salvar.');
            $maximumEnd = AtaTerm::maximumAmendmentEnd($ata->INICIO_ATA);
            if ($maximumEnd === null) {
                throw ValidationException::withMessages(['end' => 'Informe a data inicial da vigência original da ATA antes de prorrogar.']);
            }
            if ($data['end'] > $maximumEnd->format('Y-m-d')) {
                throw ValidationException::withMessages(['end' => 'A vigência total da ATA não pode ultrapassar 2 anos. Informe FINAL até '.$maximumEnd->format('d/m/Y').'.']);
            }

            $number = (int) $data['number'];
            abort_if($editingNumber !== null && $editingNumber !== $number, 422, 'O número do aditivo não pode ser alterado.');
            $identity = new AtaAmendmentReader;
            $key = $identity->key($ata->ATA);
            $process = trim($ata->PROCESSO);
            abort_if($key === null || $process === '' || DB::table('tb_ATA')->where('ID', '!=', $ataId)
                ->whereRaw('TRIM(PROCESSO) = ?', [$process])->pluck('ATA')->contains(fn ($candidate) => $identity->key($candidate) === $key),
                409, 'A identificação da ATA e do processo deve ser única para salvar o aditivo.');
            $registered = DB::table('tb_ADT_ATA')->whereRaw('TRIM(Proc_ATA) = ?', [$process])->orderBy('ID')->lockForUpdate()
                ->get(['ID', 'ATA', 'ADT', 'Data_ADT', 'Proc_ADT', 'Tipo_ADT', 'INICIO', 'FINAL', 'Período', 'VANTAJOSIDADE', 'balance_products', 'deleted_at'])
                ->filter(fn ($row) => $identity->key($row->ATA) === $key);
            $imported = DB::table('tb_ADITIVOS_ATA')->where('ID_ATA', $ataId)->orderBy('ID')->lockForUpdate()
                ->get(['NUMERO', 'FINAL_ADITIVO', 'deleted_at']);
            $products = DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $ataId)->orderBy('ID')->lockForUpdate()
                ->get(['ID', 'ADITIVO', 'ID_ADITIVO', 'ITEM', 'COD_PROD', 'FICHA', 'DISCRIMINACAO', 'MARCA', 'UND', 'QTD', 'QTD_ESPECIAL', 'VL_UNIT', 'VL_TOTAL', 'deleted_at']);
            $registered = $registered->whereNull('deleted_at');
            $imported = $imported->whereNull('deleted_at');
            $products = $products->whereNull('deleted_at');
            $latestNumber = max($registered->max('ADT') ?? 0, $imported->max('NUMERO') ?? 0, $products->max('ADITIVO') ?? 0);
            $previous = null;
            $currentEnd = max($ata->FINAL_ATA, $registered->max('FINAL'), $imported->max('FINAL_ADITIVO'));
            if ($editingNumber === null) {
                abort_if($number <= $latestNumber, 409, 'Informe um número maior que o dos aditivos ativos desta ATA.');
            } else {
                $current = $registered->where('ADT', $number);
                abort_if($current->isEmpty(), 404, 'Aditivo ativo não encontrado nesta ATA.');
                abort_if($number !== (int) $latestNumber || $current->count() !== 1 || $imported->contains('NUMERO', $number),
                    409, 'Só é possível editar o último aditivo lançado pelo painel.');
                $previous = $current->first();
                $basis = json_decode($ata->validity_basis ?? '', true);
                abort_unless(($basis['rule'] ?? null) === UpdateAtaValidity::RULE && array_key_exists('original_end', $basis)
                    && $ata->INICIO_ATA === ($basis['start'] ?? null) && $ata->FINAL_ATA === ($basis['end'] ?? null)
                    && in_array((int) $previous->ID, array_column($basis['amendments'] ?? [], 'legacy_id'), true),
                    409, 'Só é possível editar um aditivo lançado pelo painel com vigência conciliada.');
                $currentEnd = max($basis['original_end'], $registered->where('ADT', '<', $number)->max('FINAL'), $imported->max('FINAL_ADITIVO'));
            }
            if (($currentEnd !== null && $data['end'] <= $currentEnd) || ($ata->INICIO_ATA !== null && $data['end'] < $ata->INICIO_ATA)) {
                throw ValidationException::withMessages(['end' => 'Para prorrogar a ATA, informe um FINAL posterior ao vencimento atual.']);
            }

            $previousItems = $previous === null ? collect() : $products->where('ADITIVO', $number)->whereNull('ID_ADITIVO');
            $preserveItems = $previous !== null && $previous->Tipo_ADT === $data['type'];
            $originals = $products->where('ADITIVO', 0)->whereNull('ID_ADITIVO')->keyBy('ID');
            $items = $data['type'] === self::EQUAL_QUANTITY && ! $preserveItems
                ? $originals
                : collect();
            if ($data['type'] === self::EQUAL_QUANTITY && ! $preserveItems && $items->isEmpty()) {
                throw ValidationException::withMessages(['type' => 'Cadastre os produtos originais da ATA antes de lançar esta prorrogação com quantitativo.']);
            }

            $version = (int) $ata->version + 1;
            $now = now();
            TransactionGuard::requireActive();
            $record = [
                'Data_ADT' => $data['date'], 'Proc_ADT' => $data['process'], 'Tipo_ADT' => $data['type'],
                'INICIO' => $data['start'], 'FINAL' => $data['end'], 'Período' => $data['period'],
                'VANTAJOSIDADE' => $data['advantage'] ?? null,
            ];
            if ($previous === null) {
                $amendmentId = DB::table('tb_ADT_ATA')->insertGetId($record + ['ATA' => $ata->ATA, 'Proc_ATA' => $process, 'ADT' => $number], 'ID');
            } else {
                $amendmentId = (int) $previous->ID;
                DB::table('tb_ADT_ATA')->where('ID', $amendmentId)->update($record);
                if ($previousItems->isNotEmpty()) {
                    $changes = $preserveItems ? ['DATA_ADT' => $data['date'], 'PROC_ADT' => $data['process']] : ['deleted_at' => $now];
                    DB::table('tb_PRODUTOS_ATA')->whereIn('ID', $previousItems->pluck('ID'))->update($changes + ['updated_at' => $now]);
                }
            }
            $excluded = $this->syncExcludedProducts($ataId, $amendmentId, $number,
                $data['type'] === self::EXTENSION ? ($data['excluded_product_ids'] ?? null) : [], $originals, $now);
            $balance = $data['type'] === self::EXTENSION
                ? $this->balanceProducts($data['balance_quantities'] ?? null, $previous?->balance_products, $excluded['after'], $originals)
                : [];
            if ($balance !== [] || $previous?->balance_products !== null) {
                DB::table('tb_ADT_ATA')->where('ID', $amendmentId)->update([
                    'balance_products' => $balance === [] ? null : json_encode($balance, JSON_THROW_ON_ERROR),
                ]);
            }
            $productTotal = $preserveItems ? $this->sumProducts($previousItems) : '0.0000';
            $productIds = $preserveItems ? $previousItems->pluck('ID')->all() : [];
            foreach ($items as $item) {
                $quantity = DecimalAmount::normalize((string) $item->QTD);
                $price = DecimalAmount::normalize((string) $item->VL_UNIT);
                $total = $quantity === null || $price === null ? null : DecimalAmount::normalize(bcmul($quantity, $price, 4));
                if ($total === null || bccomp($quantity, '0', 4) <= 0 || bccomp($price, '0', 4) < 0) {
                    throw ValidationException::withMessages(['type' => "Revise a quantidade e o valor unitário do item {$item->ITEM} da ATA."]);
                }
                $productTotal = bcadd($productTotal, $total, 4);
                $productIds[] = DB::table('tb_PRODUTOS_ATA')->insertGetId([
                    'ID_ATA' => $ataId, 'ATA' => $ata->ATA, 'PROCESSO' => $ata->PROCESSO, 'LICITACAO' => $ata->LICITACAO, 'N_LICIT' => $ata->N_LICIT,
                    'ADITIVO' => $number, 'DATA_ADT' => $data['date'], 'PROC_ADT' => $data['process'],
                    'ITEM' => $item->ITEM, 'COD_PROD' => $item->COD_PROD, 'FICHA' => $item->FICHA, 'DISCRIMINACAO' => $item->DISCRIMINACAO,
                    'MARCA' => $item->MARCA, 'UND' => $item->UND, 'QTD' => $quantity, 'QTD_ESPECIAL' => $item->QTD_ESPECIAL,
                    'VL_UNIT' => $price, 'VL_TOTAL' => $total,
                    'source_row' => json_encode(['origin' => 'native_ata_amendment', 'amendment_id' => $amendmentId, 'original_product_id' => $item->ID], JSON_THROW_ON_ERROR),
                    'created_at' => $now, 'updated_at' => $now,
                ], 'ID');
            }

            $basis = $this->validityBasis($ata, $number, $amendmentId, $data['end'], $version);
            DB::table('tb_ATA')->where('ID', $ataId)->update(['FINAL_ATA' => $data['end'],
                'validity_basis' => json_encode($basis, JSON_THROW_ON_ERROR), 'version' => $version, 'updated_at' => $now]);
            $amendment = array_intersect_key($data, array_flip(['date', 'process', 'type', 'start', 'end', 'period', 'advantage']));
            if ($data['type'] === self::EXTENSION) {
                $amendment['excluded_product_ids'] = $excluded['after'];
                if ($balance !== []) {
                    $amendment['balance_products'] = $balance;
                }
            }
            $amount = $data['type'] === self::EXTENSION ? '0.0000' : $productTotal;
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => $previous === null ? 'ata_amendment_saved' : 'ata_amendment_updated', 'created_at' => $now,
                'metadata' => ['ata_id' => $ataId, 'amendment_id' => $amendmentId, 'amendment_number' => $number,
                    'amendment' => $amendment, 'before_amendment' => $previous, 'before_product_ids' => $previousItems->pluck('ID')->all(),
                    'before_excluded_product_ids' => $excluded['before'], 'excluded_product_ids' => $excluded['after'],
                    'product_ids' => $productIds, 'amount' => $amount, 'before_version' => (int) $ata->version, 'after_version' => $version,
                    'before_end' => $ata->FINAL_ATA, 'after_end' => $data['end'], 'before_validity_basis' => $ata->validity_basis]]);

            $updatedTotal = bcadd($this->sumProducts($products->whereNotIn('ID', $previousItems->pluck('ID'))), $productTotal, 4);

            return ['id' => $ataId, 'number' => $ata->ATA, 'version' => $version, 'amendment_number' => $number,
                ...($actor->isAdmin() ? ['signed_amendment_id' => $amendmentId] : []),
                'amendment' => $amendment, 'amendment_amount' => $amount, 'amendment_updated_total' => $updatedTotal,
                'original_end_date' => $basis['original_end']];
        }, 3);
    }

    private function balanceProducts(?array $requested, ?string $previous, array $excluded, Collection $originals): array
    {
        $saved = collect(json_decode($previous ?? '[]', true, flags: JSON_THROW_ON_ERROR))->keyBy('id');
        if ($requested === null) {
            return $saved->except($excluded)->values()->all();
        }

        $balance = [];
        foreach ($requested as $entry) {
            $id = (int) $entry['product_id'];
            $quantity = DecimalAmount::normalize($entry['quantity']);
            $item = $originals->get($id);
            $old = $saved->get($id);
            if (in_array($id, $excluded, true) || $quantity === null || str_starts_with($quantity, '-')) {
                throw ValidationException::withMessages(['balance_quantities' => 'Confira as quantidades dos produtos incluídos na prorrogação.']);
            }
            if ($old && $old['balance_quantity'] === $quantity) {
                $balance[] = $old;

                continue;
            }
            $limit = $item ? DecimalAmount::normalize((string) $item->QTD) : null;
            if ($limit === null || bccomp($quantity, $limit, 4) > 0) {
                $label = $item?->ITEM ?? $id;
                throw ValidationException::withMessages(['balance_quantities' => "Informe apenas o saldo do item {$label}, até a quantidade original da ATA."]);
            }
            $balance[] = ['id' => $id, 'item' => $item->ITEM, 'code' => $item->COD_PROD, 'description' => $item->DISCRIMINACAO,
                'brand' => $item->MARCA, 'unit' => $item->UND, 'quantity' => $limit, 'balance_quantity' => $quantity,
                'special_quantity' => DecimalAmount::normalize((string) $item->QTD_ESPECIAL),
                'unit_price' => DecimalAmount::normalize((string) $item->VL_UNIT)];
        }

        return $balance;
    }

    private function syncExcludedProducts(int $ataId, int $amendmentId, int $number, ?array $requested, Collection $originals, \DateTimeInterface $now): array
    {
        TransactionGuard::requireActive();
        $active = DB::table('ata_amendment_excluded_products')->where('ata_id', $ataId)->where('amendment_id', $amendmentId)->whereNull('deleted_at');
        $before = (clone $active)->orderBy('id')->lockForUpdate()->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        $after = $requested === null ? $before : array_map('intval', $requested);
        $newIds = array_diff($after, $before);
        $removed = array_diff($before, $after);
        if (array_diff($newIds, $originals->keys()->all()) !== []) {
            throw ValidationException::withMessages(['excluded_product_ids' => 'Selecione apenas produtos originais ativos desta ATA.']);
        }
        if ($removed !== []) {
            $active->whereIn('product_id', $removed)->update(['deleted_at' => $now]);
        }
        foreach (array_chunk($newIds, 100) as $ids) {
            DB::table('ata_amendment_excluded_products')->insert(array_map(function ($id) use ($ataId, $amendmentId, $number, $originals, $now) {
                $snapshot = array_intersect_key((array) $originals[$id], array_flip([
                    'ITEM', 'COD_PROD', 'FICHA', 'DISCRIMINACAO', 'MARCA', 'UND', 'QTD', 'QTD_ESPECIAL', 'VL_UNIT', 'VL_TOTAL',
                ]));
                foreach (['QTD', 'QTD_ESPECIAL', 'VL_UNIT', 'VL_TOTAL'] as $field) {
                    $snapshot[$field] = DecimalAmount::normalize((string) $snapshot[$field]);
                }

                return ['ata_id' => $ataId, 'amendment_id' => $amendmentId, 'amendment_number' => $number, 'product_id' => $id,
                    'product_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_at' => $now];
            }, $ids));
        }

        return ['before' => $before, 'after' => $after];
    }

    private function sumProducts(iterable $products): string
    {
        $total = '0.0000';
        foreach ($products as $product) {
            $total = bcadd($total, DecimalAmount::normalize((string) $product->VL_TOTAL) ?? '0.0000', 4);
        }

        return $total;
    }

    private function validityBasis(object $ata, int $number, int $amendmentId, string $end, int $version): array
    {
        $previous = json_decode($ata->validity_basis ?? '', true);
        $matches = ($previous['rule'] ?? null) === UpdateAtaValidity::RULE
            && $ata->INICIO_ATA === ($previous['start'] ?? null) && $ata->FINAL_ATA === ($previous['end'] ?? null);
        $sources = $matches ? ($previous['amendments'] ?? []) : [];
        $originalEnd = $ata->FINAL_ATA;
        if ($sources !== []) {
            if (array_key_exists('original_end', $previous)) {
                $originalEnd = $previous['original_end'];
            } else {
                try {
                    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $ata->INICIO_ATA ?? '');
                    if (! $start || $start->format('Y-m-d') !== $ata->INICIO_ATA) {
                        throw new InvalidArgumentException('Data inicial original indisponível.');
                    }
                    $originalEnd = AtaTerm::end($start, $previous['period'] ?? '')->format('Y-m-d');
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages(['period' => 'Revise o período original da ATA antes de gravar um novo aditivo.']);
                }
            }
        }

        $sources = array_values(array_filter($sources, fn ($source) => (int) ($source['legacy_id'] ?? 0) !== $amendmentId));

        return ['rule' => UpdateAtaValidity::RULE, 'data_ata' => $ata->DATA_ATA, 'start' => $ata->INICIO_ATA,
            'period' => $ata->PERIODO_ATA, 'end' => $end, 'original_end' => $originalEnd, 'applied_version' => $version,
            'amendments' => [...$sources, ['legacy_id' => $amendmentId, 'number' => $number, 'end' => $end]]];
    }
}
