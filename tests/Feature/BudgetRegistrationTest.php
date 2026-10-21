<?php

namespace Tests\Feature;

use App\Infrastructure\Budgets\BudgetRegistration;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class BudgetRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $fundId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'BUDGET_ADMIN', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $this->fundId = DB::table('contract_funds')->insertGetId(['name' => 'FMS', 'name_key' => 'FMS', 'raw_payload' => '{}']);
        $this->actingAs($this->admin);
    }

    private function fields(): array
    {
        return ['fund_id' => $this->fundId, 'Ficha' => '20250029', 'Orgao' => '02', 'Gestao' => '02', 'Unidade' => '0217',
            'Funcao' => '04', 'SubFunc' => '122', 'Programa' => '7008', 'Projeto' => '8040', 'Codigo' => '319094', 'Fonte' => '100', 'Descricao' => 'INDENIZACOES RESTITUICOES TRABALHIS', 'Dotacao' => 'FORGED', 'Fundo' => 'FORGED'];
    }

    public function test_create_and_edit_render_one_form_with_its_actions_inside(): void
    {
        $id = app(BudgetRegistration::class)->save($this->admin, null, $this->fields());

        foreach (['/catalogs/budgets/create', '/catalogs/budgets/create?edit='.$id] as $url) {
            $response = $this->get($url)->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
            $xpath = new \DOMXPath($document);
            $form = '//form[@id="budget-registration-form"]';

            $this->assertSame(1, $xpath->query($form)->length);
            $this->assertSame(1, $xpath->query($form.'//button[@type="submit"]')->length);
            $this->assertSame(1, $xpath->query($form.'//button[@data-budget-delete]')->length);
            $this->assertSame(1, $xpath->query($form.'//input[@data-budget-code]')->length);
        }
    }

    public function test_budget_menu_opens_registration_and_old_listing_redirects(): void
    {
        $this->get('/catalogs/list/budget')->assertRedirect(route('catalogs.budgets.create'));
        $response = $this->get('/catalogs/budgets/create')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $links = $xpath->query('//a[span[text()="Dotação"]]');

        $this->assertSame(1, $links->length);
        $this->assertSame(route('catalogs.budgets.create'), $links->item(0)->getAttribute('href'));
        $this->assertSame('page', $links->item(0)->getAttribute('aria-current'));
        $response->assertDontSee(route('catalogs.list', 'budget'), false);
    }

    public function test_budget_panel_renders_without_sidebar_and_preserves_save_flow(): void
    {
        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get('/catalogs/budgets/create?ficha=0001')
            ->assertOk()->assertSee('budget-panel-content', false)
            ->assertSee('value="0001"', false)->assertSee('budget-registration-form', false)
            ->assertDontSee('nav-catalogs', false);
        $response = $this->post('/catalogs/budgets', $this->fields())->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()
            ->assertSee('data-budget-saved', false)->assertSee('budget-panel-content', false);
    }

    public function test_save_edit_and_soft_delete_keep_exact_code_revision_and_history(): void
    {
        $this->post('/catalogs/budgets', $this->fields())->assertRedirect();
        $record = DB::table('tb_Dotacao')->sole();
        $this->assertSame('02.0217.04.122.7008.8040.319094(100)20250029', $record->Dotacao);
        $this->assertSame('FMS', $record->Fundo);
        $this->get('/catalogs/budgets/create?edit='.$record->ID_Dotacao)->assertOk()->assertSee('Dotação Orçamentária')->assertSee('readonly', false);
        $revision = app(BudgetRegistration::class)->revision($record);
        $this->put('/catalogs/budgets/'.$record->ID_Dotacao, $this->fields() + ['revision' => 'stale'])->assertConflict();
        $this->put('/catalogs/budgets/'.$record->ID_Dotacao, array_replace($this->fields(), ['revision' => $revision, 'Codigo' => '319091']))->assertRedirect();
        $this->assertDatabaseHas('tb_Dotacao', ['ID_Dotacao' => $record->ID_Dotacao, 'Dotacao' => '02.0217.04.122.7008.8040.319091(100)20250029']);
        $this->delete('/catalogs/budgets/'.$record->ID_Dotacao, ['revision' => $revision, 'confirm_delete' => 1])->assertConflict();
        $current = DB::table('tb_Dotacao')->sole();
        $revision = app(BudgetRegistration::class)->revision($current);
        $this->delete('/catalogs/budgets/'.$record->ID_Dotacao, ['revision' => $revision])->assertSessionHasErrors('confirm_delete');
        $this->delete('/catalogs/budgets/'.$record->ID_Dotacao, ['revision' => $revision, 'confirm_delete' => 1])->assertRedirect();
        $this->assertNotNull(DB::table('tb_Dotacao')->sole()->deleted_at);
        $this->assertDatabaseCount('tb_Dotacao', 1);
        $this->assertDatabaseHas('activity_events', ['action' => 'budget_deleted', 'actor_id' => $this->admin->id]);
        $this->get('/catalogs/budgets/create?edit='.$record->ID_Dotacao)->assertNotFound();
        $this->get('/catalogs/list/budget')->assertDontSee('20250029');
        $this->post('/catalogs/budgets', $this->fields())->assertSessionHasErrors('Ficha');
    }

    public function test_invalid_codes_duplicate_and_unauthorized_actor_cannot_write(): void
    {
        $this->post('/catalogs/budgets', array_replace($this->fields(), ['Orgao' => '2.0']))->assertSessionHasErrors('Orgao');
        $this->assertDatabaseCount('tb_Dotacao', 0);
        $this->post('/catalogs/budgets', $this->fields())->assertRedirect();
        $this->post('/catalogs/budgets', $this->fields())->assertSessionHasErrors('Ficha');
        $user = User::create(['name' => 'Restricted', 'username' => 'BUDGET_NO_PERMISSION', 'password' => 'TestPassword12345']);
        $this->actingAs($user)->post('/catalogs/budgets', $this->fields())->assertForbidden();
        $this->actingAs($user)->get('/catalogs/budgets/create')->assertForbidden();
        $this->assertDatabaseCount('tb_Dotacao', 1);
    }

    public function test_description_only_edit_preserves_historical_code_until_components_change(): void
    {
        $id = app(BudgetRegistration::class)->save($this->admin, null, $this->fields());
        DB::table('tb_Dotacao')->where('ID_Dotacao', $id)->update(['Dotacao' => 'LEGACY-FORMAT']);
        $record = DB::table('tb_Dotacao')->sole();
        $revision = app(BudgetRegistration::class)->revision($record);
        $this->get('/catalogs/budgets/create?edit='.$id)->assertOk()->assertSee('LEGACY-FORMAT');
        $this->put('/catalogs/budgets/'.$id, array_replace($this->fields(), ['revision' => $revision, 'Descricao' => 'Updated description']))->assertRedirect();
        $this->assertDatabaseHas('tb_Dotacao', ['ID_Dotacao' => $id, 'Dotacao' => 'LEGACY-FORMAT', 'Descricao' => 'Updated description']);
    }

    public function test_ficha_lookup_requires_access_ignores_deleted_and_rejects_ambiguity(): void
    {
        $id = app(BudgetRegistration::class)->save($this->admin, null, $this->fields());
        $url = '/catalogs/budgets/lookup?ficha=20250029';
        $this->getJson($url)->assertOk()->assertExactJson(['status' => 'found', 'url' => route('catalogs.budgets.create', ['edit' => $id])])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/catalogs/budgets/create')->assertOk()->assertDontSee('Buscar por ficha ou descrição')->assertDontSee('aria-label="Buscar dotação"', false);
        $this->getJson('/catalogs/budgets/lookup?ficha=999')->assertOk()->assertExactJson(['status' => 'not_found']);
        $duplicate = DB::table('tb_Dotacao')->insertGetId(['Ficha' => '20250029', 'Fundo' => 'FMS'], 'ID_Dotacao');
        $this->getJson($url)->assertConflict()->assertJsonMissingPath('url');
        DB::table('tb_Dotacao')->update(['deleted_at' => now()]);
        $this->getJson($url)->assertOk()->assertExactJson(['status' => 'not_found']);
        $this->getJson('/catalogs/budgets/lookup?ficha[]=1')->assertUnprocessable();
        $user = User::create(['name' => 'Restricted', 'username' => 'LOOKUP_BUDGET_DENIED', 'password' => 'TestPassword12345']);
        $this->actingAs($user)->getJson($url)->assertForbidden()->assertJsonMissingPath('url');
    }

    public function test_department_is_only_writable_for_municipality_and_filter_is_scoped_on_server(): void
    {
        $municipal = DB::table('contract_funds')->insertGetId(['name' => 'MUNICIPIO DE NIQUELÂNDIA', 'name_key' => 'MUNICIPIO DE NIQUELANDIA', 'raw_payload' => '{}']);
        $department = DB::table('contract_departments')->insertGetId(['name' => 'SECRETARIA MUNICIPAL DE URBANISMO', 'name_key' => 'SECRETARIA MUNICIPAL DE URBANISMO', 'raw_payload' => '{}']);
        $otherDepartment = DB::table('contract_departments')->insertGetId(['name' => 'SAUDE', 'name_key' => 'SAUDE', 'raw_payload' => '{}']);
        $this->post('/catalogs/budgets', $this->fields() + ['department_id' => $department])->assertSessionHasErrors('department_id');
        $this->assertDatabaseCount('tb_Dotacao', 0);
        $this->post('/catalogs/budgets', array_replace($this->fields(), ['fund_id' => $municipal, 'department_id' => $department]))->assertRedirect();
        $this->assertDatabaseHas('tb_Dotacao', ['Secretaria' => 'SECRETARIA MUNICIPAL DE URBANISMO']);
        DB::table('tb_Dotacao')->insert([
            ['Ficha' => 'ALIAS', 'Descricao' => 'Legacy alias', 'Fundo' => 'MUNICIPIO DE NIQUELANDIA', 'Secretaria' => 'SEC MUNICIPAL DE URBANISMO'],
            ['Ficha' => 'OTHER-DEPT', 'Descricao' => 'Other department', 'Fundo' => 'MUNICIPIO DE NIQUELÂNDIA', 'Secretaria' => 'SAUDE'],
            ['Ficha' => 'OTHER-FUND', 'Descricao' => 'Other fund', 'Fundo' => 'FMS', 'Secretaria' => 'SECRETARIA MUNICIPAL DE URBANISMO'],
        ]);
        $url = '/catalogs/budgets/create?filter_fund_id='.$municipal.'&filter_department_id='.$department;
        $this->get($url)->assertOk()->assertSee('20250029')->assertSee('ALIAS')->assertDontSee('OTHER-DEPT')->assertDontSee('OTHER-FUND');
        $json = $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('html');
        $this->assertStringContainsString('20250029', $json);
        $this->assertStringNotContainsString('OTHER-DEPT', $json);
        $this->getJson('/catalogs/budgets/create?filter_fund_id='.$this->fundId.'&filter_department_id='.$department)->assertUnprocessable();
        $this->getJson('/catalogs/budgets/create?filter_fund_id='.$municipal.'&filter_department_id=99999')->assertForbidden();
    }

    public function test_audit_failure_rolls_back_budget_and_soft_delete(): void
    {
        $id = app(BudgetRegistration::class)->save($this->admin, null, $this->fields());
        $record = DB::table('tb_Dotacao')->sole();
        ActivityEvent::creating(fn () => throw new RuntimeException('audit failure'));
        try {
            app(BudgetRegistration::class)->save($this->admin, $id, ['revision' => app(BudgetRegistration::class)->revision($record), 'confirm_delete' => 1], true);
            $this->fail('Expected audit failure');
        } catch (RuntimeException $error) {
            $this->assertSame('audit failure', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertNull(DB::table('tb_Dotacao')->sole()->deleted_at);
    }

    public function test_delegated_budget_access_cannot_read_change_delete_or_transfer_outside_scope(): void
    {
        $id = app(BudgetRegistration::class)->save($this->admin, null, $this->fields());
        $record = DB::table('tb_Dotacao')->sole();
        $otherFund = DB::table('contract_funds')->insertGetId(['name' => 'OTHER', 'name_key' => 'OTHER', 'raw_payload' => '{}']);
        $user = User::create(['name' => 'Delegated', 'username' => 'BUDGET_DELEGATED', 'password' => 'TestPassword12345']);
        $user->forceFill(['catalog_permissions' => ['budget']])->save();
        $scope = AccessScope::create(['scope_key' => 'budget-other', 'fund_id' => $otherFund, 'fund' => 'OTHER']);
        $user->scopes()->attach($scope);
        $this->actingAs($user)->get('/catalogs/budgets/create?edit='.$id)->assertNotFound();
        $revision = app(BudgetRegistration::class)->revision($record);
        $this->put('/catalogs/budgets/'.$id, $this->fields() + ['revision' => $revision])->assertNotFound();
        $this->delete('/catalogs/budgets/'.$id, ['revision' => $revision, 'confirm_delete' => 1])->assertNotFound();
        $this->post('/catalogs/budgets', array_replace($this->fields(), ['Ficha' => '999']))->assertForbidden();
        $this->get('/catalogs/budgets/create')->assertOk()->assertDontSee('20250029');
        $this->assertSame((array) $record, (array) DB::table('tb_Dotacao')->sole());
    }
}
