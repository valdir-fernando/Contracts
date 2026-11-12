<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Managers\ManagerSignatory;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\User;
use App\Support\ContractFields;

final class AtaContractor
{
    public function __construct(private LockActor $actors, private SelectedFund $funds, private ManagerSignatory $managers) {}

    public function defaults(User $expected, int $fundId): array
    {
        $actor = $this->actors->current($expected);
        $values = $this->funds->defaults($actor, $fundId);
        abort_unless($values, 403);
        $fund = $this->funds->current($actor, $fundId);
        abort_unless($fund, 403);
        $manager = $this->managers->forFund($fund);
        $actor = $this->actors->current($expected);
        abort_unless($this->funds->allows($actor, $fundId), 403);
        $fields = ['decreto_gestor' => $manager['Decreto'] ?? null, 'matricula_gestor' => $manager['Matricula'] ?? null];
        foreach (ContractFields::MAP as $source => $definition) {
            if (in_array($source, ['CNPJ_Fundo', 'End_Fundo', 'CPF_Gestor', 'RG_Gestor', 'Cargo_Gestor', 'Nome_Gestor'])) {
                $column = $definition['column'];
                if ($actor->isAdmin() || ! in_array($column, ContractFields::PERSONAL)) {
                    $fields[$column] = $values[$column] ?? null;
                }
            }
        }

        return $fields;
    }
}
