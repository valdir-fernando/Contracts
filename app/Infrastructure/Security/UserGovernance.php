<?php

namespace App\Infrastructure\Security;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class UserGovernance
{
    public static function lockAdministrators(): Collection
    {
        TransactionGuard::requireActive();

        return User::whereIn('user_type', [User::MASTER, 'Administrador'])->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
    }

    public static function protectLastAdministrator(User $before, User $after, Collection $administrators): void
    {
        if ($before->isMaster() && ! $after->isMaster() && $administrators->where('user_type', User::MASTER)->count() <= 1) {
            throw ValidationException::withMessages(['user_type' => 'O último Administrador Master ativo não pode ser desativado, excluído ou ter seu perfil alterado.']);
        }
        if ($before->isAdmin() && ! $after->isAdmin() && $administrators->count() <= 1) {
            throw ValidationException::withMessages(['user_type' => 'O último administrador ativo não pode ser desativado, excluído ou ter seu perfil alterado.']);
        }
    }
}
