<?php

namespace App\Console\Commands;

use App\Models\AccessScope;
use App\Models\User;
use App\Services\ContractWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeedDemo extends Command
{
    protected $signature = 'contracts:demo';

    protected $description = 'Cria amostra fictícia local e salva acessos em storage/app/private/demo-access.json';

    public function handle(ContractWriter $writer): int
    {
        if (! app()->environment('local')) {
            $this->error('Demonstração disponível apenas em ambiente local.');

            return self::FAILURE;
        }
        $path = storage_path('app/private/demo-access.json');
        if (User::where('username', 'DEMO_ADMIN')->exists()) {
            $this->info('A demonstração já existe. Consulte storage/app/private/demo-access.json.');

            return self::SUCCESS;
        }
        $credentials = DB::transaction(function () use ($writer) {
            $adminPassword = Str::random(24).'A1';
            $userPassword = Str::random(24).'A1';
            $admin = User::create(['name' => 'Administrador de demonstração', 'username' => 'DEMO_ADMIN', 'password' => $adminPassword, 'user_type' => 'Administrador']);
            $regular = User::create(['name' => 'Usuário de demonstração', 'username' => 'DEMO_USER', 'password' => $userPassword]);
            $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo de demonstração', 'name_key' => 'FUNDO DE DEMONSTRACAO', 'document' => '11222333000181', 'document_key' => '11222333000181', 'raw_payload' => '{}']);
            $scope = AccessScope::create(['scope_key' => hash('sha256', 'demo:'.$fund), 'fund' => 'Fundo de demonstração', 'fund_id' => $fund]);
            $regular->scopes()->attach($scope);
            foreach ([['001/26', today()->subDays(60)->toDateString(), today()->addDays(20)->toDateString()], ['002/26', today()->subDays(100)->toDateString(), today()->subDays(1)->toDateString()]] as [$number, $start, $end]) {
                $writer->save($admin, ['numero' => $number, 'processo' => 'DEMO-'.$number, 'fund_id' => $fund,
                    'fornecedor_nome_snapshot' => 'Fornecedor fictício', 'fornecedor_documento_snapshot' => '11222333000181',
                    'objeto' => 'Serviço demonstrativo, sem vínculo com contratos reais.', 'tipo' => 'Serviço',
                    'data_contrato' => $start, 'vigencia_inicio' => $start, 'vigencia_fim_original' => $end,
                    'valor_total' => '1908.6300', 'administrative_status' => 'ativo']);
            }

            return ['admin' => ['username' => 'DEMO_ADMIN', 'password' => $adminPassword], 'user' => ['username' => 'DEMO_USER', 'password' => $userPassword]];
        });
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        file_put_contents($path, json_encode($credentials, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        $this->info('Demonstração criada. Acessos locais em storage/app/private/demo-access.json.');

        return self::SUCCESS;
    }
}
