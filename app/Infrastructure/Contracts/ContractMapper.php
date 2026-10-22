<?php

namespace App\Infrastructure\Contracts;

use App\Domain\Contracts\ContractState;
use App\Models\Contract;
use App\Support\ContractFields;

final class ContractMapper
{
    public static function toDomain(Contract $model): ContractState
    {
        $original = [];
        foreach ($model->getAttributes() as $field => $value) {
            $original[$field === 'Contrato' ? 'numero' : $field] = $value;
        }
        $attributes = $original;
        foreach (ContractFields::MAP as $definition) {
            if ($definition['type'] === 'money' && array_key_exists($definition['column'], $original)) {
                // SQLite may return numeric columns as floats; domain money is decimal text.
                $attributes[$definition['column']] = $model->{$definition['column']};
            }
        }

        return new ContractState($model->exists ? (int) $model->id : null, $attributes, $original);
    }

    public static function toModel(ContractState $contract, bool $recentlyCreated = false): Contract
    {
        $model = new Contract;
        $original = [];
        foreach ($contract->original() as $field => $value) {
            $original[$field === 'numero' ? 'Contrato' : $field] = $value;
        }
        $model->setRawAttributes($original, true);
        $model->exists = $contract->id !== null;
        $model->wasRecentlyCreated = $recentlyCreated;
        $changes = [];
        foreach ($contract->attributes() as $field => $value) {
            if (! array_key_exists($field, $contract->original()) || $contract->original()[$field] !== $value) {
                $changes[$field] = $value;
            }
        }
        $model->fill($changes);

        return $model;
    }
}
