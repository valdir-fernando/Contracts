<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\User;
use App\Services\Reports\ContractReport;
use App\Services\Reports\ReportRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdvancedContractSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'TestPassword12345']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'search-scope'), 'fund' => 'Fundo', 'fund_id' => $fund]);
        $this->user->scopes()->attach($scope);
        $this->contract = Contract::create(['numero' => 'BUSCA-VISIVEL', 'fund_id' => $fund, 'ownership_resolved' => true,
            'objeto' => 'Manutenção de veículos', 'empenho' => '00019/26', 'ficha' => '00008', 'tipo' => 'Serviço', 'modalidade' => 'Pregão',
            'administrative_status' => 'ativo', 'data_contrato' => '2026-10-31', 'exercicio' => 2026,
            'vigencia_inicio' => '2026-10-10', 'vigencia_fim_original' => '2026-11-09', 'valor_total' => '1908.6300',
            'supplier_document_key' => '11222333000181', 'fornecedor_documento_snapshot' => '11222333000181', 'quality_issues' => []]);
        Contract::create(['numero' => 'BUSCA-SEGREDO', 'objeto' => 'Manutenção de veículos', 'empenho' => '00019/26', 'ficha' => '00008',
            'tipo' => 'TIPO-SECRETO', 'modalidade' => 'MODALIDADE-SECRETA', 'valor_total' => '1908.6300', 'quality_issues' => []]);
        $this->actingAs($this->user);
    }

    public function test_advanced_search_and_report_export_share_filters_scope_and_exact_totals(): void
    {
        $filters = ['object' => 'veículos', 'commitment' => '00019', 'budget' => '00008', 'type' => 'Serviço', 'modality' => 'Pregão',
            'status' => 'ativo', 'year' => 2026, 'from' => '2026-10-31', 'to' => '2026-10-31', 'min_value' => '1.908,6300', 'max_value' => '1908.6300'];
        $query = http_build_query($filters);
        $this->get('/contracts?'.$query)->assertOk()->assertSee('BUSCA-VISIVEL')->assertDontSee('BUSCA-SEGREDO')->assertDontSee('TIPO-SECRETO')->assertDontSee('MODALIDADE-SECRETA');
        $this->get('/reports?'.$query)->assertOk()->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $this->contract->id);
        $service = app(ContractReport::class);
        $this->assertSame(['count' => 1, 'total' => '1908.6300', 'missing' => 0, 'groups' => []], $service->summary($this->user, $service->filters($filters)));
        $response = $this->get('/reports/export/csv?'.$query)->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertStringContainsString('BUSCA-VISIVEL', file_get_contents($path));
        $this->assertStringNotContainsString('BUSCA-SEGREDO', file_get_contents($path));
        unlink($path);
        foreach (['object' => 'inexistente', 'commitment' => 'outro', 'budget' => 'outro', 'min_value' => '1908.6301', 'max_value' => '1908.6299'] as $key => $value) {
            foreach (['contracts', 'reports'] as $route) {
                $this->get('/'.$route.'?'.http_build_query([$key => $value]))->assertOk()->assertViewHas('contracts', fn ($rows) => $rows->total() === 0);
            }
        }
        $this->get('/reports?q=11.222.333%2F0001-81')->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $this->contract->id);
    }

    public function test_date_boundaries_and_invalid_filters(): void
    {
        $this->get('/reports?report=expiring&reference=2026-10-10&days=30')->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $this->contract->id);
        $this->get('/reports?validity=vigente&reference=2026-10-10')->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $this->contract->id);
        foreach (['contracts', 'reports'] as $route) {
            $this->get('/'.$route.'?date_field=end&from=2026-11-09&to=2026-11-09')->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $this->contract->id);
            $this->get('/'.$route.'?from=2026-11-01&to=2026-10-01')->assertSessionHasErrors('to');
            $this->get('/'.$route.'?min_value=2000&max_value=1000')->assertSessionHasErrors('max_value');
            $this->get('/'.$route.'?max_value=erro')->assertSessionHasErrors('max_value');
            $this->get('/'.$route.'?date_field=invalid')->assertSessionHasErrors('date_field');
        }
    }

    public function test_inspector_filter_groups_and_exports_preserve_contract_scope(): void
    {
        $this->contract->update(['nome_fiscal' => 'FISCAL ANA']);
        Contract::where('Contrato', 'BUSCA-SEGREDO')->update(['nome_fiscal' => 'FISCAL RESTRITO']);
        Contract::create(['numero' => 'OUTRO-FISCAL', 'fund_id' => $this->contract->fund_id,
            'ownership_resolved' => true, 'nome_fiscal' => 'FISCAL BRUNO', 'quality_issues' => []]);
        $filters = ['report' => 'inspector', 'inspector' => 'FISCAL ANA', 'columns' => ['number', 'inspector']];
        $this->get('/reports?'.http_build_query($filters))->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $this->contract->id);
        $service = app(ContractReport::class);
        $summary = $service->summary($this->user, $service->filters($filters));
        $this->assertSame(1, $summary['count']);
        $this->assertSame([['label' => 'FISCAL ANA', 'count' => 1, 'total' => '1908.6300']], $summary['groups']);
        $rows = new ReportRows;
        $this->assertSame($summary, $service->snapshot($this->user, $filters, $rows, false));
        $this->assertSame([['BUSCA-VISIVEL', 'FISCAL ANA']], iterator_to_array($rows));
        $response = $this->get('/reports/export/csv?'.http_build_query($filters))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $content = file_get_contents($path);
            $this->assertStringContainsString('FISCAL ANA', $content);
            $this->assertStringNotContainsString('OUTRO-FISCAL', $content);
            $this->assertStringNotContainsString('BUSCA-SEGREDO', $content);
        } finally {
            unlink($path);
        }
        $this->get('/reports?inspector=FISCAL%20RESTRITO')->assertOk()->assertDontSee('BUSCA-SEGREDO');
    }
}
