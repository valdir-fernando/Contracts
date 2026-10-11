<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\User;
use App\Services\Reports\ContractReport;
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
        $this->get('/reports?'.$query)->assertOk()->assertSee('BUSCA-VISIVEL')->assertDontSee('BUSCA-SEGREDO');
        $service = app(ContractReport::class);
        $this->assertSame(['count' => 1, 'total' => '1908.6300', 'missing' => 0, 'groups' => []], $service->summary($this->user, $service->filters($filters)));
        $response = $this->get('/reports/export/csv?'.$query)->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertStringContainsString('BUSCA-VISIVEL', file_get_contents($path));
        $this->assertStringNotContainsString('BUSCA-SEGREDO', file_get_contents($path));
        unlink($path);
        foreach (['object' => 'inexistente', 'commitment' => 'outro', 'budget' => 'outro', 'min_value' => '1908.6301', 'max_value' => '1908.6299'] as $key => $value) {
            foreach (['contracts', 'reports'] as $route) {
                $this->get('/'.$route.'?'.http_build_query([$key => $value]))->assertOk()->assertDontSee('BUSCA-VISIVEL');
            }
        }
        $this->get('/reports?q=11.222.333%2F0001-81')->assertSee('BUSCA-VISIVEL');
    }

    public function test_date_boundaries_and_invalid_filters(): void
    {
        $this->get('/reports?report=expiring&reference=2026-10-10&days=30')->assertSee('BUSCA-VISIVEL');
        $this->get('/reports?validity=vigente&reference=2026-10-10')->assertSee('BUSCA-VISIVEL');
        foreach (['contracts', 'reports'] as $route) {
            $this->get('/'.$route.'?date_field=end&from=2026-11-09&to=2026-11-09')->assertSee('BUSCA-VISIVEL');
            $this->get('/'.$route.'?from=2026-11-01&to=2026-10-01')->assertSessionHasErrors('to');
            $this->get('/'.$route.'?min_value=2000&max_value=1000')->assertSessionHasErrors('max_value');
            $this->get('/'.$route.'?max_value=erro')->assertSessionHasErrors('max_value');
            $this->get('/'.$route.'?date_field=invalid')->assertSessionHasErrors('date_field');
        }
    }
}
