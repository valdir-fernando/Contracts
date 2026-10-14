<?php

use App\Models\ContractAudit;
use App\Models\User;
use App\Services\ContractWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/bootstrap.php';

$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

try {
    $database = DB::connection()->getDatabaseName();
    if (getenv('CONTRACTS_ISOLATED_MYSQL_TESTS') !== '1' || ! $application->environment('testing')
        || ! in_array($database, ['contracts_validation', 'contracts_numbering_validation'], true)) {
        throw new RuntimeException('Isolated test schema required');
    }
    $arguments = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
    Carbon::setTestNow('2026-10-13 12:00:00');
    $actor = User::findOrFail($arguments['actor_id']);
    if ($arguments['fail_audit']) {
        ContractAudit::creating(fn () => throw new RuntimeException('Simulated audit failure'));
    }
    file_put_contents($arguments['directory'].'/ready-'.$actor->id, 'ready');
    $deadline = microtime(true) + 20;
    while (! is_file($arguments['directory'].'/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timeout');
        }
        usleep(10000);
    }
    $contract = $application->make(ContractWriter::class)->save($actor, [
        'fund_id' => $arguments['fund_id'], 'processo' => 'CONCURRENCY-'.$actor->id,
        'fornecedor_nome_snapshot' => 'Fornecedor sintético', 'fornecedor_documento_snapshot' => '11222333000181',
        'objeto' => 'Contrato de teste de concorrência', 'tipo' => 'Serviço', 'valor_total' => '100.0000',
        'data_contrato' => '2026-10-13', 'vigencia_inicio' => '2026-10-13', 'vigencia_fim_original' => '2027-10-13',
    ]);
    echo json_encode(['number' => $contract->numero, 'sequence' => $contract->number_sequence], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    // Never print database bindings or environment credentials from a subprocess.
    fwrite(STDERR, get_class($error));
    exit(2);
}
