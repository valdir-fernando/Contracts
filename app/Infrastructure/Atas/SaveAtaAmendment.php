<?php

namespace App\Infrastructure\Atas;

use App\Domain\Atas\AtaAnnualIndex;
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

    public const VARIABLE_QUANTITY = 'Prorrogação/Quantitativo - Período e Valor Variável';

    public const ANNUAL_INDEX = 'Prorrogação/Quantitativo/Reajuste Anual INDICE';

    public const APOSTILLE = 'Apostilamento/Reajuste Anual INDICE';

    public const ANNUAL_INDEX_TYPES = [self::ANNUAL_INDEX, self::APOSTILLE];

    public const ADVANTAGE_OPTIONS = ['Pesquisa de Preços', 'Declaração do Gestor'];

    public const QUANTITY_ENTRY_TYPES = [self::EXTENSION, self::VARIABLE_QUANTITY, ...self::ANNUAL_INDEX_TYPES];

    public const SUPPORTED_TYPES = [self::EXTENSION, self::EQUAL_QUANTITY, self::VARIABLE_QUANTITY, ...self::ANNUAL_INDEX_TYPES];

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
            $acceptsQuantities = in_array($input['type'] ?? null, self::QUANTITY_ENTRY_TYPES, true);
            $annualAdjustment = in_array($input['type'] ?? null, self::ANNUAL_INDEX_TYPES, true);
            $apostille = ($input['type'] ?? null) === self::APOSTILLE;
            $subject = $apostille ? 'apostilamento' : 'aditivo';
            $data = Validator::make($input, [
                'version' => 'required|integer|min:1',
                'instrument' => 'sometimes|required|in:apostille,amendment',
                'number' => 'required|integer|min:1|max:4294967295',
                'date' => 'required|date_format:Y-m-d',
                'process' => 'required|string|max:64',
                'type' => $editingNumber === null ? ['required', Rule::in(self::SUPPORTED_TYPES)] : 'required|string|max:100',
                'advantage' => [Rule::excludeIf(! in_array($input['type'] ?? null, self::ADVANTAGE_TYPES, true)),
                    ...($annualAdjustment && $editingNumber !== null ? ['sometimes'] : []), 'required', 'string', Rule::in(self::ADVANTAGE_OPTIONS)],
                'annual_index' => [Rule::excludeIf(! $annualAdjustment), ...($apostille && $editingNumber === null ? [] : ['sometimes']), 'required', 'string', 'max:64'],
                'start' => 'required|date_format:Y-m-d',
                'end' => 'required|date_format:Y-m-d|after_or_equal:start',
                'period' => 'required|string|max:1000',
                'excluded_product_ids' => [Rule::prohibitedIf(! $acceptsQuantities), 'sometimes', 'array', 'list'],
                'excluded_product_ids.*' => 'required|integer|min:1|distinct',
                'balance_quantities' => [Rule::prohibitedIf(! $acceptsQuantities), 'sometimes', 'array', 'list'],
                'balance_quantities.*' => 'required|array:product_id,quantity'.($annualAdjustment ? ',adjusted_unit_price' : ''),
                'balance_quantities.*.product_id' => 'required|integer|min:1|distinct',
                'balance_quantities.*.quantity' => 'required|string|max:32',
                'balance_quantities.*.adjusted_unit_price' => 'sometimes|nullable|string|max:32',
            ], ['type.in' => 'Este tipo de aditivo ainda não tem regras de lançamento configuradas.'], [
                'number' => $apostille ? 'Nº Apostilamento' : 'Nº Aditivo',
                'date' => $apostille ? 'Data_Apost.' : 'Data_ADT', 'process' => $apostille ? 'Proc_Apost.' : 'Proc_ADT',
                'type' => 'Tipo_ADT', 'start' => 'INICIO', 'end' => 'FINAL', 'period' => 'Período', 'advantage' => 'Vantajosidade', 'annual_index' => 'Indice_Anual',
                'balance_quantities.*.adjusted_unit_price' => 'Novo_VL_Unitário',
            ])->validate();
            abort_if((int) $ata->version !== (int) $data['version'], 409, 'A ATA foi alterada. Consulte novamente antes de salvar.');
            $requestedApostille = ($data['instrument'] ?? 'amendment') === 'apostille';
            if (($editingNumber !== null || isset($data['instrument'])) && $requestedApostille !== $apostille) {
                throw ValidationException::withMessages(['type' => 'Selecione o tipo correspondente ao instrumento para salvar.']);
            }
            if (! $apostille) {
                $maximumEnd = AtaTerm::maximumAmendmentEnd($ata->INICIO_ATA);
                if ($maximumEnd === null) {
                    throw ValidationException::withMessages(['end' => 'Informe a data inicial da vigência original da ATA antes de prorrogar.']);
                }
                if ($data['end'] > $maximumEnd->format('Y-m-d')) {
                    throw ValidationException::withMessages(['end' => 'A vigência total da ATA não pode ultrapassar 2 anos. Informe FINAL até '.$maximumEnd->format('d/m/Y').'.']);
                }
            }

            $number = (int) $data['number'];
            abort_if($editingNumber !== null && $editingNumber !== $number, 422, "O número do {$subject} não pode ser alterado.");
            $identity = new AtaAmendmentReader;
            $key = $identity->key($ata->ATA);
            $process = trim($ata->PROCESSO);
            abort_if($key === null || $process === '' || DB::table('tb_ATA')->where('ID', '!=', $ataId)
                ->whereRaw('TRIM(PROCESSO) = ?', [$process])->pluck('ATA')->contains(fn ($candidate) => $identity->key($candidate) === $key),
                409, "A identificação da ATA e do processo deve ser única para salvar o {$subject}.");
            $registered = DB::table('tb_ADT_ATA')->whereRaw('TRIM(Proc_ATA) = ?', [$process])
                ->where(fn ($query) => $query->where('ID_ATA', $ataId)->orWhere(fn ($legacy) => $legacy->whereNull('ID_ATA')->whereNull('source_path')))
                ->orderBy('ID')->lockForUpdate()
                ->get(['ID', 'ATA', 'ADT', 'Data_ADT', 'Proc_ADT', 'Tipo_ADT', 'INICIO', 'FINAL', 'Período', 'VANTAJOSIDADE', 'annual_index', 'balance_products', 'deleted_at'])
                ->filter(fn ($row) => $identity->key($row->ATA) === $key);
            $products = DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $ataId)->orderBy('ID')->lockForUpdate()
                ->get(['ID', 'ADITIVO', 'ID_ADITIVO', 'amendment_id', 'ITEM', 'COD_PROD', 'FICHA', 'DISCRIMINACAO', 'MARCA', 'UND', 'QTD', 'QTD_ESPECIAL', 'VL_UNIT', 'VL_TOTAL', 'deleted_at']);
            $registered = $registered->whereNull('deleted_at')->filter(fn ($row) => self::isApostille($row->Tipo_ADT) === $apostille);
            $products = $products->whereNull('deleted_at');
            $latestNumber = (int) max($registered->max('ADT') ?? 0, $apostille ? 0 : ($products->max('ADITIVO') ?? 0));
            $previous = null;
            $currentEnd = max($ata->FINAL_ATA, $registered->max('FINAL'));
            if ($editingNumber === null) {
                abort_if($apostille && $latestNumber === 0 && $number !== 1, 409, 'Informe 1 como número do primeiro apostilamento desta ATA.');
                abort_if($number <= $latestNumber, 409, "Informe um número maior que o dos {$subject}s ativos desta ATA.");
            } else {
                $current = $registered->where('ADT', $number);
                abort_if($current->isEmpty(), 404, ucfirst($subject).' ativo não encontrado nesta ATA.');
                abort_if($number !== $latestNumber || $current->count() !== 1,
                    409, "Só é possível editar o último {$subject} ativo, com identificação única.");
                $previous = $current->first();
                if (! $apostille) {
                    $basis = json_decode($ata->validity_basis ?? '', true);
                    abort_unless(($basis['rule'] ?? null) === UpdateAtaValidity::RULE
                        && $ata->INICIO_ATA === ($basis['start'] ?? null) && $ata->FINAL_ATA === ($basis['end'] ?? null)
                        && (in_array((int) $previous->ID, array_column($basis['amendments'] ?? [], 'legacy_id'), true)
                            || in_array((int) $previous->ID, array_column($basis['amendments'] ?? [], 'id'), true)),
                        409, 'Só é possível editar um aditivo com vigência conciliada.');
                    $originalEnd = array_key_exists('original_end', $basis) ? $basis['original_end'] : $this->originalEnd($ata, $basis);
                    $currentEnd = max($originalEnd, $registered->where('ADT', '<', $number)->max('FINAL'));
                }
            }
            if (! $apostille && (($currentEnd !== null && $data['end'] <= $currentEnd) || ($ata->INICIO_ATA !== null && $data['end'] < $ata->INICIO_ATA))) {
                throw ValidationException::withMessages(['end' => 'Para prorrogar a ATA, informe um FINAL posterior ao vencimento atual.']);
            }

            $previousType = trim($previous->Tipo_ADT ?? '');
            $preserveItems = $previous !== null && $previousType === $data['type'];
            $supportedType = in_array($data['type'], self::SUPPORTED_TYPES, true);
            if (! $supportedType && ! $preserveItems) {
                throw ValidationException::withMessages(['type' => 'Este tipo de aditivo ainda não tem regras de lançamento configuradas.']);
            }
            $previousIndex = $preserveItems ? $previous->annual_index : null;
            $annualIndex = $annualAdjustment ? AtaAnnualIndex::normalize($data['annual_index'] ?? $previousIndex) : null;
            $historicalAnnual = $annualAdjustment && ! $apostille && $preserveItems && $previousIndex === null && ! array_key_exists('annual_index', $data);
            if ($annualAdjustment && ! $historicalAnnual) {
                if ($annualIndex === null) {
                    throw ValidationException::withMessages(['annual_index' => 'Informe um percentual válido em Indice_Anual.']);
                }
                $data['advantage'] ??= $preserveItems ? $previous->VANTAJOSIDADE : null;
                if (! in_array($data['advantage'], self::ADVANTAGE_OPTIONS, true)) {
                    throw ValidationException::withMessages(['advantage' => "Selecione a Vantajosidade do {$subject}."]);
                }
            }
            if ($historicalAnnual && (! empty($data['balance_quantities']) || ! empty($data['excluded_product_ids']))) {
                throw ValidationException::withMessages(['annual_index' => 'Informe Indice_Anual para lançar quantidades ou exclusões.']);
            }
            $retainingHistoricalType = $preserveItems && (! $supportedType || $historicalAnnual);
            $previousItems = $apostille || $previous === null ? collect() : $products->where('ADITIVO', $number)
                ->filter(fn ($product) => $product->amendment_id === null || (int) $product->amendment_id === (int) $previous->ID);
            $variableQuantity = $data['type'] === self::VARIABLE_QUANTITY;
            $pricedQuantities = $variableQuantity || $annualAdjustment;
            $historicalVariableProducts = $previousType === self::VARIABLE_QUANTITY && $previous->balance_products === null && $previousItems->isNotEmpty();
            $historicalAnnualProducts = $previousType === self::ANNUAL_INDEX && $previous->annual_index === null && $previousItems->isNotEmpty();
            abort_if($historicalAnnualProducts && ! $historicalAnnual, 409,
                'Concilie os produtos já lançados neste aditivo antes de alterar a modalidade ou aplicar um índice anual.');
            abort_if($previousItems->isNotEmpty() && ! $preserveItems && ($historicalVariableProducts || ! in_array($previousType, self::SUPPORTED_TYPES, true)),
                409, 'Para editar este aditivo, mantenha o Tipo_ADT original. A troca de tipo exige conciliar os produtos e valores já registrados.');
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
            $record = ['ID_ATA' => $ataId, 'updated_at' => $now, 'edited_in_panel_at' => $now,
                'Data_ADT' => $data['date'], 'Proc_ADT' => $data['process'], 'Tipo_ADT' => $data['type'],
                'INICIO' => $data['start'], 'FINAL' => $data['end'], 'Período' => $data['period'],
                'VANTAJOSIDADE' => $data['advantage'] ?? null, 'annual_index' => $annualIndex,
            ];
            if ($retainingHistoricalType) {
                unset($record['Tipo_ADT'], $record['VANTAJOSIDADE']);
            }
            if ($previous === null) {
                $amendmentId = DB::table('tb_ADT_ATA')->insertGetId($record + ['ATA' => $ata->ATA, 'Proc_ATA' => $process, 'ADT' => $number, 'created_at' => $now], 'ID');
            } else {
                $amendmentId = (int) $previous->ID;
                DB::table('tb_ADT_ATA')->where('ID', $amendmentId)->update($record);
            }
            $excluded = $this->syncExcludedProducts($ataId, $amendmentId, $number,
                match (true) {
                    $retainingHistoricalType => null,
                    $acceptsQuantities => $data['excluded_product_ids'] ?? null,
                    default => [],
                }, $originals, $now);
            $previousBalance = $acceptsQuantities && $preserveItems
                ? json_decode($previous->balance_products ?? '[]', true, flags: JSON_THROW_ON_ERROR)
                : [];
            $balance = $acceptsQuantities
                ? $this->balanceProducts($historicalAnnual ? null : ($data['balance_quantities'] ?? null), $previousBalance, $excluded['after'], $originals, ! $pricedQuantities)
                : [];
            if ($annualAdjustment && isset($data['balance_quantities'])) {
                $balance = $this->applyAdjustedUnitPrices($balance, $data['balance_quantities']);
            }
            abort_if($historicalVariableProducts && ($balance !== [] || $excluded['after'] !== $excluded['before']), 409,
                'Concilie os produtos já lançados neste aditivo antes de alterar as quantidades ou exclusões.');
            $quantities = array_column($balance, 'balance_quantity', 'id');
            $previousQuantities = array_column($previousBalance, 'balance_quantity', 'id');
            ksort($quantities);
            ksort($previousQuantities);
            $adjustedPrices = array_column($balance, 'adjusted_unit_price', 'id');
            $previousAdjustedPrices = array_column($previousBalance, 'adjusted_unit_price', 'id');
            ksort($adjustedPrices);
            ksort($previousAdjustedPrices);
            $indexChanged = $annualIndex !== null && ($previousIndex === null || bccomp($annualIndex, $previousIndex, 64) !== 0);
            $replacePricedItems = $pricedQuantities && ! $historicalAnnual
                && (! $preserveItems || $quantities !== $previousQuantities || $indexChanged || $adjustedPrices !== $previousAdjustedPrices);
            $keepPreviousItems = $preserveItems && ! $replacePricedItems;
            if ($previousItems->isNotEmpty()) {
                $changes = $keepPreviousItems ? ['DATA_ADT' => $data['date'], 'PROC_ADT' => $data['process']] : ['deleted_at' => $now];
                DB::table('tb_PRODUTOS_ATA')->whereIn('ID', $previousItems->pluck('ID'))->update($changes + ['updated_at' => $now]);
            }
            if ($apostille || $replacePricedItems) {
                $items = $this->pricedItems($balance);
            }
            if ($apostille && $items->isEmpty()) {
                throw ValidationException::withMessages(['balance_quantities' => 'Informe a quantidade de pelo menos um item incluído no apostilamento.']);
            }
            $productTotal = $keepPreviousItems ? $this->sumProducts($previousItems) : '0.0000';
            $productIds = $keepPreviousItems ? $previousItems->pluck('ID')->all() : [];
            $apostillePrices = [];
            foreach ($items as $item) {
                $quantity = DecimalAmount::normalize((string) $item->QTD);
                $price = DecimalAmount::normalize((string) $item->VL_UNIT);
                if ($annualIndex !== null) {
                    $price = $item->adjusted_unit_price ?? ($price !== null && ! str_starts_with($price, '-')
                        ? AtaAnnualIndex::adjustedUnitPrice($price, $annualIndex) : $price);
                }
                $total = $quantity === null || $price === null ? null : DecimalAmount::normalize(bcmul($quantity, $price, $annualAdjustment ? 2 : 4));
                if ($total === null || bccomp($quantity, '0', 4) <= 0 || bccomp($price, '0', 4) < 0) {
                    $field = $pricedQuantities ? 'balance_quantities' : 'type';
                    throw ValidationException::withMessages([$field => "Revise a quantidade e o valor unitário do item {$item->ITEM} da ATA."]);
                }
                $productTotal = bcadd($productTotal, $total, 4);
                if ($apostille) {
                    $apostillePrices[$item->ID] = ['effective_unit_price' => $price, 'adjusted_total' => $total];

                    continue;
                }
                $productIds[] = DB::table('tb_PRODUTOS_ATA')->insertGetId([
                    'ID_ATA' => $ataId, 'ATA' => $ata->ATA, 'PROCESSO' => $ata->PROCESSO, 'LICITACAO' => $ata->LICITACAO, 'N_LICIT' => $ata->N_LICIT,
                    'ADITIVO' => $number, 'amendment_id' => $amendmentId, 'DATA_ADT' => $data['date'], 'PROC_ADT' => $data['process'],
                    'ITEM' => $item->ITEM, 'COD_PROD' => $item->COD_PROD, 'FICHA' => $item->FICHA, 'DISCRIMINACAO' => $item->DISCRIMINACAO,
                    'MARCA' => $item->MARCA, 'UND' => $item->UND, 'QTD' => $quantity, 'QTD_ESPECIAL' => $item->QTD_ESPECIAL,
                    'VL_UNIT' => $price, 'VL_TOTAL' => $total,
                    'source_row' => json_encode(['origin' => 'native_ata_amendment', 'amendment_id' => $amendmentId, 'original_product_id' => $item->ID], JSON_THROW_ON_ERROR),
                    'created_at' => $now, 'updated_at' => $now,
                ], 'ID');
            }
            if ($apostille) {
                $balance = array_map(fn (array $item) => array_replace(
                    array_diff_key($item, ['effective_unit_price' => true, 'adjusted_total' => true]),
                    $apostillePrices[$item['id']] ?? [],
                ), $balance);
            }
            if (! $retainingHistoricalType && ($balance !== [] || $previous?->balance_products !== null)) {
                DB::table('tb_ADT_ATA')->where('ID', $amendmentId)->update([
                    'balance_products' => $balance === [] ? null : json_encode($balance, JSON_THROW_ON_ERROR),
                ]);
            }

            $validity = [];
            $originalEndDate = [];
            if (! $apostille) {
                $basis = $this->validityBasis($ata, $number, $amendmentId, $data['end'], $version);
                $validity = ['FINAL_ATA' => $data['end'], 'validity_basis' => json_encode($basis, JSON_THROW_ON_ERROR)];
                $originalEndDate = ['original_end_date' => $basis['original_end']];
            }
            DB::table('tb_ATA')->where('ID', $ataId)->update($validity + ['version' => $version, 'updated_at' => $now]);
            $amendment = array_intersect_key($data, array_flip(['date', 'process', 'type', 'start', 'end', 'period', 'advantage']));
            if ($annualIndex !== null) {
                $amendment['annual_index'] = $annualIndex;
            }
            if ($retainingHistoricalType && $previous->VANTAJOSIDADE !== null) {
                $amendment['advantage'] = $previous->VANTAJOSIDADE;
            }
            if ($acceptsQuantities) {
                $amendment['excluded_product_ids'] = $excluded['after'];
                if ($balance !== []) {
                    $amendment['balance_products'] = $balance;
                }
            }
            $amount = $data['type'] === self::EXTENSION ? '0.0000' : $productTotal;
            $updatedTotal = bcadd($this->sumProducts($products->whereNotIn('ID', $previousItems->pluck('ID'))), $apostille ? '0.0000' : $productTotal, 4);
            $action = ($apostille ? 'ata_apostille_' : 'ata_amendment_').($previous === null ? 'saved' : 'updated');
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => $action, 'created_at' => $now,
                'metadata' => ['ata_id' => $ataId, 'amendment_id' => $amendmentId, 'amendment_number' => $number,
                    'amendment' => $amendment, 'before_amendment' => $previous, 'before_product_ids' => $previousItems->pluck('ID')->all(),
                    'before_excluded_product_ids' => $excluded['before'], 'excluded_product_ids' => $excluded['after'],
                    'product_ids' => $productIds, 'amount' => $amount, 'before_version' => (int) $ata->version, 'after_version' => $version,
                    ...($annualAdjustment ? ['updated_total' => $updatedTotal] : []),
                    'before_end' => $ata->FINAL_ATA, 'after_end' => $validity['FINAL_ATA'] ?? $ata->FINAL_ATA, 'before_validity_basis' => $ata->validity_basis]]);

            return ['id' => $ataId, 'number' => $ata->ATA, 'version' => $version, 'amendment_number' => $number,
                ...($actor->isAdmin() ? ['signed_amendment_id' => $amendmentId] : []),
                'amendment' => $amendment + ($pricedQuantities ? ['has_registered_products' => $apostille || $productIds !== []] : []),
                'amendment_amount' => $amount, 'amendment_updated_total' => $updatedTotal,
                ...$originalEndDate];
        }, 3);
    }

    public static function isApostille(?string $type): bool
    {
        return trim($type ?? '') === self::APOSTILLE;
    }

    private function balanceProducts(?array $requested, array $previous, array $excluded, Collection $originals, bool $limitToOriginal): array
    {
        $saved = collect($previous)->keyBy('id');
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
                throw ValidationException::withMessages(['balance_quantities' => 'Confira as quantidades dos produtos incluídos.']);
            }
            if ($old && $old['balance_quantity'] === $quantity) {
                $balance[] = $old;

                continue;
            }
            if (! $limitToOriginal && $item === null) {
                throw ValidationException::withMessages(['balance_quantities' => 'Informe quantidades apenas para produtos originais ativos desta ATA.']);
            }
            $originalQuantity = $item ? DecimalAmount::normalize((string) $item->QTD) : null;
            if ($limitToOriginal && ($originalQuantity === null || bccomp($quantity, $originalQuantity, 4) > 0)) {
                $label = $item?->ITEM ?? $id;
                throw ValidationException::withMessages(['balance_quantities' => "Informe apenas o saldo do item {$label}, até a quantidade original da ATA."]);
            }
            if (! $limitToOriginal && $old) {
                $balance[] = array_replace($old, ['balance_quantity' => $quantity]);

                continue;
            }
            $balance[] = ['id' => $id, 'item' => $item->ITEM, 'code' => $item->COD_PROD, 'description' => $item->DISCRIMINACAO,
                'brand' => $item->MARCA, 'unit' => $item->UND, 'quantity' => $originalQuantity, 'balance_quantity' => $quantity,
                ...($limitToOriginal ? [] : ['ficha' => $item->FICHA]),
                'special_quantity' => DecimalAmount::normalize((string) $item->QTD_ESPECIAL),
                'unit_price' => DecimalAmount::normalize((string) $item->VL_UNIT)];
        }

        return $balance;
    }

    private function applyAdjustedUnitPrices(array $balance, array $requested): array
    {
        $prices = array_column($requested, 'adjusted_unit_price', 'product_id');

        return array_map(static function (array $item) use ($prices) {
            $id = $item['id'];
            if (! array_key_exists($id, $prices)) {
                return $item;
            }
            if ($prices[$id] === null) {
                unset($item['adjusted_unit_price']);

                return $item;
            }
            $price = DecimalAmount::normalize($prices[$id]);
            if ($price === null || str_starts_with($price, '-')) {
                throw ValidationException::withMessages(['balance_quantities' => "Confira o Novo_VL_Unitário do item {$item['item']}."]);
            }
            $item['adjusted_unit_price'] = $price;

            return $item;
        }, $balance);
    }

    private function pricedItems(array $balance): Collection
    {
        return collect($balance)->reject(fn (array $item) => $item['balance_quantity'] === '0.0000')
            ->map(fn (array $item) => (object) ['ID' => $item['id'], 'ITEM' => $item['item'], 'COD_PROD' => $item['code'],
                'DISCRIMINACAO' => $item['description'], 'MARCA' => $item['brand'], 'UND' => $item['unit'], 'FICHA' => $item['ficha'] ?? null,
                'QTD_ESPECIAL' => $item['special_quantity'], 'QTD' => $item['balance_quantity'], 'VL_UNIT' => $item['unit_price'],
                'adjusted_unit_price' => $item['adjusted_unit_price'] ?? null]);
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

        $sources = array_values(array_filter($sources, fn ($source) => (int) ($source['legacy_id'] ?? $source['id'] ?? 0) !== $amendmentId));

        return ['rule' => UpdateAtaValidity::RULE, 'data_ata' => $ata->DATA_ATA, 'start' => $ata->INICIO_ATA,
            'period' => $ata->PERIODO_ATA, 'end' => $end, 'original_end' => $originalEnd, 'applied_version' => $version,
            'amendments' => [...$sources, ['legacy_id' => $amendmentId, 'number' => $number, 'end' => $end]]];
    }

    private function originalEnd(object $ata, array $basis): string
    {
        try {
            return AtaTerm::end(new DateTimeImmutable($ata->INICIO_ATA), $basis['period'] ?? $ata->PERIODO_ATA ?? '')->format('Y-m-d');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['period' => 'Revise o período original da ATA antes de editar o aditivo.']);
        }
    }
}
