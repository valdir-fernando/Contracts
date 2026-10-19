<?php

namespace Database\Seeders;

use App\Models\AccessScope;
use App\Models\User;
use App\Models\UserAudit;
use App\Services\Legacy\Normalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ReferenceUsersSeeder extends Seeder
{
    public function run(?string $file = null, bool $preserveExistingUsernames = false): void
    {
        $sql = file_get_contents($file ?? base_path('database-example/u910323952_bdgestao_niq.sql'));
        if (! preg_match('/INSERT INTO `tb_Usuario`[^;]+;/s', $sql, $insert)) {
            throw new RuntimeException('Tabela de usuários não encontrada na referência.');
        }
        preg_match_all('/^\((\d+), (.+)\)[,;]/m', $insert[0], $rows, PREG_SET_ORDER);
        if (count($rows) !== 17) {
            throw new RuntimeException('A referência deve conter 17 usuários.');
        }
        DB::transaction(function () use ($rows, $preserveExistingUsernames) {
            foreach ($rows as $row) {
                preg_match_all("/'((?:\\\\.|[^'\\\\])*)'/s", $row[2], $matches);
                if (count($matches[1]) !== 7) {
                    throw new RuntimeException('Registro de usuário inválido: '.$row[1]);
                }
                [$name, $type, $nickname, $password, $fund, $department] = array_map(
                    fn ($value) => str_replace(["\\'", '\\\\'], ["'", '\\'], $value), $matches[1]
                );
                $username = (int) $row[1] === 20 ? 'JULIANA.MENDES' : Str::upper(trim($nickname));
                if (User::withTrashed()->where('reference_user_id', (int) $row[1])->exists()) {
                    continue;
                }
                if (User::withTrashed()->where('username', $username)->exists()) {
                    if ($preserveExistingUsernames) {
                        continue;
                    }
                    throw new RuntimeException('Nome de usuário já cadastrado: '.$username);
                }
                $user = User::create([
                    'reference_user_id' => (int) $row[1], 'name' => $name,
                    'username' => $username, 'password' => $password,
                    'user_type' => $type, 'fund' => $fund, 'department' => $department,
                ]);
                if (trim($fund)) {
                    $funds = DB::table('contract_funds')->whereNull('deleted_at')->get()->filter(fn ($record) => Normalizer::name($record->name) === Normalizer::name($fund));
                    $departments = DB::table('contract_departments')->get()->filter(fn ($record) => Normalizer::name($record->name) === Normalizer::name($department));
                    $scope = AccessScope::firstOrCreate(['scope_key' => hash('sha256', trim($fund).'|'.trim($department))],
                        ['fund' => trim($fund), 'department' => trim($department) ?: null,
                            'fund_id' => $funds->count() === 1 ? $funds->first()->id : null,
                            'department_id' => trim($department) && $departments->count() === 1 ? $departments->first()->id : null]);
                    $user->scopes()->attach($scope);
                }
                UserAudit::create(['actor_id' => null, 'user_id' => $user->id, 'action' => 'created', 'before' => null,
                    'after' => $user->only(['name', 'username', 'user_type', 'fund', 'department', 'reference_user_id'])
                        + ['scope_ids' => $user->scopes()->pluck('access_scopes.id')->all()],
                    'reason' => 'Importação da referência tb_Usuario; ID de origem '.$row[1], 'created_at' => now()]);
            }
        });
        $this->command?->info('17 usuários de referência cadastrados ou já existentes.');
    }
}
