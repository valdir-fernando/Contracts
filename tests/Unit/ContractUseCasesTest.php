<?php

namespace Tests\Unit;

use App\Application\Contracts\AuthorizedContract;
use App\Application\Contracts\ChangeContractLifecycle;
use App\Application\Contracts\Ports\ContractAccess;
use App\Application\Contracts\Ports\ContractCatalog;
use App\Application\Contracts\Ports\ContractCreationDate;
use App\Application\Contracts\Ports\ContractInputValidator;
use App\Application\Contracts\Ports\ContractNumbering;
use App\Application\Contracts\Ports\ContractStore;
use App\Application\Contracts\SaveContract;
use App\Application\Contracts\ValidatedContractInput;
use App\Application\Exceptions\AccessDenied;
use App\Application\Exceptions\ConcurrencyConflict;
use App\Application\Exceptions\InputRejected;
use App\Application\Ports\TransactionManager;
use App\Application\Security\ActorIdentity;
use App\Domain\Contracts\ContractNumber;
use App\Domain\Contracts\ContractState;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class ContractUseCasesTest extends TestCase
{
    public function test_creation_uses_trusted_date_before_transaction_and_ignores_client_date(): void
    {
        $ports = $this->ports();
        $ports->fields = ['data_cont_verd' => '1999-01-01'];
        $saved = $this->save($ports)->execute(new ActorIdentity(5, 0), ['fund_id' => 1, 'data_cont_verd' => '1999-01-01']);
        $this->assertSame('2026-10-22', $saved->get('data_cont_verd'));
        $this->assertSame(1, $ports->dateCalls);
    }

    public function test_edit_preserves_creation_date_and_never_requests_online_date(): void
    {
        $ports = $this->ports(4);
        $ports->state = $ports->state->with(['data_cont_verd' => '2025-03-04']);
        $ports->fields = ['data_cont_verd' => '1999-01-01'];
        $saved = $this->save($ports)->execute(new ActorIdentity(5, 0), ['version' => 3, 'reason' => 'Correção de teste'], 4);
        $this->assertSame('2025-03-04', $saved->get('data_cont_verd'));
        $this->assertSame(0, $ports->dateCalls);
    }

    private function ports(?int $id = null): InMemoryContractPorts
    {
        return new InMemoryContractPorts(new ContractState($id, [
            'numero' => '001/26', 'processo' => 'PROC', 'fund_id' => 1, 'department_id' => 2, 'ownership_resolved' => true,
            'fornecedor_documento_snapshot' => '11.222.333/0001-81', 'data_contrato' => '2026-10-01',
            'vigencia_inicio' => '2026-10-01', 'vigencia_fim_original' => '2027-10-01', 'version' => 3,
        ]));
    }

    private function save(InMemoryContractPorts $ports): SaveContract
    {
        return new SaveContract($ports, $ports, $ports, $ports, $ports, $ports, $ports);
    }

    public function test_creation_authorizes_and_validates_inside_transaction_then_audits_with_current_actor(): void
    {
        $ports = $this->ports();
        $ports->fields = ['objeto' => 'Novo objeto'];
        $this->assertSame(101, $this->save($ports)->execute(new ActorIdentity(5, 2), ['fund_id' => 1, 'created_by' => 999])->id);
        $this->assertSame(['begin', 'authorize:create', 'validate', 'destination', 'ownership', 'supplier', 'number', 'duplicate', 'save', 'commit'], $ports->events);
        $this->assertSame(5, $ports->saved->get('created_by'));
        $this->assertSame(5, $ports->saved->get('updated_by'));
        $this->assertSame('Fundo confiável', $ports->saved->get('fundo_nome_snapshot'));
        $this->assertSame(2026, $ports->saved->get('exercicio'));
        $this->assertSame(1, $ports->saved->version());
    }

    public function test_access_failure_does_not_validate_or_write_unavailable_records(): void
    {
        $ports = $this->ports(4);
        $ports->allowed = false;
        try {
            $this->save($ports)->execute(new ActorIdentity(5, 0), [], 4);
            $this->fail('A autorização deve falhar antes de acessar dados adicionais.');
        } catch (AccessDenied) {
            $this->assertSame(['begin', 'authorize:update', 'rollback'], $ports->events);
            $this->assertNull($ports->saved);
        }
    }

    public function test_only_validated_supplier_id_can_override_document_resolution(): void
    {
        $ports = $this->ports();
        $ports->fields = ['supplier_id' => 17];
        $saved = $this->save($ports)->execute(new ActorIdentity(5, 2), ['fund_id' => 1, 'supplier_id' => 999]);
        $this->assertSame(17, $saved->get('supplier_id'));
        $this->assertNotContains('supplier', $ports->events);
        $ports = $this->ports();
        $saved = $this->save($ports)->execute(new ActorIdentity(5, 2), ['fund_id' => 1, 'supplier_id' => 999]);
        $this->assertSame(10, $saved->get('supplier_id'));
    }

    public function test_destination_scope_is_checked_before_resolving_catalogs_or_persisting(): void
    {
        $ports = $this->ports(4);
        $ports->destinationAllowed = false;
        try {
            $this->save($ports)->execute(new ActorIdentity(5, 0), ['version' => 3, 'fund_id' => 99], 4);
            $this->fail('Não deve ser possível mover um contrato para outro escopo.');
        } catch (AccessDenied) {
            $this->assertSame(['begin', 'authorize:update', 'validate', 'destination', 'rollback'], $ports->events);
        }
    }

    public function test_stale_version_stops_before_destination_checks_and_persistence(): void
    {
        $ports = $this->ports(4);
        try {
            $this->save($ports)->execute(new ActorIdentity(5, 0), ['version' => 2], 4);
            $this->fail('A versão desatualizada deve ser rejeitada.');
        } catch (ConcurrencyConflict) {
            $this->assertSame(['begin', 'authorize:update', 'validate', 'rollback'], $ports->events);
            $this->assertNull($ports->saved);
        }
    }

    public function test_duplicate_requires_confirmation_and_rolls_back_without_saving(): void
    {
        $ports = $this->ports();
        $ports->duplicate = true;
        try {
            $this->save($ports)->execute(new ActorIdentity(5, 0), ['fund_id' => 1]);
            $this->fail('A duplicidade plausível precisa de confirmação explícita.');
        } catch (InputRejected $error) {
            $this->assertArrayHasKey('duplicate_confirmation', $error->errors);
            $this->assertNull($ports->saved);
            $this->assertSame('rollback', end($ports->events));
        }
        $this->save($ports)->execute(new ActorIdentity(5, 0), ['fund_id' => 1, 'duplicate_confirmation' => true]);
        $this->assertTrue($ports->duplicateConfirmed);
    }

    public function test_invalid_changed_date_is_rejected_before_supplier_or_duplicate_reads(): void
    {
        $ports = $this->ports(4);
        $ports->fields = ['vigencia_fim_original' => '2026-09-30'];
        try {
            $this->save($ports)->execute(new ActorIdentity(5, 0), ['version' => 3], 4);
            $this->fail('Cronologia inconsistente não pode ser introduzida.');
        } catch (InputRejected $error) {
            $this->assertArrayHasKey('vigencia_fim_original', $error->errors);
            $this->assertNotContains('supplier', $ports->events);
            $this->assertNull($ports->saved);
        }
    }

    public function test_edits_preserve_legacy_ownership_and_omitted_fields(): void
    {
        $ports = $this->ports(4);
        $ports->state = $ports->state->with(['fundo_nome_snapshot' => 'Nome histórico', 'dados_veiculo' => 'Preservar', 'quality_issues' => '[{"code":"historical"}]']);
        $ports->fields = ['objeto' => 'Corrigido'];
        $this->save($ports)->execute(new ActorIdentity(5, 0), ['version' => 3, 'reason' => 'Correção documentada'], 4);
        $this->assertNotContains('ownership', $ports->events);
        $this->assertNotContains('duplicate', $ports->events);
        $this->assertSame('Nome histórico', $ports->saved->get('fundo_nome_snapshot'));
        $this->assertSame('Preservar', $ports->saved->get('dados_veiculo'));
        $this->assertSame('[{"code":"historical"}]', $ports->saved->get('quality_issues'));
        $this->assertSame(4, $ports->saved->version());
    }

    public function test_persistence_or_audit_failure_rolls_back_the_whole_use_case(): void
    {
        $ports = $this->ports();
        $ports->failOnSave = true;
        try {
            $this->save($ports)->execute(new ActorIdentity(5, 0), ['fund_id' => 1]);
            $this->fail('Uma falha de persistência/auditoria deve reverter o caso de uso.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
            $this->assertNull($ports->saved);
            $this->assertFalse($ports->active);
            $this->assertSame('rollback', end($ports->events));
        }
    }

    public function test_delete_and_restore_use_locked_current_version_and_explicit_abilities(): void
    {
        $ports = $this->ports(4);
        $case = new ChangeContractLifecycle($ports, $ports, $ports, $ports);
        $case->delete(new ActorIdentity(5, 0), 4, ['version' => 3, 'reason' => 'Exclusão documentada']);
        $this->assertFalse($ports->restored);
        $this->assertSame(['begin', 'authorize:delete', 'validate:lifecycle', 'lifecycle', 'commit'], $ports->events);
        $case->restore(new ActorIdentity(5, 0), 4, ['version' => 3, 'reason' => 'Restauração documentada']);
        $this->assertTrue($ports->restored);
        try {
            $case->delete(new ActorIdentity(5, 0), 4, ['version' => 2, 'reason' => 'Versão desatualizada']);
            $this->fail('O ciclo de vida deve respeitar a versão bloqueada.');
        } catch (ConcurrencyConflict) {
            $this->assertSame('rollback', end($ports->events));
        }
    }
}

/** Test-only ports: no Laravel boot, ORM, facades, HTTP or database. */
final class InMemoryContractPorts implements ContractAccess, ContractCatalog, ContractCreationDate, ContractInputValidator, ContractNumbering, ContractStore, TransactionManager
{
    public int $dateCalls = 0;

    public function today(): string
    {
        if ($this->active) {
            throw new RuntimeException('Date lookup must precede the transaction.');
        }
        $this->dateCalls++;

        return '2026-10-22';
    }

    public array $events = [];

    public array $fields = [];

    public bool $active = false;

    public bool $allowed = true;

    public bool $destinationAllowed = true;

    public bool $duplicate = false;

    public bool $duplicateConfirmed = false;

    public bool $failOnSave = false;

    public int $lastNumber = 0;

    public ?ContractState $saved = null;

    public ?bool $restored = null;

    public function __construct(public ContractState $state) {}

    public function run(callable $operation): mixed
    {
        $before = $this->saved;
        $beforeNumber = $this->lastNumber;
        $this->events[] = 'begin';
        $this->active = true;
        try {
            $result = $operation();
            $this->events[] = 'commit';

            return $result;
        } catch (Throwable $error) {
            $this->saved = $before;
            $this->lastNumber = $beforeNumber;
            $this->events[] = 'rollback';
            throw $error;
        } finally {
            $this->active = false;
        }
    }

    public function lockForSave(ActorIdentity $actor, ?int $contractId): AuthorizedContract
    {
        return $this->lock($actor, $contractId === null ? 'create' : 'update');
    }

    public function lockForLifecycle(ActorIdentity $actor, int $contractId, bool $restore): AuthorizedContract
    {
        return $this->lock($actor, $restore ? 'restore' : 'delete');
    }

    private function lock(ActorIdentity $actor, string $ability): AuthorizedContract
    {
        if (! $this->active) {
            throw new RuntimeException('Missing transaction');
        }
        $this->events[] = 'authorize:'.$ability;
        if (! $this->allowed) {
            throw new AccessDenied;
        }

        return new AuthorizedContract($actor, true, $this->state);
    }

    public function canAssign(AuthorizedContract $context, ?int $fundId, ?int $departmentId): bool
    {
        $this->events[] = 'destination';

        return $this->destinationAllowed;
    }

    public function validate(AuthorizedContract $context, array $input): ValidatedContractInput
    {
        $this->events[] = 'validate';

        return new ValidatedContractInput($input, $this->fields);
    }

    public function validateLifecycle(AuthorizedContract $context, array $input): array
    {
        $this->events[] = 'validate:lifecycle';

        return $input;
    }

    public function ownership(int $fundId, ?int $departmentId): array
    {
        $this->events[] = 'ownership';

        return ['fund_id' => $fundId, 'department_id' => $departmentId, 'fundo_nome_snapshot' => 'Fundo confiável', 'ownership_resolved' => true];
    }

    public function supplierId(string $document): ?int
    {
        $this->events[] = 'supplier';

        return 10;
    }

    public function hasDuplicate(AuthorizedContract $context, ContractState $contract): bool
    {
        $this->events[] = 'duplicate';

        return $this->duplicate;
    }

    public function nextNumber(AuthorizedContract $context, int $fundId): ContractNumber
    {
        if (! $this->active) {
            throw new RuntimeException('Missing transaction');
        }
        $this->events[] = 'number';

        return new ContractNumber(++$this->lastNumber, 2026);
    }

    public function save(AuthorizedContract $context, ContractState $contract, ?string $reason, bool $duplicateConfirmed): ContractState
    {
        if (! $this->active) {
            throw new RuntimeException('Missing transaction');
        }
        $this->events[] = 'save';
        $this->saved = $contract;
        $this->duplicateConfirmed = $duplicateConfirmed;
        if ($this->failOnSave) {
            throw new RuntimeException('Audit unavailable');
        }

        return new ContractState($contract->id ?? 101, $contract->attributes());
    }

    public function changeLifecycle(AuthorizedContract $context, bool $restore, string $reason): void
    {
        $this->events[] = 'lifecycle';
        $this->restored = $restore;
    }
}
