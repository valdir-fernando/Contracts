<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function createUser(): User
    {
        return User::create(['name' => 'Pessoa de teste', 'username' => 'TESTE', 'email' => 'teste@example.com', 'password' => 'SenhaSegura!123']);
    }

    public function test_login_screen_is_public_and_dashboard_is_protected(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Contracts')->assertSee('name="_token"', false);
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_active_user_can_login_and_logout(): void
    {
        $user = $this->createUser();
        $this->post('/login', ['username' => $user->username, 'password' => 'SenhaSegura!123', 'remember' => '1'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk()->assertSee($user->name);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_wrong_password_is_rejected_and_not_flashed(): void
    {
        $user = $this->createUser();
        $this->from('/login')->post('/login', ['username' => $user->username, 'password' => 'incorreta'])
            ->assertRedirect('/login')->assertSessionHasErrors('username')->assertSessionMissing('_old_input.password');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = $this->createUser();
        $user->is_active = false;
        $user->save();
        $this->post('/login', ['username' => $user->username, 'password' => 'SenhaSegura!123'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['username' => 'TESTE', 'password' => 'incorreta'])->assertSessionHasErrors('username');
        }
        $user = $this->createUser();
        $this->post('/login', ['username' => $user->username, 'password' => 'SenhaSegura!123'])
            ->assertSessionHasErrors('username');
        $this->assertStringStartsWith('Muitas tentativas.', session('errors')->first('username'));
        $this->assertGuest();
    }

    public function test_failed_login_audit_does_not_leave_an_authenticated_session(): void
    {
        $user = $this->createUser();
        $this->failActivityWrites();
        $this->post('/login', ['username' => $user->username, 'password' => 'SenhaSegura!123', 'remember' => '1'])->assertStatus(500);
        $this->assertGuest();
        $this->assertNull($user->fresh()->last_login_at);
        $this->assertNull($user->fresh()->remember_token);
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_failed_logout_audit_still_terminates_the_session(): void
    {
        $user = $this->createUser();
        $this->post('/login', ['username' => $user->username, 'password' => 'SenhaSegura!123'])->assertRedirect('/dashboard');
        $this->failActivityWrites();
        $this->post('/logout')->assertStatus(500);
        $this->assertGuest();
        $this->assertFalse(session()->has('auth_version'));
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_remember_token_storage_failure_still_terminates_logout(): void
    {
        $user = $this->createUser();
        $this->post('/login', ['username' => $user->username, 'password' => 'SenhaSegura!123', 'remember' => '1'])->assertRedirect('/dashboard');
        Event::listen('eloquent.saving: '.User::class, function () {
            throw new \RuntimeException('Simulated remember token storage failure');
        });
        $this->post('/logout')->assertStatus(500);
        $this->assertGuest();
        $this->assertFalse(session()->has('auth_version'));
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_failed_attempts_are_rate_limited_even_when_activity_storage_fails(): void
    {
        $this->failActivityWrites();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['username' => 'TESTE', 'password' => 'incorreta'])->assertStatus(500);
        }
        $this->post('/login', ['username' => 'TESTE', 'password' => 'incorreta'])->assertSessionHasErrors('username');
        $this->assertStringStartsWith('Muitas tentativas.', session('errors')->first('username'));
        $this->assertGuest();
    }

    private function failActivityWrites(): void
    {
        Event::listen('eloquent.creating: '.ActivityEvent::class, function () {
            throw new \RuntimeException('Simulated activity storage failure');
        });
    }

    public function test_fields_are_validated(): void
    {
        $this->post('/login', ['username' => '', 'password' => ''])->assertSessionHasErrors(['username', 'password']);
        $this->assertGuest();
    }

    public function test_username_is_normalized(): void
    {
        $user = $this->createUser();
        $this->post('/login', ['username' => ' teste ', 'password' => 'SenhaSegura!123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_reference_users_are_imported_without_resetting_passwords(): void
    {
        $this->seedReferenceUsers();
        $this->assertDatabaseCount('users', 17);
        $this->assertDatabaseHas('users', ['reference_user_id' => 18, 'username' => 'JULIANA']);
        $this->assertDatabaseHas('users', ['reference_user_id' => 20, 'username' => 'JULIANA.MENDES']);
        $user = User::where('reference_user_id', 1)->firstOrFail();
        $this->assertNotSame('SenhaFicticia123', $user->password);
        $this->post('/login', ['username' => $user->username, 'password' => 'SenhaFicticia123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $user->password = 'SenhaAlterada!123';
        $user->save();
        $this->seedReferenceUsers();
        $this->assertDatabaseCount('users', 17);
        $this->assertTrue(Hash::check('SenhaAlterada!123', $user->fresh()->password));
    }
}
