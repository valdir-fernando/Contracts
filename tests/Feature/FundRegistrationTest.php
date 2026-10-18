<?php

namespace Tests\Feature;

use App\Infrastructure\Funds\FundRegistration;
use App\Infrastructure\Security\SelectedFund;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class FundRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaSegura123', 'user_type' => 'Administrador']);
        $this->actingAs($this->admin);
    }

    private function data(array $extra = []): array
    {
        return array_replace(['name' => 'Fundo exemplo', 'document' => '11.222.333/0001-81', 'End_Fundo' => 'Rua Exemplo, 10',
            'Abreviatuda' => 'FEX', 'Codigo' => '002', 'Telefone' => '(62) 3333-4444', 'Nome' => 'Contato exemplo'], $extra);
    }

    private function revision(object $record): string
    {
        return app(FundRegistration::class)->revision($record);
    }

    public function test_form_lists_imported_fields_and_creation_saves_legacy_keys_with_audit(): void
    {
        $this->get('/funds')->assertOk()->assertSee('Cadastro de Fundo')->assertSee('Código de gestão')->assertSee('Dados Online')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/funds', $this->data())->assertRedirect();
        $record = DB::table('contract_funds')->first();
        $this->assertNull($record->legacy_id);
        $this->assertSame('Fundo exemplo', $record->name);
        $this->assertSame('11222333000181', $record->document_key);
        $raw = json_decode($record->raw_payload, true);
        $this->assertSame('FEX', $raw['Abreviatuda']);
        $this->assertSame('002', $raw['Codigo']);
        $this->assertSame('Contato exemplo', $raw['Nome']);
        $this->assertSame('Rua Exemplo, 10', app(SelectedFund::class)->defaults($this->admin, $record->id)['end_fundo']);
        $this->assertDatabaseHas('activity_events', ['action' => 'fund_created', 'actor_id' => $this->admin->id]);
        $this->get('/funds?edit='.$record->id)->assertOk()->assertSee('11.222.333/0001-81')->assertSee('Contato exemplo');
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_edit_keeps_source_and_contract_history_and_rejects_stale_revision(): void
    {
        $this->post('/funds', $this->data())->assertRedirect();
        $record = DB::table('contract_funds')->first();
        DB::table('contract_funds')->where('id', $record->id)->update(['legacy_id' => 8, 'raw_payload->source_extra' => 'Preservado']);
        $record = DB::table('contract_funds')->first();
        $revision = $this->revision($record);
        $contract = Contract::create(['fund_id' => $record->id, 'fundo_nome_snapshot' => $record->name, 'end_fundo' => 'Endereço histórico', 'quality_issues' => []]);
        $this->put('/funds/'.$record->id, $this->data(['revision' => $revision, 'End_Fundo' => 'Endereço atualizado']))->assertRedirect();
        $updated = DB::table('contract_funds')->first();
        $this->assertSame('Preservado', json_decode($updated->raw_payload, true)['source_extra']);
        $this->assertSame(8, $updated->legacy_id);
        $this->assertSame('Endereço histórico', $contract->fresh()->end_fundo);
        $this->put('/funds/'.$record->id, $this->data(['revision' => $revision]))->assertStatus(409);
        $this->put('/funds/'.$record->id, $this->data(['revision' => $this->revision($updated), 'name' => 'Fundo diferente']))->assertSessionHasErrors('name');
        $this->delete('/funds/'.$record->id, ['revision' => $this->revision($updated), 'confirmation' => 1])->assertSessionHasErrors('confirmation');
    }

    public function test_delete_preserves_record_and_removes_it_from_login_and_session_authorization(): void
    {
        $this->post('/funds', $this->data())->assertRedirect();
        $record = DB::table('contract_funds')->first();
        $input = ['revision' => $this->revision($record), 'confirmation' => 1];
        $this->delete('/funds/'.$record->id, ['revision' => $input['revision']])->assertSessionHasErrors('confirmation');
        $this->delete('/funds/'.$record->id, ['revision' => 'stale', 'confirmation' => 1])->assertStatus(409);
        $this->delete('/funds/'.$record->id, $input)->assertRedirect('/funds');
        $deleted = DB::table('contract_funds')->first();
        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame($record->raw_payload, $deleted->raw_payload);
        $this->assertFalse(app(SelectedFund::class)->allows($this->admin, $record->id));
        $this->assertNull(app(SelectedFund::class)->current($this->admin, $record->id));
        $this->get('/funds')->assertDontSee($record->name);
        $this->get('/funds?edit='.$record->id)->assertNotFound();
        $this->put('/funds/'.$record->id, $this->data(['revision' => $input['revision']]))->assertNotFound();
        $this->post('/session/fund', ['fund_id' => $record->id])->assertForbidden();
        $this->post('/catalogs', ['kind' => 'scope', 'fund_id' => $record->id])->assertUnprocessable();
        $this->assertDatabaseHas('activity_events', ['action' => 'fund_deleted']);
        $this->app['auth']->forgetGuards();
        $this->get('/login')->assertOk()->assertDontSee($record->name);
    }

    public function test_linked_fund_cannot_be_deleted_and_validation_enforces_cnpj_and_duplicates(): void
    {
        $this->post('/funds', $this->data(['document' => '52998224725']))->assertSessionHasErrors('document');
        $this->post('/funds', $this->data(['document' => '11111111111111']))->assertSessionHasErrors('document');
        $this->post('/funds', $this->data())->assertRedirect();
        $record = DB::table('contract_funds')->first();
        $this->post('/funds', $this->data())->assertSessionHasErrors('document');
        AccessScope::create(['scope_key' => 'scope-fund', 'fund_id' => $record->id, 'fund' => $record->name]);
        $this->delete('/funds/'.$record->id, ['revision' => $this->revision($record), 'confirmation' => 1])->assertSessionHasErrors('confirmation');
        $this->assertNull(DB::table('contract_funds')->first()->deleted_at);
        $user = User::create(['name' => 'Usuário', 'username' => 'USER', 'password' => 'SenhaSegura123']);
        $this->actingAs($user)->get('/funds')->assertForbidden();
        $this->post('/funds', $this->data())->assertForbidden();
        $this->put('/funds/'.$record->id, $this->data())->assertForbidden();
        $this->delete('/funds/'.$record->id, ['confirmation' => 1])->assertForbidden();
    }

    public function test_audit_failure_rolls_back_edit_and_deletion(): void
    {
        $this->post('/funds', $this->data())->assertRedirect();
        $record = DB::table('contract_funds')->first();
        $service = app(FundRegistration::class);
        $revision = $service->revision($record);
        ActivityEvent::creating(function () {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            foreach (['update', 'delete'] as $action) {
                try {
                    if ($action === 'update') {
                        $service->save($this->admin, $record->id, $this->data(['revision' => $revision, 'End_Fundo' => 'Alterado']));
                    } else {
                        $service->delete($this->admin, $record->id, ['revision' => $revision, 'confirmation' => 1]);
                    }
                    $this->fail('Audit failure must abort mutation.');
                } catch (RuntimeException $error) {
                    $this->assertSame('Audit unavailable', $error->getMessage());
                }
                $current = DB::table('contract_funds')->first();
                $this->assertSame($record->raw_payload, $current->raw_payload);
                $this->assertNull($current->deleted_at);
            }
        } finally {
            ActivityEvent::flushEventListeners();
        }
    }

    public function test_cnpj_lookup_opens_the_existing_editor_without_exposing_payload_or_modifying_records(): void
    {
        $this->post('/funds', $this->data())->assertRedirect();
        $record = DB::table('contract_funds')->first();
        DB::table('contract_funds')->where('id', $record->id)->update(['document' => ' 11.222.333/0001-81 ', 'document_key' => null]);
        $this->postJson('/funds/lookup', ['cnpj' => '11222333000181'])->assertOk()
            ->assertExactJson(['found' => true, 'edit_url' => route('funds.index', ['edit' => $record->id])])
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('Contato exemplo')->assertDontSee('11222333000181');
        $this->get('/funds?edit='.$record->id)->assertOk()->assertSee('Rua Exemplo, 10')->assertSee('Contato exemplo');
        $this->assertDatabaseCount('contract_funds', 1);
        $this->assertDatabaseCount('activity_events', 1);
        DB::table('contract_funds')->where('id', $record->id)->update(['deleted_at' => now()]);
        $this->postJson('/funds/lookup', ['cnpj' => '11.222.333/0001-81'])->assertOk()->assertExactJson(['found' => false, 'edit_url' => null]);
    }

    public function test_cnpj_lookup_validates_document_rejects_ambiguity_and_requires_administrator(): void
    {
        foreach (['11111111111111', '52998224725', 'abc11222333000181', ['11222333000181']] as $cnpj) {
            $this->postJson('/funds/lookup', ['cnpj' => $cnpj])->assertUnprocessable()->assertJsonValidationErrors('cnpj');
        }
        $this->post('/funds', $this->data())->assertRedirect();
        $record = (array) DB::table('contract_funds')->first();
        unset($record['id']);
        DB::table('contract_funds')->insert($record);
        $this->postJson('/funds/lookup', ['cnpj' => '11222333000181'])->assertStatus(409)->assertDontSee('Contato exemplo');
        $user = User::create(['name' => 'Usuário', 'username' => 'USER', 'password' => 'SenhaSegura123']);
        $this->actingAs($user)->postJson('/funds/lookup', ['cnpj' => '11222333000181'])->assertForbidden();
    }

    public function test_audit_failure_rolls_back_new_fund(): void
    {
        ActivityEvent::creating(function () {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            app(FundRegistration::class)->save($this->admin, null, $this->data());
            $this->fail('Audit failure must abort registration.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('contract_funds', 0);
    }
}
