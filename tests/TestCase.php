<?php

namespace Tests;

use App\Services\Legacy\Normalizer;
use Database\Seeders\ReferenceUsersSeeder;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    protected function seedContractInspector(int $fundId, ?int $departmentId = null): void
    {
        $fund = DB::table('contract_funds')->find($fundId);
        $department = $departmentId ? DB::table('contract_departments')->find($departmentId) : null;
        DB::table('contract_inspectors')->insert([
            'legacy_id' => (int) DB::table('contract_inspectors')->max('legacy_id') + 1,
            'name' => 'Fiscal fictício', 'name_key' => 'FISCAL FICTICIO',
            'fund_name_key' => Normalizer::name($fund->name),
            'department_name_key' => Normalizer::name($department?->name),
            'raw_payload' => json_encode(['Nome_Fiscal' => 'Fiscal fictício', 'Decreto' => '001/26']),
        ]);
    }

    protected function seedReferenceUsers(): void
    {
        // Synthetic data keeps the test suite independent of the private SQL dump.
        $rows = [];
        foreach ([...range(1, 15), 18, 20] as $id) {
            $nickname = match ($id) {
                1 => 'FERNANDO', 18, 20 => 'JULIANA', default => 'TESTE'.$id,
            };
            $role = $id === 1 ? 'Administrador' : 'Usuario';
            $rows[] = "($id, 'Pessoa ficticia $id', '$role', '$nickname', 'SenhaFicticia123', 'Fundo ficticio', 'Secretaria ficticia', 'teste@example.com')";
        }
        $file = tempnam(sys_get_temp_dir(), 'reference-users-test-');
        try {
            file_put_contents($file, "INSERT INTO `tb_Usuario` VALUES\n".implode(",\n", $rows).';');
            $this->app->call([new ReferenceUsersSeeder, 'run'], ['file' => $file]);
        } finally {
            unlink($file);
        }
    }
}
