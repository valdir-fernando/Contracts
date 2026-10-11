<?php

namespace App\Console\Commands;

use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateAdministrator extends Command
{
    protected $signature = 'contracts:create-admin {username} {--name=Administrador}';

    protected $description = 'Cria administrador inicial com senha informada de forma oculta';

    public function handle(): int
    {
        $username = Str::upper(trim($this->argument('username')));
        $password = $this->secret('Senha (mínimo 12 caracteres, letras e números)');
        $confirmation = $this->secret('Confirme a senha');
        $validator = Validator::make(['username' => $username, 'name' => $this->option('name'), 'password' => $password, 'password_confirmation' => $confirmation],
            ['username' => 'required|string|max:255|regex:/^[A-Z0-9._-]+$/|unique:users', 'name' => 'required|string|max:255',
                'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers(), 'max:1024']]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($username, $password) {
            $user = User::create(['name' => $this->option('name'), 'username' => $username, 'password' => $password, 'user_type' => 'Administrador']);
            ActivityEvent::create(['actor_id' => $user->id, 'action' => 'admin_provisioned', 'metadata' => [], 'created_at' => now()]);
        });
        $this->info('Administrador criado.');

        return self::SUCCESS;
    }
}
