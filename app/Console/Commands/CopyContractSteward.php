<?php

namespace App\Console\Commands;

use App\Infrastructure\ContractStewards\CopyFundManager;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class CopyContractSteward extends Command
{
    protected $signature = 'contracts:copy-contract-steward {name} {--fund= : Nome do Fundo autorizado para a cópia}';

    protected $description = 'Cadastra Gestor de Contratos a partir de um Gestor do Fundo, preservando a origem.';

    public function handle(CopyFundManager $copy): int
    {
        if (! $this->option('fund')) {
            $this->error('Informe o Fundo com --fund.');

            return self::FAILURE;
        }
        try {
            $result = $copy->copy($this->argument('name'), $this->option('fund'));
        } catch (ValidationException $error) {
            $this->error(implode(' ', $error->validator->errors()->all()));

            return self::FAILURE;
        }
        $this->info($result['created'] ? 'Gestor de Contratos cadastrado com auditoria.' : 'O Gestor de Contratos já está cadastrado. Nenhuma alteração aplicada.');

        return self::SUCCESS;
    }
}
