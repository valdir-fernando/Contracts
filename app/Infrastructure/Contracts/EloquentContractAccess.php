<?php

namespace App\Infrastructure\Contracts;

use App\Application\Contracts\AuthorizedContract;
use App\Application\Contracts\Ports\ContractAccess;
use App\Application\Exceptions\AccessDenied;
use App\Application\Exceptions\RecordNotFound;
use App\Application\Security\ActorIdentity;
use App\Infrastructure\Persistence\TransactionGuard;
use App\Infrastructure\Security\LockActor;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class EloquentContractAccess implements ContractAccess
{
    public function __construct(private LockActor $actors) {}

    public function lockForSave(ActorIdentity $actor, ?int $contractId): AuthorizedContract
    {
        return $this->lock($actor, $contractId, $contractId === null ? 'create' : 'update');
    }

    public function lockForLifecycle(ActorIdentity $actor, int $contractId, bool $restore): AuthorizedContract
    {
        return $this->lock($actor, $contractId, $restore ? 'restore' : 'delete', true);
    }

    private function lock(ActorIdentity $expected, ?int $contractId, string $ability, bool $withDeleted = false): AuthorizedContract
    {
        $snapshot = new User;
        $snapshot->forceFill(['id' => $expected->id, 'session_version' => $expected->sessionVersion]);
        try {
            $actor = $this->actors->lock($snapshot);
            $query = $withDeleted ? Contract::withTrashed() : Contract::query();
            $contract = $contractId === null ? new Contract : $query->whereKey($contractId)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize($ability, $contractId === null ? Contract::class : $contract);
        } catch (ModelNotFoundException) {
            throw new RecordNotFound;
        } catch (AuthorizationException) {
            throw new AccessDenied;
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() !== 403) {
                throw $error;
            }
            throw new AccessDenied;
        }

        return new AuthorizedContract(new ActorIdentity($actor->id, $actor->session_version), $actor->isAdmin(), ContractMapper::toDomain($contract));
    }

    public function canAssign(AuthorizedContract $context, ?int $fundId, ?int $departmentId): bool
    {
        TransactionGuard::requireActive();

        return $context->isAdministrator || User::findOrFail($context->actor->id)->scopes()->where('fund_id', $fundId)
            ->where(function ($query) use ($departmentId) {
                $query->whereNull('department')->orWhere('department', '');
                if ($departmentId) {
                    $query->orWhere('department_id', $departmentId);
                }
            })->exists();
    }
}
