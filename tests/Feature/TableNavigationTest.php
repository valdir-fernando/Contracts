<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\ImportRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TableNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $regular;

    private int $fund;

    private int $department;

    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']);
        $this->regular = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'SyntheticPassword123']);
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo A', 'name_key' => 'FUNDO A', 'document' => '11222333000181', 'raw_payload' => '{"private":"PRIVATE SOURCE"}']);
        $this->department = DB::table('contract_departments')->insertGetId(['name' => 'Secretaria A', 'name_key' => 'SECRETARIA A', 'raw_payload' => '{}']);
        $this->supplier = DB::table('suppliers')->insertGetId(['name' => 'Fornecedor A', 'name_key' => 'FORNECEDOR A', 'document' => '11.222.333/0001-81', 'document_key' => '11222333000181', 'raw_payload' => '{"private":"PRIVATE SOURCE"}']);
        $scope = AccessScope::create(['scope_key' => 'scope', 'fund' => 'Fundo A', 'fund_id' => $this->fund]);
        $this->regular->scopes()->attach($scope);
    }

    private function contract(array $extra = []): Contract
    {
        return Contract::create(array_replace(['numero' => 'VISIBLE-001', 'fund_id' => $this->fund, 'department_id' => $this->department,
            'supplier_id' => $this->supplier, 'ownership_resolved' => true, 'quality_issues' => [], 'vigencia_inicio' => '2026-01-01',
            'vigencia_fim_original' => '2026-12-01', 'vigencia_fim_atual' => '2026-12-01', 'cpf_gestor' => '11144477735'], $extra));
    }

    public function test_supplier_details_preserve_scope_and_explicit_fields(): void
    {
        $this->contract();
        $this->contract(['numero' => 'SECRET-OTHER-FUND', 'fund_id' => null, 'ownership_resolved' => false]);
        $response = $this->actingAs($this->regular)->get('/suppliers/'.$this->supplier)->assertOk()->assertSee('Fornecedor A')
            ->assertSee('VISIBLE-001')->assertDontSee('SECRET-OTHER-FUND')->assertDontSee('PRIVATE SOURCE')->assertDontSee('11144477735');
        $response->assertSee('data-row-href=', false);
        $other = DB::table('suppliers')->insertGetId(['name' => 'Fornecedor oculto', 'name_key' => 'OCULTO', 'document_key' => '99888777000100', 'raw_payload' => '{}']);
        $this->get('/suppliers/'.$other)->assertNotFound();
        $person = DB::table('suppliers')->insertGetId(['name' => 'Pessoa', 'name_key' => 'PESSOA', 'document' => '52998224725', 'document_key' => '52998224725', 'raw_payload' => '{}']);
        $this->contract(['supplier_id' => $person]);
        $this->get('/suppliers/'.$person)->assertNotFound()->assertDontSee('52998224725');
        $this->actingAs($this->admin)->get('/suppliers/'.$person)->assertOk()->assertSee('52998224725');
    }

    public function test_catalog_details_are_admin_only_and_never_expose_source_payload(): void
    {
        $this->contract();
        $this->actingAs($this->regular)->get('/catalogs/fund/'.$this->fund)->assertForbidden();
        $this->get('/catalogs/department/'.$this->department)->assertForbidden();
        $this->actingAs($this->admin)->get('/catalogs/fund/'.$this->fund)->assertOk()->assertSee('Fundo A')->assertSee('VISIBLE-001')->assertDontSee('PRIVATE SOURCE');
        $this->get('/catalogs/department/'.$this->department)->assertOk()->assertSee('Secretaria A')->assertSee('VISIBLE-001');
        $this->get('/catalogs/invalid/'.$this->fund)->assertNotFound();
        $this->get('/catalogs/fund/99999')->assertNotFound();
    }

    public function test_contract_report_and_dashboard_rows_link_to_the_corresponding_contract(): void
    {
        $contract = $this->contract(['exercicio' => today()->year]);
        $this->actingAs($this->admin)->withSession(['fund_id' => $this->fund]);
        foreach (['/contracts?year=', '/reports', '/calendar?month=2026-12'] as $url) {
            $this->get($url)->assertOk()->assertSee('data-row-href="'.route('contracts.show', $contract).'"', false);
        }
        $contract->update(['vigencia_fim_atual' => today()->addDays(5)]);
        $this->actingAs($this->admin->fresh())->get('/dashboard?year=all')->assertOk()->assertDontSee('Contratos recentes')
            ->assertDontSee('data-row-href=', false)
            ->assertSee('href="'.route('contracts.show', $contract).'"', false)->assertSee($contract->numero);
        $contract->delete();
        $this->get('/contracts?deleted=1&year=')->assertOk()->assertSee('Restaurar')->assertDontSee('data-row-href="'.route('contracts.show', $contract).'"', false);
    }

    public function test_administrative_record_details_require_admin_and_project_event_metadata(): void
    {
        $event = ActivityEvent::create(['actor_id' => $this->admin->id, 'action' => 'report_requested', 'metadata' => ['export_id' => 1], 'created_at' => now()]);
        $contract = $this->contract();
        $audit = ContractAudit::create(['contract_id' => $contract->id, 'actor_id' => $this->admin->id, 'action' => 'updated',
            'changes' => ['objeto' => ['before' => 'Antigo', 'after' => 'Novo']], 'reason' => 'Correção registrada', 'created_at' => now()]);
        $run = ImportRun::create(['source_hash' => str_repeat('a', 64), 'status' => 'completed', 'started_at' => now(), 'summary' => ['imported' => 1]]);
        $this->actingAs($this->regular);
        foreach (['event' => $event->id, 'contract-audit' => $audit->id, 'import' => $run->id, 'user-audit' => 1, 'issue' => 1] as $kind => $id) {
            $this->get('/administration/records/'.$kind.'/'.$id)->assertForbidden();
        }
        $this->actingAs($this->admin)->get('/administration/records/event/'.$event->id)->assertOk()->assertSee('Relatório solicitado');
        $this->get('/administration/records/contract-audit/'.$audit->id)->assertOk()->assertSee('Correção registrada')->assertSee('Antigo')->assertSee('Novo');
        $this->get('/administration/records/import/'.$run->id)->assertOk()->assertSee('Execução de importação');
        $this->get('/administration/records/event/9999')->assertNotFound();
        $this->get('/administration')->assertOk()->assertSee('data-row-href="'.route('administration.record', ['event', $event->id]).'"', false);
    }
}
