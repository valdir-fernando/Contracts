<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Managers\ManagerSignatory;
use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\SelectedFund;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Validation\ValidationException;

final class AtaManagingFundSnapshot
{
    public function __construct(private SelectedFund $funds, private ManagerSignatory $managers) {}

    public function capture(User $actor, object $fund): array
    {
        TransactionGuard::requireActive();
        $manager = $this->managers->forFund($fund);
        $defaults = $this->funds->defaults($actor, $fund->id, forPersistence: true);
        $values = [
            'CNPJ_FUNDO' => Normalizer::document($fund->document), 'FUNDO' => $fund->name,
            'END_FUNDO' => $defaults['end_fundo'] ?? null, 'GESTOR' => $manager['Nome_Gestor'] ?? null,
            'CPF_GESTOR' => $defaults['cpf_gestor'] ?? null, 'RG_GESTOR' => $manager['RG_Gestor'] ?? null,
            'CARGO_GESTOR' => $manager['Cargo'] ?? null, 'DECRETO_GESTOR' => $manager['Decreto'] ?? null,
            'MATRICULA_GESTOR' => $manager['Matricula'] ?? null,
        ];
        $errors = [];
        foreach (['CNPJ_FUNDO' => 'CNPJ do Fundo', 'FUNDO' => 'Fundo Gerenciador', 'END_FUNDO' => 'Endereço do Fundo',
            'GESTOR' => 'Nome do Gestor', 'CPF_GESTOR' => 'CPF do Gestor', 'RG_GESTOR' => 'RG do Gestor',
            'CARGO_GESTOR' => 'Cargo do Gestor', 'DECRETO_GESTOR' => 'Nº Decreto', 'MATRICULA_GESTOR' => 'Matrícula Funcional'] as $column => $label) {
            if (! is_scalar($values[$column]) || trim((string) $values[$column]) === '') {
                $errors[$column] = 'Dados Gerenciador: preencha '.$label.' no cadastro.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if (strlen($values['CNPJ_FUNDO']) !== 14 || ! Normalizer::validDocument($values['CNPJ_FUNDO'])) {
            throw ValidationException::withMessages(['managing_fund_id' => 'Dados Gerenciador: informe um CNPJ válido no cadastro do Fundo.']);
        }

        return $values;
    }
}
