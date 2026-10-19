<?php

namespace App\Console\Commands;

use App\Infrastructure\Security\UserGovernance;
use App\Models\User;
use App\Models\UserAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PromoteMasterAdministrator extends Command
{
    protected $signature = 'contracts:promote-master {username}';

    protected $description = 'Promove uma conta ativa a Administrador Master, preservando senha e vínculos e registrando auditoria';

    public function handle(): int
    {
        $username = Str::upper(trim($this->argument('username')));
        $found = DB::transaction(function () use ($username) {
            UserGovernance::lockAdministrators();
            $user = User::where('username', $username)->lockForUpdate()->first();
            if (! $user || ! $user->is_active) {
                return false;
            }
            if ($user->isMaster()) {
                return true;
            }
            $before = $user->only(['username', 'user_type']);
            $user->user_type = User::MASTER;
            $user->session_version++;
            $user->remember_token = null;
            $user->save();
            UserAudit::create(['actor_id' => null, 'user_id' => $user->id, 'action' => 'updated', 'before' => $before,
                'after' => $user->only(['username', 'user_type']), 'reason' => 'Promoção explícita por CLI contracts:promote-master', 'created_at' => now()]);

            return true;
        });
        if (! $found) {
            $this->error('Conta ativa não encontrada.');

            return self::FAILURE;
        }
        $this->info('Conta configurada como Administrador Master. Entre novamente para atualizar a sessão.');

        return self::SUCCESS;
    }
}
