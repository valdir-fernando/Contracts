<?php

namespace Tests\Feature;

use App\Infrastructure\Managers\ManagerRegistration;
use App\Infrastructure\Security\SelectedFund;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ManagerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $fund;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaSegura123', 'user_type' => 'Administrador']);
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo Saúde', 'name_key' => 'FUNDO SAUDE', 'raw_payload' => '{}']);
        $this->actingAs($this->admin);
    }

    private function input(): array
    {
        return ['fund_id' => $this->fund, 'Nome_Gestor' => 'Gestora exemplo', 'CPF_Gestor' => '529.982.247-25',
            'RG_Gestor' => 'RG privado', 'Data_Nasc' => '2001-11-30', 'Sexo' => 'Feminino',
            'Endereco' => 'Endereço privado', 'Cargo' => 'Gestora do Fundo', 'Cargo_Abreviado' => 'Gestora',
            'Fone_Celular' => '(62) 99999-9999', 'WhatsApp' => '(62) 99999-9999', 'Email' => 'gestora@example.com',
            'Decreto' => '103/2026', 'Data_Decreto' => '2026-04-01', 'Assinatura' => '0', 'Matricula' => '158370'];
    }

    private function revision(int $id): string
    {
        return app(ManagerRegistration::class)->revision(DB::table('tb_Gestor')->find($id));
    }

    public function test_create_edit_and_delete_preserve_history_and_private_projection(): void
    {
        $this->post('/managers', $this->input())->assertRedirect();
        $record = DB::table('tb_Gestor')->sole();
        $this->assertNull($record->legacy_id);
        $this->assertSame('GESTORA EXEMPLO', $record->name);
        $raw = json_decode($record->raw_payload, true);
        $this->assertSame('52998224725', $raw['CPF_Gestor']);
        $this->assertSame('Gestora', $raw['Cargo_Abreviado']);
        $this->assertSame('158370', $raw['Matricula']);
        $this->assertEquals(0, $raw['Assinatura']);
        $this->get('/catalogs/list/manager-fund')->assertOk()->assertSee('GESTORA EXEMPLO')->assertDontSee('gestora@example.com')->assertDontSee('52998224725');
        $this->get('/catalogs/list/manager-fund?edit='.$record->id)->assertOk()->assertSee('gestora@example.com')->assertSee('2001-11-30');
        $raw['Extra_Legado'] = 'preservado';
        $raw['Data_Nasc'] = 'data desconhecida';
        DB::table('tb_Gestor')->where('id', $record->id)->update(['raw_payload' => json_encode($raw)]);
        $revision = $this->revision($record->id);
        $this->put('/managers/'.$record->id, ['fund_id' => $this->fund, 'Nome_Gestor' => 'Novo nome', 'CPF_Gestor' => '52998224725', 'Data_Nasc' => null, 'Email' => null, 'revision' => $revision])->assertRedirect();
        $updated = json_decode(DB::table('tb_Gestor')->find($record->id)->raw_payload, true);
        $this->assertSame('preservado', $updated['Extra_Legado']);
        $this->assertSame('data desconhecida', $updated['Data_Nasc']);
        $this->assertSame('Gestora do Fundo', $updated['Cargo']);
        $this->assertNull($updated['Email']);
        $this->put('/managers/'.$record->id, $this->input() + ['revision' => $revision])->assertConflict();
        $this->delete('/managers/'.$record->id, ['revision' => $this->revision($record->id)])->assertSessionHasErrors('confirmation');
        $this->delete('/managers/'.$record->id, ['revision' => $this->revision($record->id), 'confirmation' => 1])->assertRedirect();
        $this->assertNotNull(DB::table('tb_Gestor')->find($record->id)->deleted_at);
        $this->assertNull(app(SelectedFund::class)->defaults($this->admin, $this->fund)['nome_gestor']);
        $this->get('/catalogs/list/manager-fund?edit='.$record->id)->assertNotFound();
        foreach (['manager_created', 'manager_updated', 'manager_deleted'] as $action) {
            $this->assertDatabaseHas('activity_events', ['action' => $action, 'actor_id' => $this->admin->id]);
        }
    }

    public function test_validation_and_duplicate_cpf(): void
    {
        foreach (['abc52998224725', '52998224724', '11222333000181'] as $invalidCpf) {
            $this->post('/managers', array_replace($this->input(), ['CPF_Gestor' => $invalidCpf]))->assertSessionHasErrors('CPF_Gestor');
        }
        $this->post('/managers', array_replace($this->input(), ['CPF_Gestor' => '11111111111', 'Email' => 'invalid']))->assertSessionHasErrors(['Email']);
        $this->post('/managers', array_replace($this->input(), ['CPF_Gestor' => '11111111111']))->assertSessionHasErrors('CPF_Gestor');
        $this->post('/managers', $this->input())->assertRedirect();
        $this->post('/managers', $this->input())->assertSessionHasErrors('CPF_Gestor');
        $this->assertDatabaseCount('tb_Gestor', 1);
        DB::table('contract_funds')->where('id', $this->fund)->update(['deleted_at' => now()]);
        $this->post('/managers', $this->input())->assertStatus(422);
    }

    public function test_cpf_is_required_for_creation_and_editing_without_mutation_or_audit_on_failure(): void
    {
        foreach ([[], ['CPF_Gestor' => null], ['CPF_Gestor' => ''], ['CPF_Gestor' => '   ']] as $cpf) {
            $input = array_diff_key($this->input(), ['CPF_Gestor' => true]);
            $this->post('/managers', $cpf + $input)->assertSessionHasErrors('CPF_Gestor');
        }
        $this->assertDatabaseCount('tb_Gestor', 0);
        $this->assertDatabaseCount('activity_events', 0);
        $this->post('/managers', $this->input())->assertRedirect()->assertSessionHasNoErrors();
        $record = DB::table('tb_Gestor')->sole();
        foreach ([[], ['CPF_Gestor' => null], ['CPF_Gestor' => '']] as $cpf) {
            $input = array_diff_key($this->input(), ['CPF_Gestor' => true]);
            $this->put('/managers/'.$record->id, $cpf + $input + ['revision' => $this->revision($record->id)])
                ->assertSessionHasErrors('CPF_Gestor');
        }
        $this->assertSame($record->raw_payload, DB::table('tb_Gestor')->find($record->id)->raw_payload);
        $this->assertDatabaseCount('activity_events', 1);
    }

    public function test_non_admin_cannot_read_or_mutate_and_revoked_actor_is_rechecked(): void
    {
        $this->post('/managers', $this->input())->assertRedirect();
        $id = DB::table('tb_Gestor')->value('id');
        $expected = $this->admin;
        $revision = $this->revision($id);
        $user = User::create(['name' => 'User', 'username' => 'USER', 'password' => 'SenhaSegura123']);
        $this->actingAs($user);
        $this->get('/catalogs/list/manager-fund?edit='.$id)->assertForbidden();
        $this->post('/managers', $this->input())->assertForbidden();
        $this->put('/managers/'.$id, $this->input() + ['revision' => $revision])->assertForbidden();
        $this->delete('/managers/'.$id, ['revision' => $revision, 'confirmation' => 1])->assertForbidden();
        DB::table('users')->where('id', $expected->id)->update(['user_type' => $user->user_type]);
        $this->expectException(HttpException::class);
        app(ManagerRegistration::class)->save($expected, $id, $this->input() + ['revision' => $revision]);
    }

    public function test_audit_failure_rolls_back_mutation(): void
    {
        Event::listen('eloquent.creating: '.ActivityEvent::class, function () {
            throw new \RuntimeException('Simulated audit failure');
        });
        $this->post('/managers', $this->input())->assertStatus(500);
        $this->assertDatabaseCount('tb_Gestor', 0);
    }
}
