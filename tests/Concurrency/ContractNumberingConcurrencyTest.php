<?php

namespace Tests\Concurrency;

use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ContractNumberingConcurrencyTest extends TestCase
{
    public function test_two_processes_share_one_counter_without_duplicates_and_audit_failure_does_not_consume_number(): void
    {
        if (config('database.default') !== 'mysql' || getenv('CONTRACTS_ISOLATED_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Requires explicitly isolated MySQL schema and independent processes.');
        }
        $database = DB::connection()->getDatabaseName();
        $this->assertTrue(app()->environment('testing'));
        $this->assertContains($database, ['contracts_validation', 'contracts_numbering_validation']);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        DB::table('suppliers')->insert(['name' => 'Fornecedor sintético', 'name_key' => 'FORNECEDOR SINTETICO', 'document' => '11222333000181', 'document_key' => '11222333000181', 'raw_payload' => '{}']);
        $actors = [];
        $token = bin2hex(random_bytes(8));
        foreach ([1, 2] as $index) {
            $actors[] = User::create(['name' => 'Concorrência '.$index, 'username' => 'CONCURRENT-'.$token.'-'.$index,
                'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']);
        }
        try {
            foreach ([[0, false, [1, 2]], [999, false, [1000, 1001]], [0, true, [1]]] as $scenario => [$last, $fail, $expected]) {
                $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo concorrência '.$token.'-'.$scenario,
                    'name_key' => 'CONCURRENT-'.$token.'-'.$scenario, 'raw_payload' => '{}']);
                if ($last) {
                    DB::table('tb_N_Contrato')->insert(['fund_id' => $fund, 'ANO' => 2026, 'FUNDO' => 'Teste', 'NUMERO' => $last]);
                }
                $directory = sys_get_temp_dir().'/contracts-numbering-barrier-'.bin2hex(random_bytes(12));
                mkdir($directory, 0700);
                $processes = [];
                $connection = DB::connection()->getConfig();
                $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'],
                    'DB_DATABASE' => $database, 'DB_USERNAME' => (string) $connection['username'], 'DB_PASSWORD' => (string) $connection['password'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'CONTRACTS_ISOLATED_MYSQL_TESTS' => '1'];
                try {
                    foreach ($actors as $index => $actor) {
                        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/numbering-worker.php'), json_encode([
                            'actor_id' => $actor->id, 'fund_id' => $fund, 'directory' => $directory, 'fail_audit' => $fail && $index === 0,
                        ], JSON_THROW_ON_ERROR)], base_path(), $environment, null, 30);
                        $process->start();
                        $processes[] = $process;
                    }
                    $deadline = microtime(true) + 20;
                    while (! is_file($directory.'/ready-'.$actors[0]->id) || ! is_file($directory.'/ready-'.$actors[1]->id)) {
                        $this->assertLessThan($deadline, microtime(true), 'Workers did not reach the synchronization barrier.');
                        usleep(20000);
                    }
                    file_put_contents($directory.'/go', 'go');
                    $sequences = [];
                    foreach ($processes as $index => $process) {
                        $exit = $process->wait();
                        $this->assertSame($fail && $index === 0 ? 2 : 0, $exit, $process->getErrorOutput());
                        if ($exit === 0) {
                            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                            $sequences[] = $result['sequence'];
                            $this->assertSame(str_pad((string) $result['sequence'], 3, '0', STR_PAD_LEFT).'/26', $result['number']);
                        }
                    }
                    sort($sequences);
                    $this->assertSame($expected, $sequences);
                    $this->assertSame(count($expected), Contract::where('fund_id', $fund)->count());
                    $this->assertSame(count($expected), ContractAudit::whereIn('contract_id', Contract::where('fund_id', $fund)->select('id'))->count());
                    $this->assertSame(max($expected), (int) DB::table('tb_N_Contrato')->where('fund_id', $fund)->where('ANO', 2026)->value('NUMERO'));
                } finally {
                    foreach ($processes as $process) {
                        if ($process->isRunning()) {
                            $process->stop();
                        }
                    }
                    foreach (['go', 'ready-'.$actors[0]->id, 'ready-'.$actors[1]->id] as $file) {
                        if (is_file($directory.'/'.$file)) {
                            unlink($directory.'/'.$file);
                        }
                    }
                    rmdir($directory);
                }
            }
        } finally {
            // This opt-in suite runs only in the disposable schema verified above.
            $this->assertSame($database, DB::connection()->getDatabaseName());
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }
    }
}
