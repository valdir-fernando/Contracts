<?php

namespace Tests\Feature;

use App\Infrastructure\Inspectors\InspectorRegistration;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class InspectorRegistrationTest extends TestCase
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
        return array_replace(['Nome_Fiscal' => 'Fiscal exemplo', 'CPF_Fiscal' => '529.982.247-25', 'fund_id' => $this->fund,
            'Celular' => '(62) 99999-0000', 'Decreto' => '144/26', 'Data_Decreto' => '2026-01-05'], $extra);
    }

    public function test_form_registers_native_inspector_and_audits_without_changing_contracts(): void
    {
        $this->get('/catalogs/list/inspector')->assertOk()->assertSee('Fiscal de Contratos')->assertSee('Secretaria / Setor')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/inspectors', $this->data())->assertRedirect();
        $record = DB::table('contract_inspectors')->first();
        $this->assertNull($record->legacy_id);
        $this->assertSame('FISCAL EXEMPLO', $record->name);
        $raw = json_decode($record->raw_payload, true);
        $this->assertSame('FISCAL EXEMPLO', $raw['Nome_Fiscal']);
        $this->assertSame('Ativo', $raw['Situacao']);
        $this->assertSame('52998224725', $raw['CPF_Fiscal']);
        $this->assertSame('(62) 99999-0000', $raw['Celular']);
        $this->assertDatabaseHas('activity_events', ['actor_id' => $this->admin->id, 'action' => 'inspector_created']);
        $this->assertDatabaseCount('contracts', 0);
        $this->get('/catalogs/list/inspector')->assertDontSee('52998224725');
        $this->get('/catalogs/list/inspector?edit='.$record->id)->assertOk()->assertSee('52998224725');
    }

    public function test_update_preserves_unknown_payload_and_rejects_stale_revision(): void
    {
        $id = DB::table('contract_inspectors')->insertGetId(['legacy_id' => 99, 'name' => 'Antigo', 'name_key' => 'ANTIGO',
            'fund_name_key' => 'FUNDO SAUDE', 'department_name_key' => '',
            'raw_payload' => json_encode(['Email' => 'preservar@example.com', 'Cod_Prodata' => 123, 'Sexo' => 'F', 'Data_Nasc' => 'legado'])]);
        $revision = app(InspectorRegistration::class)->revision(DB::table('contract_inspectors')->find($id));
        $this->put('/inspectors/'.$id, $this->data(['Nome_Fiscal' => 'José da Conceição', 'revision' => $revision, 'Sexo' => 'F', 'Data_Nasc' => null]))->assertRedirect();
        $record = DB::table('contract_inspectors')->find($id);
        $raw = json_decode($record->raw_payload, true);
        $this->assertSame('JOSÉ DA CONCEIÇÃO', $record->name);
        $this->assertSame('JOSÉ DA CONCEIÇÃO', $raw['Nome_Fiscal']);
        $this->assertSame('preservar@example.com', $raw['Email']);
        $this->assertSame(123, $raw['Cod_Prodata']);
        $this->assertSame('legado', $raw['Data_Nasc']);
        $this->assertSame(99, $record->legacy_id);
        $this->put('/inspectors/'.$id, $this->data(['revision' => $revision]))->assertStatus(409);
        $this->assertDatabaseCount('activity_events', 1);
    }

    public function test_context_and_cpf_are_validated_and_non_admin_cannot_write(): void
    {
        $department = DB::table('contract_departments')->insertGetId(['name' => 'Educação', 'name_key' => 'EDUCACAO', 'raw_payload' => '{}']);
        $this->post('/inspectors', $this->data(['department_id' => $department]))->assertSessionHasErrors('department_id');
        $this->post('/inspectors', $this->data(['CPF_Fiscal' => '11111111111']))->assertSessionHasErrors('CPF_Fiscal');
        $this->assertDatabaseCount('contract_inspectors', 0);
        $user = User::create(['name' => 'Usuário', 'username' => 'USER', 'password' => 'SenhaSegura123']);
        $this->actingAs($user)->post('/inspectors', $this->data())->assertForbidden();
        $this->put('/inspectors/1', $this->data())->assertForbidden();
        $this->get('/catalogs/list/inspector?edit=1')->assertForbidden();
    }

    public function test_status_is_saved_preserved_when_omitted_and_validated(): void
    {
        $this->post('/inspectors', $this->data(['Situacao' => 'Inativo']))->assertRedirect();
        $record = DB::table('contract_inspectors')->first();
        $this->assertSame('Inativo', json_decode($record->raw_payload, true)['Situacao']);
        $this->get('/catalogs/list/inspector?edit='.$record->id)->assertOk()->assertSee('value="Inativo" selected', false);
        $revision = app(InspectorRegistration::class)->revision($record);
        $this->put('/inspectors/'.$record->id, $this->data(['revision' => $revision]))->assertRedirect();
        $record = DB::table('contract_inspectors')->first();
        $this->assertSame('Inativo', json_decode($record->raw_payload, true)['Situacao']);
        $this->post('/inspectors', $this->data(['Situacao' => 'Inválido']))->assertSessionHasErrors('Situacao');
        $this->assertDatabaseCount('contract_inspectors', 1);
    }

    public function test_delete_requires_confirmation_and_revision_and_preserves_the_record(): void
    {
        $this->post('/inspectors', $this->data())->assertRedirect();
        $record = DB::table('contract_inspectors')->first();
        $revision = app(InspectorRegistration::class)->revision($record);
        $url = '/inspectors/'.$record->id;
        $this->delete($url, ['revision' => $revision])->assertSessionHasErrors('confirmation');
        $this->delete($url, ['revision' => 'stale', 'confirmation' => 1])->assertStatus(409);
        $this->assertNull(DB::table('contract_inspectors')->find($record->id)->deleted_at);
        $this->delete($url, ['revision' => $revision, 'confirmation' => 1])->assertRedirect('/catalogs/list/inspector');
        $deleted = DB::table('contract_inspectors')->find($record->id);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame($record->raw_payload, $deleted->raw_payload);
        $this->assertDatabaseHas('activity_events', ['action' => 'inspector_deleted', 'actor_id' => $this->admin->id]);
        $this->get('/catalogs/list/inspector')->assertDontSee('FISCAL EXEMPLO');
        $this->get('/catalogs/list/inspector?edit='.$record->id)->assertNotFound();
        $this->put($url, $this->data(['revision' => $revision]))->assertNotFound();
        $this->delete($url, ['revision' => $revision, 'confirmation' => 1])->assertNotFound();
    }

    public function test_delete_rechecks_admin_and_rolls_back_when_audit_fails(): void
    {
        $this->post('/inspectors', $this->data())->assertRedirect();
        $record = DB::table('contract_inspectors')->first();
        $input = ['revision' => app(InspectorRegistration::class)->revision($record), 'confirmation' => 1];
        $user = User::create(['name' => 'Usuário', 'username' => 'USER', 'password' => 'SenhaSegura123']);
        $this->actingAs($user)->delete('/inspectors/'.$record->id, $input)->assertForbidden();
        ActivityEvent::creating(function () {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            app(InspectorRegistration::class)->delete($this->admin, $record->id, $input);
            $this->fail('Audit failure should abort deletion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertNull(DB::table('contract_inspectors')->find($record->id)->deleted_at);
    }

    public function test_lookup_finds_masked_cpf_including_inactive_records_without_exposing_personal_payloads(): void
    {
        $this->post('/inspectors', $this->data(['Situacao' => 'Inativo']))->assertRedirect();
        $record = DB::table('contract_inspectors')->first();
        DB::table('contract_inspectors')->where('id', $record->id)->update(['raw_payload->CPF_Fiscal' => ' 529.982.247-25 ']);
        $this->postJson('/inspectors/lookup', ['cpf' => '52998224725'])->assertOk()
            ->assertExactJson(['found' => true, 'edit_url' => route('catalogs.list', ['kind' => 'inspector', 'edit' => $record->id])])
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('FISCAL EXEMPLO')->assertDontSee('52998224725');
        DB::table('contract_inspectors')->where('id', $record->id)->update(['deleted_at' => now()]);
        $this->postJson('/inspectors/lookup', ['cpf' => '529.982.247-25'])->assertOk()->assertExactJson(['found' => false, 'edit_url' => null]);
    }

    public function test_lookup_rejects_invalid_cpf_duplicates_and_non_administrators(): void
    {
        foreach (['11111111111', '52998224724', 'abc52998224725', ['52998224725']] as $cpf) {
            $this->postJson('/inspectors/lookup', ['cpf' => $cpf])->assertUnprocessable()->assertJsonValidationErrors('cpf');
        }
        $this->post('/inspectors', $this->data())->assertRedirect();
        $this->post('/inspectors', $this->data())->assertRedirect();
        $this->postJson('/inspectors/lookup', ['cpf' => '52998224725'])->assertStatus(409)->assertDontSee('FISCAL EXEMPLO');
        $user = User::create(['name' => 'Usuário', 'username' => 'USER', 'password' => 'SenhaSegura123']);
        $this->actingAs($user)->postJson('/inspectors/lookup', ['cpf' => '52998224725'])->assertForbidden();
    }

    public function test_brazilian_legacy_dates_fill_the_form_and_save_dates_in_iso_format(): void
    {
        $id = DB::table('contract_inspectors')->insertGetId(['legacy_id' => 99, 'name' => 'Fiscal exemplo', 'name_key' => 'FISCAL EXEMPLO',
            'fund_name_key' => 'FUNDO SAUDE', 'department_name_key' => '',
            'raw_payload' => json_encode(['Data_Nasc' => '30/12/1971', 'Data_Decreto' => '29/02/2024'])]);
        $record = DB::table('contract_inspectors')->find($id);
        $this->get('/catalogs/list/inspector?edit='.$id)->assertOk()
            ->assertSee('value="1971-12-30"', false)->assertSee('value="2024-02-29"', false)->assertDontSee('Valor legado:');
        $this->assertSame($record->raw_payload, DB::table('contract_inspectors')->find($id)->raw_payload);
        $revision = app(InspectorRegistration::class)->revision($record);
        $this->put('/inspectors/'.$id, $this->data(['revision' => $revision, 'Data_Nasc' => '1971-12-30', 'Data_Decreto' => '2024-02-29']))->assertRedirect();
        $record = DB::table('contract_inspectors')->find($id);
        $raw = json_decode($record->raw_payload, true);
        $this->assertSame('1971-12-30', $raw['Data_Nasc']);
        $this->assertSame('2024-02-29', $raw['Data_Decreto']);
        $this->put('/inspectors/'.$id, $this->data(['revision' => app(InspectorRegistration::class)->revision($record), 'Data_Nasc' => null]))->assertRedirect();
        $this->assertNull(json_decode(DB::table('contract_inspectors')->find($id)->raw_payload, true)['Data_Nasc']);
    }

    public function test_impossible_brazilian_dates_are_not_silently_rolled_into_another_month(): void
    {
        $this->assertNull(InspectorRegistration::dateForForm('31/02/2026'));
        $this->assertNull(InspectorRegistration::dateForForm('29/02/2025'));
        $this->assertNull(InspectorRegistration::dateForForm('30/12/71'));
        $this->assertSame('1971-12-30', InspectorRegistration::dateForForm('1971-12-30'));
        $this->assertSame('1971-12-30', InspectorRegistration::dateForForm(' 30/12/1971 '));
    }

    public function test_registration_stores_iso_dates_and_rejects_brazilian_dates_sent_directly_to_the_server(): void
    {
        $this->post('/inspectors', $this->data(['Data_Nasc' => '1971-12-30']))->assertRedirect();
        $record = DB::table('contract_inspectors')->first();
        $raw = json_decode($record->raw_payload, true);
        $this->assertSame('1971-12-30', $raw['Data_Nasc']);
        $this->assertSame('2026-01-05', $raw['Data_Decreto']);
        $this->post('/inspectors', $this->data(['Data_Nasc' => '30/12/1971', 'Data_Decreto' => '05/01/2026']))
            ->assertSessionHasErrors(['Data_Nasc', 'Data_Decreto']);
        $this->assertDatabaseCount('contract_inspectors', 1);
    }

    public function test_audit_failure_rolls_back_registration(): void
    {
        ActivityEvent::creating(function () {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            app(InspectorRegistration::class)->save($this->admin, null, $this->data());
            $this->fail('Audit failure should abort the mutation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('contract_inspectors', 0);
    }
}
