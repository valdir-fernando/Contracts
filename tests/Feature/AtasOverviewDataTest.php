<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtasOverviewQuery;
use App\Livewire\AtasOverview;
use App\Models\AccessScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AtasOverviewDataTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 11, 9)->startOfDay());
        $this->actor = User::create(['name' => 'Pessoa fictícia', 'username' => 'ATAS-DATA', 'password' => 'SyntheticPassword123'])->fresh();
        $this->actingAs($this->actor);
    }

    public function test_global_portfolio_ignores_selected_fund_scopes_and_participant_multiplicity(): void
    {
        $funds = [];
        foreach (['A', 'B'] as $name) {
            $funds[] = DB::table('contract_funds')->insertGetId(['name' => 'Fundo '.$name, 'name_key' => 'FUNDO '.$name, 'raw_payload' => '{}']);
        }
        $scope = AccessScope::create(['scope_key' => 'global-atas-test', 'fund_id' => $funds[0], 'fund' => 'Fundo A']);
        $this->actor->scopes()->attach($scope);
        $first = $this->ata('001/2026', ['fund_id' => $funds[0], 'FORNECEDOR' => 'Fornecedor A']);
        $second = $this->ata('002/2026', ['fund_id' => $funds[1], 'FORNECEDOR' => 'Fornecedor B']);
        foreach ($funds as $index => $fund) {
            DB::table('tb_FUNDOS_ATA')->insert(['ID_ATA' => $first, 'CNPJ_FUNDO' => '00.000.000/0000-0'.($index + 1), 'FUNDO' => 'Participante', 'papel' => 'participante', 'fund_id' => $fund]);
        }
        $this->item($first, '10.0100');
        $this->item($second, '20.0200');
        $response = $this->withSession(['fund_id' => $funds[0]])->get('/atas')->assertOk()
            ->assertSee('R$ 30,03')->assertDontSee('id="atas-list"', false)
            ->assertSee('2 atas sem vigência definida')->assertDontSee('atas do Fundo');
        $this->preview('global', $response->getContent());
        $data = $this->data();
        $this->assertSame(2, $data['count']);
        $this->assertSame(2, $data['itemCount']);
        $this->assertSame('30.0300', $data['total']);
        $this->assertSame(['Fornecedor B', 'Fornecedor A'], array_column($data['atas']->items(), 'supplier'));
        $this->withSession(['fund_id' => null])->get('/atas')->assertOk()->assertSee('R$ 30,03');
    }

    public function test_validity_charts_alerts_and_totals_use_only_active_atas_and_items(): void
    {
        $this->actor->forceFill(['expiration_alert_enabled' => true, 'expiration_alert_days' => 30])->save();
        $current = $this->ata('001/26', ['INICIO_ATA' => '2026-01-01', 'FINAL_ATA' => '2026-11-20']);
        $this->ata('002/26', ['INICIO_ATA' => '2026-01-01', 'FINAL_ATA' => '2026-11-09']);
        $this->ata('003/26', ['INICIO_ATA' => '2026-12-01', 'FINAL_ATA' => '2027-01-10']);
        $this->ata('004/26');
        $this->ata('005/26', ['INICIO_ATA' => '2026-12-01', 'FINAL_ATA' => '2026-11-10']);
        $deleted = $this->ata('006/26', ['deleted_at' => now()]);
        $this->item($current, '0.1000');
        $this->item($current, '0.2000');
        $this->item($current, '9999.0000', true);
        $this->item($deleted, '8888.0000');
        $data = $this->data();
        $this->assertSame(5, $data['count']);
        $this->assertSame(['vigente' => 1, 'vencido' => 1, 'a_iniciar' => 1, 'indeterminado' => 2], $data['statuses']);
        $this->assertSame('0.3000', $data['total']);
        $this->assertSame(2, $data['itemCount']);
        $this->assertSame([1, 0, 1, 0, 0, 0], array_column($data['months'], 'count'));
        $this->assertSame(['001/26'], array_column($data['alerts'], 'number'));
        $response = $this->get('/atas')->assertOk()->assertSee('1 ata em 30 dias')->assertSee('20,0%')->assertSee('R$ 0,30');
        $this->preview('statuses', $response->getContent());
    }

    public function test_year_uses_ata_suffix_instead_of_inconsistent_document_date(): void
    {
        $current = $this->ata('057/2026', ['DATA_ATA' => '2025-03-11', 'FORNECEDOR' => 'Fornecedor deste ano']);
        $previous = $this->ata(" 009 / 25\t", ['FORNECEDOR' => 'Fornecedor anterior']);
        $this->item($current, '10.0000');
        $this->item($previous, '20.0000');
        Livewire::test(AtasOverview::class)->assertSee('R$ 10,00')->assertDontSee('R$ 20,00')
            ->set('year', '2025')->assertSee('R$ 20,00')->assertDontSee('R$ 10,00')
            ->set('year', 'all')->assertSee('R$ 30,00')->assertSee('em todos os exercícios');
        $this->assertSame([2026, 2025], $this->data()['years']->all());
    }

    public function test_search_and_pagination_do_not_change_portfolio_metrics(): void
    {
        for ($number = 1; $number <= 12; $number++) {
            $this->ata(str_pad((string) $number, 3, '0', STR_PAD_LEFT).'/26', ['FORNECEDOR' => 'Fornecedor '.$number,
                'PROCESSO' => 'processo-'.$number, 'N_LICIT' => $number === 1 ? '099/2026' : '001/2026']);
        }
        $firstPage = $this->data()['atas'];
        $this->assertSame(12, $firstPage->total());
        $this->assertSame('Fornecedor 12', $firstPage->items()[0]['supplier']);
        $secondPage = app(AtasOverviewQuery::class)->for($this->actor, 2026, '', 2)['atas'];
        $this->assertSame(['Fornecedor 2', 'Fornecedor 1'], array_column($secondPage->items(), 'supplier'));
        $component = Livewire::test(AtasOverview::class)->call('setPage', 2)->set('search', 'processo-1')->assertSet('paginators.page', 1);
        $data = $this->data(search: '099/2026');
        $this->assertSame(12, $data['count']);
        $this->assertSame(1, $data['atas']->total());
        $this->assertSame('Fornecedor 1', $data['atas']->items()[0]['supplier']);
        $this->assertSame(0, $this->data(search: 'inexistente')['atas']->total());
        $component->set('search', str_repeat('x', 256))->assertHasErrors('search');
    }

    public function test_projection_and_html_hide_private_snapshots_and_escape_supplier(): void
    {
        $this->actor->forceFill(['expiration_alert_enabled' => true, 'expiration_alert_days' => 30])->save();
        $this->ata('001/26', ['INICIO_ATA' => '2026-01-01', 'FINAL_ATA' => '2026-11-20', 'FORNECEDOR' => '<script>alert(1)</script>', 'CPF_SOCIO' => 'CPF-SECRETO',
            'RG_SOCIO' => 'RG-SECRETO', 'END_FORNECEDOR' => 'ENDERECO-SECRETO', 'EMAIL' => 'EMAIL-SECRETO',
            'source_files' => '[{"file":"CAMINHO-SECRETO"}]', 'quality_issues' => '["PENDENCIA-INTERNA"]']);
        $response = $this->get('/atas')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        foreach (['CPF-SECRETO', 'RG-SECRETO', 'ENDERECO-SECRETO', 'EMAIL-SECRETO', 'CAMINHO-SECRETO', 'PENDENCIA-INTERNA'] as $secret) {
            $response->assertDontSee($secret);
            $this->assertStringNotContainsString($secret, json_encode($this->data()['atas']->items()));
        }
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_disabled_alerts_and_revoked_actor_are_rechecked_on_refresh(): void
    {
        $this->ata('001/26', ['INICIO_ATA' => '2026-01-01', 'FINAL_ATA' => '2026-11-20']);
        $component = Livewire::test(AtasOverview::class);
        User::whereKey($this->actor->id)->update(['expiration_alert_enabled' => false]);
        $component->call('$refresh')->assertSee('Avisos desativados na sua conta');
        User::whereKey($this->actor->id)->update(['is_active' => false]);
        $component->call('$refresh')->assertForbidden();
    }

    private function data(?int $year = 2026, string $search = ''): array
    {
        return app(AtasOverviewQuery::class)->for($this->actor, $year, $search);
    }

    private function preview(string $name, string $html): void
    {
        if ($directory = getenv('ATAS_PREVIEW_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
    }

    private function ata(string $number, array $values = []): int
    {
        return DB::table('tb_ATA')->insertGetId(array_replace(['ATA' => $number, 'DATA_ATA' => '2026-01-01',
            'PROCESSO' => '123', 'LICITACAO' => 'PREGÃO ELETRÔNICO', 'N_LICIT' => '001/2026', 'FORNECEDOR' => 'Fornecedor fictício',
            'CNPJ_FUNDO' => '00.000.000/0000-01', 'source_files' => '[]', 'quality_issues' => '[]', 'import_hash' => str_repeat('0', 64)], $values), 'ID');
    }

    private function item(int $ataId, string $value, bool $deleted = false): void
    {
        DB::table('tb_PRODUTOS_ATA')->insert(['ID_ATA' => $ataId, 'ATA' => '001/26', 'PROCESSO' => '123', 'LICITACAO' => 'PREGÃO', 'N_LICIT' => '001/26',
            'ITEM' => (string) (DB::table('tb_PRODUTOS_ATA')->count() + 1), 'DISCRIMINACAO' => 'Produto fictício', 'UND' => 'UN',
            'QTD' => '1.0000', 'VL_UNIT' => $value, 'VL_TOTAL' => $value, 'source_row' => '[]', 'deleted_at' => $deleted ? now() : null]);
    }
}
