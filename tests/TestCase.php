<?php

namespace Tests;

use Database\Seeders\ReferenceUsersSeeder;

abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
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
