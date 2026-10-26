<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\User;
use App\Support\BrazilianLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class BrazilianLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_rules_return_portuguese_labels_and_messages(): void
    {
        $validator = Validator::make(['days' => 0, 'file' => 'text', 'reference' => 'invalid'], ['days' => 'integer|min:1', 'file' => 'file', 'reference' => 'date_format:Y-m-d', 'current_password' => 'required']);
        $errors = $validator->errors();
        $this->assertSame('O campo antecedência em dias deve ser no mínimo 1.', $errors->first('days'));
        $this->assertSame('O campo arquivo deve ser um arquivo.', $errors->first('file'));
        $this->assertSame('Informe uma data válida em data de referência.', $errors->first('reference'));
        $this->assertSame('O campo senha atual é obrigatório.', $errors->first('current_password'));
    }

    public function test_display_preserves_decimal_precision_and_localizes_reordered_columns(): void
    {
        $this->assertSame('-1.234.567,8901', BrazilianLocale::decimal('-1234567.8901', true));
        $this->assertSame('9999999999999999,9999', BrazilianLocale::decimal('9999999999999999.9999'));
        $this->assertSame(['31/12/2026', '1908,6300', '001/26'], BrazilianLocale::reportRow(['2026-12-31', '1908.6300', '001/26'], ['columns' => ['ends_at', 'initial_amount', 'number']]));
        $this->assertSame('Ação: Cadastro atualizado; Data inicial: 11/10/2026', BrazilianLocale::filters(['action' => 'updated', 'from' => '2026-10-11']));
        $this->assertSame('Vínculo confirmado manualmente', BrazilianLocale::state('manual_confirmed'));
    }

    public function test_calendar_month_selector_uses_portuguese_and_validates_both_parts(): void
    {
        $this->withoutVite();
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo teste', 'name_key' => 'FUNDO TESTE', 'raw_payload' => '{}']);
        Contract::create(['numero' => 'DEZEMBRO', 'fund_id' => $fund, 'vigencia_inicio' => '2026-01-01', 'vigencia_fim_original' => '2026-12-31', 'vigencia_fim_atual' => '2026-12-31', 'quality_issues' => []]);
        $this->actingAs($admin)->withSession(['fund_id' => $fund])->get('/calendar?month_number=12&calendar_year=2026')->assertOk()->assertSee('Dezembro')->assertSee('DEZEMBRO')->assertSee('31/12/2026')->assertDontSee('type="month"', false);
        $this->get('/calendar?month_number=13&calendar_year=2026')->assertSessionHasErrors('month_number');
        $this->get('/calendar?month_number=12&calendar_year=2200')->assertSessionHasErrors('calendar_year');
        $this->get('/calendar?month_number=12')->assertSessionHasErrors('calendar_year');
    }

    public function test_error_pages_are_portuguese_and_do_not_expose_exception_details(): void
    {
        config(['app.debug' => false]);
        $this->get('/pagina-inexistente')->assertNotFound()->assertSee('Página não encontrada')->assertSee('lang="pt-BR"', false)->assertDontSee('Not Found');
        foreach ([403, 419, 429, 500, 503] as $code) {
            $this->assertStringContainsString('Voltar ao início', view('errors.'.$code)->render());
        }
    }
}
