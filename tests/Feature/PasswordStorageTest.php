<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_passwords_are_stored_as_salted_hashes_and_hidden_from_serialization(): void
    {
        $password = 'SenhaFicticia!12345';
        $first = User::create(['name' => 'Primeiro', 'username' => 'FIRST', 'password' => $password]);
        $second = User::create(['name' => 'Segundo', 'username' => 'SECOND', 'password' => $password]);
        $stored = DB::table('users')->where('id', $first->id)->value('password');
        $other = DB::table('users')->where('id', $second->id)->value('password');

        $this->assertNotSame($password, $stored);
        $this->assertNotSame($stored, $other);
        $this->assertTrue(Hash::check($password, $stored));
        $this->assertTrue(Hash::check($password, $other));
        $this->assertFalse(Hash::check('SenhaIncorreta!123', $stored));
        $this->assertArrayNotHasKey('password', $first->fresh()->toArray());
        $this->assertStringNotContainsString($stored, $first->fresh()->toJson());
    }

    public function test_changing_password_persists_only_the_new_hash(): void
    {
        $user = User::create(['name' => 'Teste', 'username' => 'TEST', 'password' => 'SenhaAnterior!123']);
        $user->password = 'NovaSenhaFicticia!456';
        $user->save();
        $stored = DB::table('users')->where('id', $user->id)->value('password');

        $this->assertNotSame('NovaSenhaFicticia!456', $stored);
        $this->assertTrue(Hash::check('NovaSenhaFicticia!456', $stored));
        $this->assertFalse(Hash::check('SenhaAnterior!123', $stored));
    }
}
