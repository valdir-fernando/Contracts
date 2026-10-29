<?php

namespace App\Infrastructure\Dfd;

use App\Infrastructure\Security\CatalogAccess;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DfdAccess
{
    public function __construct(private LockActor $actors, private SelectedFund $funds) {}

    public function context(User $expected, ?int $fundId, bool $lock = false): array
    {
        $actor = $lock ? $this->actors->lock($expected) : $this->actors->current($expected);
        abort_unless($fundId && $this->funds->allows($actor, $fundId), 403, 'Selecione um Fundo autorizado no login.');
        $fund = DB::table('contract_funds')->where('id', $fundId)->whereNull('deleted_at')->when($lock, fn ($query) => $query->lockForUpdate())->first();
        abort_unless($fund, 403);

        return [$actor, $fund];
    }

    public function visible(User $actor, int $fundId): Builder
    {
        $query = DB::table('tb_DFD')->where('fund_id', $fundId)->whereNull('deleted_at');
        if (! $actor->isAdmin()) {
            $scopes = $actor->scopes()->where('fund_id', $fundId)->get()->filter(fn ($scope) => ! $scope->department || $scope->department_id);
            if (! $scopes->contains(fn ($scope) => ! $scope->department_id && ! $scope->department)) {
                $query->whereIn('department_id', $scopes->pluck('department_id')->filter());
            }
        }

        return $query;
    }

    public function record(User $actor, int $fundId, int $id, bool $lock = false): object
    {
        $record = $this->visible($actor, $fundId)->where('ID', $id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        abort_unless($record, 404);

        return $record;
    }

    public function version(object $record, array $input): void
    {
        validator($input, ['version' => 'required|integer|min:1'])->validate();
        if ((int) $input['version'] !== (int) $record->version) {
            throw new ConflictHttpException('Esta DFD foi alterada. Reabra o cadastro antes de continuar.');
        }
    }

    public function destination(User $actor, object $fund, ?int $departmentId): ?object
    {
        CatalogAccess::destination($actor, $fund->id, $departmentId);
        $department = $departmentId ? CatalogAccess::departments($actor)->where('id', $departmentId)->first() : null;
        abort_if($departmentId && ! $department, 422, 'Selecione uma área requisitante disponível.');

        return $department;
    }
}
