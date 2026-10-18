<?php

namespace Tests\Feature;

use App\Infrastructure\ContractStewards\ContractStewardRegistration;
use App\Infrastructure\Funds\FundRegistration;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ContractStewardRegistrationTest extends TestCase
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

    private function data(array $extra = []): array
    {
        return array_replace(['Nome_Gestor' => 'Gestor exemplo', 'CPF_Gestor' => '529.982.247-25', 'fund_id' => $this->fund,
            'Celular' => '(62) 99999-0000', 'Decreto' => '144/26', 'Data_Decreto' => '2026-01-05'], $extra);
    }

    public function test_form_registers_native_steward_and_audits_without_changing_contracts(): void
    {
        $this->get('/catalogs/list/contract-steward')->assertOk()->assertSee('Gestor de Contratos')->assertSee('Secretaria / Setor')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/contract-stewards', $this->data())->assertRedirect();
        $record = DB::table('contract_stewards')->first();
        $this->assertNull($record->legacy_id);
        $this->assertSame('GESTOR EXEMPLO', $record->name);
        $raw = json_decode($record->raw_payload, true);
        $this->assertSame('GESTOR EXEMPLO', $raw['Nome_Gestor']);
        $this->assertSame('Ativo', $raw['Situacao']);
        $this->assertSame('52998224725', $raw['CPF_Gestor']);
        $this->assertSame('(62) 99999-0000', $raw['Celular']);
        $this->assertDatabaseHas('activity_events', ['actor_id' => $this->admin->id, 'action' => 'contract_steward_created']);
        $this->assertDatabaseCount('contracts', 0);
        $this->get('/catalogs/list/contract-steward')->assertDontSee('52998224725');
        $this->get('/catalogs/list/contract-steward?edit='.$record->id)->assertOk()->assertSee('52998224725');
    }

    public function test_update_preserves_unknown_payload_and_rejects_stale_revision(): void
    {
        $id = DB::table('contract_stewards')->insertGetId(['legacy_id' => 99, 'name' => 'Antigo', 'name_key' => 'ANTIGO',
            'fund_name_key' => 'FUNDO SAUDE', 'department_name_key' => '',
            'raw_payload' => json_encode(['Email' => 'preservar@example.com', 'Cod_Prodata' => 123, 'Sexo' => 'F', 'Data_Nasc' => 'legado'])]);
        $revision = app(ContractStewardRegistration::class)->revision(DB::table('contract_stewards')->find($id));
        $this->put('/contract-stewards/'.$id, $this->data(['Nome_Gestor' => 'José da Conceição', 'revision' => $revision, 'Sexo' => 'F', 'Data_Nasc' => null]))->assertRedirect();
        $record = DB::table('contract_stewards')->find($id);
        $raw = json_decode($record->raw_payload, true);
        $this->assertSame('JOSÉ DA CONCEIÇÃO', $record->name);
        $this->assertSame('JOSÉ DA CONCEIÇÃO', $raw['Nome_Gestor']);
        $this->assertSame('preservar@example.com', $raw['Email']);
        $this->assertSame(123, $raw['Cod_Prodata']);
        $this->assertSame('legado', $raw['Data_Nasc']);
        $this->assertSame(99, $record->legacy_id);
        $this->put('/contract-stewards/'.$id, $this->data(['revision' => $revision]))->assertStatus(409);
        $this->assertDatabaseCount('activity_events', 1);
    }

    public function test_context_and_cpf_are_validated_and_non_admin_cannot_write(): void
    {
        $department = DB::table('contract_departments')->insertGetId(['name' => 'Educação', 'name_key' => 'EDUCACAO', 'raw_payload' => '{}']);
        $this->post('/contract-stewards', $this->data(['department_id' => $department]))->assertSessionHasErrors('department_id');
        $this->post('/contract-stewards', $this->data(['CPF_Gestor' => '11111111111']))->assertSessionHasErrors('CPF_Gestor');
        $this->assertDatabaseCount('contract_stewards', 0);
        $this->assertDatabaseCount('tb_Gestor_Cont', 0);
        $user = User::create(['name' => 'Usuário', 'username' => 'USER', 'password' => 'SenhaSegura123']);
        $this->actingAs($user)->post('/contract-stewards', $this->data())->assertForbidden();
        $this->put('/contract-stewards/1', $this->data())->assertForbidden();
        $this->get('/catalogs/list/contract-steward?edit=1')->assertForbidden();
    }

    public function test_status_is_saved_preserved_when_omitted_and_validated(): void
    {
        $this->post('/contract-stewards', $this->data(['Situacao' => 'Inativo']))->assertRedirect();
        $record = DB::table('contract_stewards')->first();
        $this->assertSame('Inativo', json_decode($record->raw_payload, true)['Situacao']);
        $this->get('/catalogs/list/contract-steward?edit='.$record->id)->assertOk()->assertSee('value="Inativo" selected', false);
        $revision = app(ContractStewardRegistration::class)->revision($record);
        $this->put('/contract-stewards/'.$record->id, $this->data(['revision' => $revision]))->assertRedirect();
        $record = DB::table('contract_stewards')->first();
        $this->assertSame('Inativo', json_decode($record->raw_payload, true)['Situacao']);
        $this->post('/contract-stewards', $this->data(['Situacao' => 'Inválido']))->assertSessionHasErrors('Situacao');
        $this->assertDatabaseCount('contract_stewards', 1);
    }

    public function test_delete_requires_confirmation_and_revision_and_preserves_the_record(): void
    {
        $this->post('/contract-stewards', $this->data())->assertRedirect();
        $record = DB::table('contract_stewards')->first();
        $revision = app(ContractStewardRegistration::class)->revision($record);
        $url = '/contract-stewards/'.$record->id;
        $this->delete($url, ['revision' => $revision])->assertSessionHasErrors('confirmation');
        $this->delete($url, ['revision' => 'stale', 'confirmation' => 1])->assertStatus(409);
        $this->assertNull(DB::table('contract_stewards')->find($record->id)->deleted_at);
        $this->delete($url, ['revision' => $revision, 'confirmation' => 1])->assertRedirect('/catalogs/list/contract-steward');
        $deleted = DB::table('contract_stewards')->find($record->id);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame($record->raw_payload, $deleted->raw_payload);
        $this->assertDatabaseHas('activity_events', ['action' => 'contract_steward_deleted', 'actor_id' => $this->admin->id]);
        $this->get('/catalogs/list/contract-steward')->assertDontSee('GESTOR EXEMPLO');
        $this->get('/catalogs/list/contract-steward?edit='.$record->id)->assertNotFound();
        $this->put($url, $this->data(['revision' => $revision]))->assertNotFound();
        $this->delete($url, ['revision' => $revision, 'confirmation' => 1])->assertNotFound();
    }

    public function test_delete_rechecks_admin_and_rolls_back_when_audit_fails(): void
    {
        $this->post('/contract-stewards', $this->data())->assertRedirect();
        $record = DB::table('contract_stewards')->first();
        $input = ['revision' => app(ContractStewardRegistration::class)->revision($record), 'confirmation' => 1];
        $user = User::create(['name' => 'Usuário', 'username' => 'USER', 'password' => 'SenhaSegura123']);
        $this->actingAs($user)->delete('/contract-stewards/'.$record->id, $input)->assertForbidden();
        ActivityEvent::creating(function () {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            app(ContractStewardRegistration::class)->delete($this->admin, $record->id, $input);
            $this->fail('Audit failure should abort deletion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertNull(DB::table('contract_stewards')->find($record->id)->deleted_at);
    }

    public function test_lookup_finds_masked_cpf_including_inactive_records_without_exposing_personal_payloads(): void
    {
        $this->post('/contract-stewards', $this->data(['Situacao' => 'Inativo']))->assertRedirect();
        $record = DB::table('contract_stewards')->first();
        DB::table('contract_stewards')->where('id', $record->id)->update(['raw_payload->CPF_Gestor' => ' 529.982.247-25 ']);
        $this->postJson('/contract-stewards/lookup', ['cpf' => '52998224725'])->assertOk()
            ->assertExactJson(['found' => true, 'edit_url' => route('catalogs.list', ['kind' => 'contract-steward', 'edit' => $record->id])])
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('GESTOR EXEMPLO')->assertDontSee('52998224725');
        DB::table('contract_stewards')->where('id', $record->id)->update(['deleted_at' => now()]);
        $this->postJson('/contract-stewards/lookup', ['cpf' => '529.982.247-25'])->assertOk()->assertExactJson(['found' => false, 'edit_url' => null]);
    }

    public function test_lookup_rejects_invalid_cpf_duplicates_and_non_administrators(): void
    {
        foreach (['11111111111', '52998224724', 'abc52998224725', ['52998224725']] as $cpf) {
            $this->postJson('/contract-stewards/lookup', ['cpf' => $cpf])->assertUnprocessable()->assertJsonValidationErrors('cpf');
        }
        $this->post('/contract-stewards', $this->data())->assertRedirect();
        // Simulate pre-existing inconsistent data to verify lookup remains safe.
        $duplicate = (array) DB::table('contract_stewards')->sole();
        unset($duplicate['id'], $duplicate['legacy_id']);
        DB::table('contract_stewards')->insert($duplicate);
        $this->postJson('/contract-stewards/lookup', ['cpf' => '52998224725'])->assertStatus(409)->assertDontSee('GESTOR EXEMPLO');
        $user = User::create(['name' => 'Usuário', 'username' => 'USER', 'password' => 'SenhaSegura123']);
        $this->actingAs($user)->postJson('/contract-stewards/lookup', ['cpf' => '52998224725'])->assertForbidden();
    }

    public function test_brazilian_legacy_dates_fill_the_form_and_save_dates_in_iso_format(): void
    {
        $id = DB::table('contract_stewards')->insertGetId(['legacy_id' => 99, 'name' => 'Gestor exemplo', 'name_key' => 'GESTOR EXEMPLO',
            'fund_name_key' => 'FUNDO SAUDE', 'department_name_key' => '',
            'raw_payload' => json_encode(['Data_Nasc' => '30/12/1971', 'Data_Decreto' => '29/02/2024'])]);
        $record = DB::table('contract_stewards')->find($id);
        $this->get('/catalogs/list/contract-steward?edit='.$id)->assertOk()
            ->assertSee('value="1971-12-30"', false)->assertSee('value="2024-02-29"', false)->assertDontSee('Valor legado:');
        $this->assertSame($record->raw_payload, DB::table('contract_stewards')->find($id)->raw_payload);
        $revision = app(ContractStewardRegistration::class)->revision($record);
        $this->put('/contract-stewards/'.$id, $this->data(['revision' => $revision, 'Data_Nasc' => '1971-12-30', 'Data_Decreto' => '2024-02-29']))->assertRedirect();
        $record = DB::table('contract_stewards')->find($id);
        $raw = json_decode($record->raw_payload, true);
        $this->assertSame('1971-12-30', $raw['Data_Nasc']);
        $this->assertSame('2024-02-29', $raw['Data_Decreto']);
        $this->put('/contract-stewards/'.$id, $this->data(['revision' => app(ContractStewardRegistration::class)->revision($record), 'Data_Nasc' => null]))->assertRedirect();
        $this->assertNull(json_decode(DB::table('contract_stewards')->find($id)->raw_payload, true)['Data_Nasc']);
    }

    public function test_impossible_brazilian_dates_are_not_silently_rolled_into_another_month(): void
    {
        $this->assertNull(ContractStewardRegistration::dateForForm('31/02/2026'));
        $this->assertNull(ContractStewardRegistration::dateForForm('29/02/2025'));
        $this->assertNull(ContractStewardRegistration::dateForForm('30/12/71'));
        $this->assertSame('1971-12-30', ContractStewardRegistration::dateForForm('1971-12-30'));
        $this->assertSame('1971-12-30', ContractStewardRegistration::dateForForm(' 30/12/1971 '));
    }

    public function test_registration_stores_iso_dates_and_rejects_brazilian_dates_sent_directly_to_the_server(): void
    {
        $this->post('/contract-stewards', $this->data(['Data_Nasc' => '1971-12-30']))->assertRedirect();
        $record = DB::table('contract_stewards')->first();
        $raw = json_decode($record->raw_payload, true);
        $this->assertSame('1971-12-30', $raw['Data_Nasc']);
        $this->assertSame('2026-01-05', $raw['Data_Decreto']);
        $this->post('/contract-stewards', $this->data(['Data_Nasc' => '30/12/1971', 'Data_Decreto' => '05/01/2026']))
            ->assertSessionHasErrors(['Data_Nasc', 'Data_Decreto']);
        $this->assertDatabaseCount('contract_stewards', 1);
    }

    public function test_audit_failure_rolls_back_registration(): void
    {
        ActivityEvent::creating(function () {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            app(ContractStewardRegistration::class)->save($this->admin, null, $this->data());
            $this->fail('Audit failure should abort the mutation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('contract_stewards', 0);
    }

    public function test_catalog_is_separate_from_inspectors_and_fund_managers_and_menu_has_requested_order(): void
    {
        DB::table('contract_inspectors')->insert(['name' => 'Fiscal separado', 'name_key' => 'FISCAL SEPARADO',
            'fund_name_key' => 'FUNDO SAUDE', 'department_name_key' => '', 'raw_payload' => '{"CPF_Fiscal":"52998224725"}']);
        DB::table('tb_Gestor')->insert(['name' => 'Gestor do Fundo separado', 'name_key' => 'GESTOR DO FUNDO SEPARADO',
            'fund_name_key' => 'FUNDO SAUDE', 'raw_payload' => '{"CPF_Gestor":"52998224725","Assinatura":1}']);
        $inspector = DB::table('contract_inspectors')->sole();
        $fundManager = DB::table('tb_Gestor')->sole();
        $url = '/catalogs/list/contract-steward';
        $this->get($url)->assertOk()->assertSee('Gestor de Contratos')
            ->assertDontSee('Fiscal separado')->assertDontSee('Gestor do Fundo separado')
            ->assertSeeInOrder(['<span>Fiscal_Contrato</span>', '<span>Gestor_Contrato</span>', '<span>Gestor_Fundo</span>'], false)
            ->assertDontSee('Assinar os contratos do Executivo');
        $this->postJson('/contract-stewards/lookup', ['cpf' => '52998224725'])
            ->assertExactJson(['found' => false, 'edit_url' => null]);
        $this->post('/contract-stewards', $this->data())->assertRedirect();
        $record = DB::table('contract_stewards')->sole();
        $revision = app(ContractStewardRegistration::class)->revision($record);
        $this->delete('/contract-stewards/'.$record->id, ['revision' => $revision, 'confirmation' => 1])->assertRedirect();
        $this->assertEquals($inspector, DB::table('contract_inspectors')->sole());
        $this->assertEquals($fundManager, DB::table('tb_Gestor')->sole());
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_fund_with_contract_steward_cannot_be_deleted_and_revoked_admin_is_rechecked(): void
    {
        $this->post('/contract-stewards', $this->data())->assertRedirect();
        $fund = DB::table('contract_funds')->find($this->fund);
        $registration = app(FundRegistration::class);
        $this->assertTrue($registration->linked($fund));
        $this->delete('/funds/'.$this->fund, ['revision' => $registration->revision($fund), 'confirmation' => 1])
            ->assertSessionHasErrors('confirmation');
        $this->assertNull(DB::table('contract_funds')->find($this->fund)->deleted_at);
        DB::table('users')->where('id', $this->admin->id)->update(['is_active' => false]);
        $this->expectException(HttpException::class);
        app(ContractStewardRegistration::class)->save($this->admin, null, $this->data());
    }
}
