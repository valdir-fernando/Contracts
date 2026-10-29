<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetiredContractListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_listing_redirects_to_reports_and_navigation_has_no_retired_links(): void
    {
        $this->withoutVite();
        $actor = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']);
        $this->get('/contracts?validity=vigente')->assertRedirect('/login');
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo teste', 'name_key' => 'FUNDO TESTE', 'raw_payload' => '{}']);
        $this->actingAs($actor)->withSession(['fund_id' => $fund]);
        foreach (['/contracts', '/contracts?validity=vigente', '/contracts?validity=vencido'] as $url) {
            $this->get($url)->assertRedirect(route('reports.index'));
        }
        $contract = Contract::create(['numero' => '001/26', 'fund_id' => $fund, 'ownership_resolved' => true, 'quality_issues' => []]);
        foreach (['/dashboard', '/reports', '/contracts/create', '/contracts/'.$contract->id] as $url) {
            $response = $this->get($url)->assertOk();
            $html = new \DOMDocument;
            @$html->loadHTML($response->getContent());
            $xpath = new \DOMXPath($html);
            $this->assertSame(0, $xpath->query('//a[@href="'.route('contracts.index').'" or starts-with(@href, "'.route('contracts.index').'?" )]')->length);
            $response->assertDontSee('Contratos vigentes');
        }
    }

    public function test_deleted_contracts_remain_private_searchable_paginated_and_restorable(): void
    {
        $this->withoutVite();
        $actor = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']);
        $deleted = null;
        for ($i = 0; $i < 21; $i++) {
            $deleted = Contract::create(['numero' => 'EXCLUIDO-'.$i, 'processo' => 'HISTORICO', 'quality_issues' => []]);
            $deleted->delete();
        }
        Contract::create(['numero' => 'ATIVO', 'quality_issues' => []]);
        $this->actingAs($actor)->get('/contracts/deleted?q=HISTORICO')->assertOk()
            ->assertSee('Restaurar')->assertDontSee('ATIVO')
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 21 && str_contains($rows->nextPageUrl(), 'q=HISTORICO'));
        $this->get('/contracts/deleted?q=HISTORICO&page=2')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->count() === 1);
        $this->get('/contracts?deleted=1')->assertOk()->assertSee('Restaurar');
        $this->post('/contracts/'.$deleted->id.'/restore', ['confirmation' => $deleted->numero,
            'reason' => 'Restauração administrativa de teste', 'version' => $deleted->fresh()->version])
            ->assertSessionHasNoErrors()->assertRedirect(route('contracts.show', $deleted));
        $this->assertNull($deleted->fresh()->deleted_at);
        $this->assertDatabaseHas('contract_audits', ['contract_id' => $deleted->id, 'action' => 'restored']);

        $actor->update(['user_type' => 'Usuario']);
        $this->get('/contracts/deleted')->assertForbidden();
        $this->get('/contracts?deleted=1')->assertForbidden();
    }
}
