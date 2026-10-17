<?php

namespace Tests\Feature;

use App\Infrastructure\Suppliers\UpdateSupplier;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']);
        $this->actingAs($this->admin);
        $this->supplier = DB::table('suppliers')->insertGetId(['name' => 'Fornecedor original', 'name_key' => 'FORNECEDOR ORIGINAL',
            'document' => '11.222.333/0001-81', 'document_key' => '11222333000181',
            'raw_payload' => json_encode(['Endereco' => 'Rua original', 'Nome_Fantasia' => 'Fantasia original',
                'Email_Fornecedor' => 'original@example.com', 'Sexo' => 'Feminino', 'ID_Fornecedor' => 'LEGACY-42', 'retired' => 'PRIVATE LEGACY'])]);
    }

    private function input(array $extra = []): array
    {
        return $extra + ['kind' => 'supplier', 'name' => 'Fornecedor atualizado', 'document' => '11.222.333/0001-81',
            'Endereco' => 'Rua atualizada', 'revision' => UpdateSupplier::revision(DB::table('suppliers')->find($this->supplier))];
    }

    public function test_admin_opens_existing_registration_with_current_values_and_revision(): void
    {
        $response = $this->get('/suppliers/'.$this->supplier)->assertOk()->assertSee('Editar fornecedor')->assertSee('Usar no novo contrato')
            ->assertSee('Fantasia original')->assertSee('Rua original')->assertDontSee('PRIVATE LEGACY')->assertDontSee('LEGACY-42');
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        $xpath = new \DOMXPath($html);
        $form = $html->getElementById('catalog-supplier-form');
        $this->assertStringEndsWith('/suppliers/'.$this->supplier, $form->getAttribute('action'));
        $this->assertSame('PUT', $xpath->query('//input[@name="_method"]')->item(0)->getAttribute('value'));
        $this->assertSame('Fornecedor original', $html->getElementById('catalog-supplier-name')->getAttribute('value'));
        $this->assertSame('true', $form->getAttribute('data-supplier-editing'));
        $this->assertSame($this->input()['revision'], $xpath->query('//input[@name="revision"]')->item(0)->getAttribute('value'));
        $this->assertStringContainsString('?new=1', $response->getContent());
    }

    public function test_update_preserves_omitted_legacy_fields_and_contract_snapshots_and_audits(): void
    {
        $contract = Contract::create(['numero' => '001/26', 'supplier_id' => $this->supplier,
            'fornecedor_nome_snapshot' => 'Fornecedor histórico', 'fornecedor_documento_snapshot' => '11222333000181',
            'ownership_resolved' => true, 'quality_issues' => []]);
        $this->put('/suppliers/'.$this->supplier, $this->input(['Email_Fornecedor' => null, 'Fone_Fixo' => '(62) 3333-4444',
            'raw_payload' => 'forged', 'ID_Fornecedor' => 'forged']))->assertRedirect('/suppliers/'.$this->supplier)->assertSessionHasNoErrors();
        $row = DB::table('suppliers')->find($this->supplier);
        $raw = json_decode($row->raw_payload, true);
        $this->assertSame('Fornecedor atualizado', $row->name);
        $this->assertSame('Rua atualizada', $raw['Endereco']);
        $this->assertNull($raw['Email_Fornecedor']);
        $this->assertSame('Fantasia original', $raw['Nome_Fantasia']);
        $this->assertSame('Feminino', $raw['Sexo']);
        $this->assertSame('6233334444', $raw['Fone_Fixo']);
        $this->assertSame('LEGACY-42', $raw['ID_Fornecedor']);
        $this->assertSame('PRIVATE LEGACY', $raw['retired']);
        $this->assertSame('Fornecedor histórico', $contract->fresh()->fornecedor_nome_snapshot);
        $this->assertDatabaseCount('suppliers', 1);
        $audit = ActivityEvent::sole();
        $this->assertSame('supplier_updated', $audit->action);
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertNotSame($audit->metadata['before_revision'], $audit->metadata['after_revision']);
    }

    public function test_update_rejects_duplicate_documents_and_invalid_details(): void
    {
        DB::table('suppliers')->insert(['name' => 'Outro', 'name_key' => 'OUTRO', 'document_key' => '52998224725', 'raw_payload' => '{}']);
        foreach ([['document' => '52998224725'], ['document' => '123'], ['Endereco' => ''], ['Email_Socio' => 'invalid'], ['CPF_Socio' => '123']] as $invalid) {
            $this->from('/suppliers/'.$this->supplier)->put('/suppliers/'.$this->supplier, $this->input($invalid))
                ->assertRedirect('/suppliers/'.$this->supplier)->assertSessionHasErrors(array_keys($invalid), null, 'catalog-supplier');
        }
        $this->assertDatabaseHas('suppliers', ['id' => $this->supplier, 'name' => 'Fornecedor original']);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_old_revision_does_not_overwrite_current_data_or_get_refreshed_after_error(): void
    {
        $input = $this->input();
        DB::table('suppliers')->where('id', $this->supplier)->update(['name' => 'Alterado por outra pessoa']);
        $this->from('/suppliers/'.$this->supplier)->put('/suppliers/'.$this->supplier, $input)
            ->assertSessionHasErrors(['revision'], null, 'catalog-supplier');
        $response = $this->get('/suppliers/'.$this->supplier)->assertOk()->assertSee('O fornecedor foi alterado.');
        $this->assertStringContainsString('name="revision" value="'.$input['revision'].'"', $response->getContent());
        $this->assertDatabaseHas('suppliers', ['id' => $this->supplier, 'name' => 'Alterado por outra pessoa']);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_update_requires_current_admin_and_a_known_supplier(): void
    {
        $input = $this->input();
        $user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'SyntheticPassword123', 'user_type' => 'Usuario']);
        $this->actingAs($user)->put('/suppliers/'.$this->supplier, $input)->assertForbidden();
        $this->actingAs($this->admin)->put('/suppliers/999999', $input)->assertNotFound();
        DB::table('users')->where('id', $this->admin->id)->update(['is_active' => false]);
        $this->put('/suppliers/'.$this->supplier, $input)->assertForbidden();
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_audit_failure_rolls_back_supplier_update(): void
    {
        $before = DB::table('suppliers')->find($this->supplier);
        ActivityEvent::creating(fn () => throw new \RuntimeException('Synthetic audit failure'));
        try {
            $this->put('/suppliers/'.$this->supplier, $this->input())->assertStatus(500);
            $this->assertEquals($before, DB::table('suppliers')->find($this->supplier));
            $this->assertDatabaseCount('activity_events', 0);
        } finally {
            ActivityEvent::flushEventListeners();
        }
    }
}
