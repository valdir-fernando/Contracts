<?php

namespace Tests\Feature;

use App\Infrastructure\Documents\InspectorDecree;
use App\Infrastructure\Inspectors\InspectorRegistration;
use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InspectorDecreeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'Password123', 'user_type' => 'Administrador']);
        DB::table('contract_funds')->insert(['name' => 'MUNICIPIO DE NIQUELÂNDIA', 'name_key' => 'MUNICIPIO DE NIQUELANDIA',
            'document' => '12.345.678/0001-90', 'raw_payload' => json_encode(['End_Fundo' => 'Endereço municipal'])]);
        DB::table('contract_funds')->insert(['name' => 'FUNDO SAÚDE', 'name_key' => 'FUNDO SAUDE', 'document' => '99.999.999/0001-99',
            'raw_payload' => json_encode(['End_Fundo' => 'Endereço do fundo'])]);
        DB::table('tb_Gestor')->insert(['name' => 'PREFEITO EXEMPLO', 'name_key' => 'PREFEITO EXEMPLO', 'fund_name_key' => 'MUNICIPIO DE NIQUELANDIA',
            'raw_payload' => json_encode(['Cargo' => 'Prefeito Municipal', 'Assinatura' => 0])]);
        DB::table('tb_Gestor')->insert(['name' => 'GESTORA DELEGADA', 'name_key' => 'GESTORA DELEGADA', 'fund_name_key' => 'MUNICIPIO DE NIQUELANDIA',
            'raw_payload' => json_encode(['Cargo' => 'Secretária', 'Assinatura' => 1])]);
        $this->inspector = $this->addInspector('FISCAL EXEMPLO');
        $this->actingAs($this->admin);
    }

    private function addInspector(string $name): int
    {
        return DB::table('contract_inspectors')->insertGetId(['name' => $name, 'name_key' => $name,
            'fund_name_key' => 'FUNDO SAUDE', 'department_name_key' => '',
            'raw_payload' => json_encode(['Decreto' => '10/2026', 'Data_Decreto' => '2026-10-18', 'CPF_Fiscal' => '52998224725'])]);
    }

    private function input(InspectorDecree $service): array
    {
        $prepared = $service->prepare($this->admin);

        return ['revision' => $prepared['revision'], 'expires' => $prepared['expires'], 'inspector_id' => $this->inspector];
    }

    private function capture(?\Closure $during = null): InspectorDecree
    {
        return new class(app(LockActor::class), $during) extends InspectorDecree
        {
            public function __construct($actors, private ?\Closure $during)
            {
                parent::__construct($actors);
            }

            protected function render(string $html): string
            {
                ($this->during ?? static fn () => null)();

                return $html;
            }
        };
    }

    public function test_individual_uses_municipality_and_mayor_even_for_other_fund_and_escapes_input(): void
    {
        $service = $this->capture();
        DB::table('contract_inspectors')->where('id', $this->inspector)->update(['name' => '<script>FISCAL EXEMPLO</script>']);
        $input = $this->input($service);
        $html = $service->generate($this->admin, $input);
        $this->assertStringContainsString('PREFEITO EXEMPLO', $html);
        $this->assertStringContainsString('Endereço municipal', $html);
        $this->assertStringContainsString('FUNDO SAÚDE', $html);
        $this->assertStringContainsString('18 de outubro de 2026', $html);
        $this->assertStringContainsString('&lt;SCRIPT&gt;', $html);
        foreach (['GESTORA DELEGADA', 'Endereço do fundo', '52998224725', '{{', '<script>'] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
        $this->assertDatabaseHas('activity_events', ['action' => 'inspector_decree_generated', 'actor_id' => $this->admin->id]);
    }

    public function test_collective_renders_all_selected_fiscals_and_no_editorial_instruction(): void
    {
        $second = $this->addInspector('OUTRO FISCAL');
        $service = $this->capture();
        $html = $service->generate($this->admin, $this->input($service));
        $this->assertStringContainsString('ANEXO ÚNICO', $html);
        $this->assertStringContainsString('FISCAL EXEMPLO', $html);
        $this->assertStringContainsString('OUTRO FISCAL', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringNotContainsString('Uma linha por fiscal', $html);
        $this->assertStringNotContainsString('{{', $html);
        $metadata = ActivityEvent::first()->metadata;
        $this->assertSame('collective', $metadata['mode']);
        $this->assertSame([$this->inspector, $second], $metadata['inspector_ids']);
        foreach (['Matrícula', 'Cargo', 'delimitação da fiscalização'] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
    }

    public function test_routes_render_form_and_download_real_pdf_privately(): void
    {
        $this->get(route('catalogs.inspectors'))->assertOk()->assertSee(route('inspectors.decree.create'), false);
        $this->get(route('inspectors.decree.create'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('52998224725');
        $this->get(route('inspectors.decree.create', ['inspector' => $this->inspector]))->assertOk()
            ->assertSee('10/2026')->assertSee('18/10/2026')->assertDontSee('Matrícula funcional')->assertDontSee('name="mode"', false);
        $this->get(route('catalogs.inspectors', ['edit' => $this->inspector]))->assertOk()->assertSee('form="inspector-decree-direct"', false);
        $input = ['inspector' => $this->inspector, 'inspector_revision' => app(InspectorRegistration::class)->revision(DB::table('contract_inspectors')->find($this->inspector))];
        $response = $this->post(route('inspectors.decree.store'), $input)->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_group_is_automatic_and_excludes_other_decrees_inactive_and_deleted_fiscals(): void
    {
        $municipal = $this->addInspector('FISCAL DA SECRETARIA');
        DB::table('contract_departments')->insert(['name' => 'Secretaria de Educação', 'name_key' => 'EDUCACAO', 'raw_payload' => '{}']);
        DB::table('contract_inspectors')->where('id', $municipal)->update(['fund_name_key' => 'MUNICIPIO DE NIQUELANDIA', 'department_name_key' => 'EDUCACAO',
            'raw_payload' => json_encode(['Decreto' => ' 10/2026 ', 'Data_Decreto' => '18/10/2026'])]);
        foreach ([['Decreto' => '10/2026', 'Data_Decreto' => '2025-10-18'], ['Decreto' => '11/2026', 'Data_Decreto' => '2026-10-18'],
            ['Decreto' => '10/2026', 'Data_Decreto' => '2026-10-18', 'Situacao' => 'Inativo']] as $index => $payload) {
            $id = $this->addInspector('FORA DO DECRETO '.$index);
            DB::table('contract_inspectors')->where('id', $id)->update(['raw_payload' => json_encode($payload)]);
        }
        $deleted = $this->addInspector('EXCLUÍDO');
        DB::table('contract_inspectors')->where('id', $deleted)->update(['deleted_at' => now()]);
        $service = $this->capture();
        $html = $service->generate($this->admin, $this->input($service) + ['mode' => 'individual', 'ids' => [$this->inspector], 'number' => 'FORJADO']);
        $this->assertStringContainsString('FISCAL DA SECRETARIA', $html);
        $this->assertStringContainsString('Secretaria de Educação', $html);
        $this->assertStringContainsString('FUNDO SAÚDE', $html);
        $this->assertStringNotContainsString('FORA DO DECRETO', $html);
        $this->assertStringNotContainsString('EXCLUÍDO', $html);
        $this->assertStringNotContainsString('FORJADO', $html);
        $this->assertSame([$this->inspector, $municipal], ActivityEvent::first()->metadata['inspector_ids']);
    }

    public function test_direct_emission_rejects_changed_anchor_and_cannot_omit_unresolved_group_members(): void
    {
        $revision = app(InspectorRegistration::class)->revision(DB::table('contract_inspectors')->find($this->inspector));
        DB::table('contract_inspectors')->where('id', $this->inspector)->update(['name' => 'ALTERADO']);
        $this->postJson(route('inspectors.decree.store'), ['inspector' => $this->inspector, 'inspector_revision' => $revision])->assertConflict();
        $second = $this->addInspector('SEM FUNDO VÁLIDO');
        DB::table('contract_inspectors')->where('id', $second)->update(['fund_name_key' => 'INEXISTENTE']);
        $third = $this->addInspector('SEM SECRETARIA VÁLIDA');
        DB::table('contract_inspectors')->where('id', $third)->update(['fund_name_key' => 'MUNICIPIO DE NIQUELANDIA', 'department_name_key' => 'INEXISTENTE']);
        $this->postJson(route('inspectors.decree.store'), $this->input(app(InspectorDecree::class)))->assertUnprocessable()
            ->assertJsonPath('message', 'Os seguintes fiscais deste decreto possuem Fundo ou Secretaria indisponível ou ambíguo: SEM FUNDO VÁLIDO; SEM SECRETARIA VÁLIDA. Corrija os vínculos antes de emitir.');
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_non_municipal_fiscal_does_not_require_or_expose_legacy_department(): void
    {
        DB::table('contract_inspectors')->where('id', $this->inspector)->update(['department_name_key' => 'SECRETARIA LEGADA INEXISTENTE']);
        $service = $this->capture();
        $html = $service->generate($this->admin, $this->input($service));
        $this->assertStringContainsString('FUNDO SAÚDE', $html);
        $this->assertStringNotContainsString('SECRETARIA LEGADA INEXISTENTE', $html);
        $this->assertSame('SECRETARIA LEGADA INEXISTENTE', DB::table('contract_inspectors')->find($this->inspector)->department_name_key);
        $this->get(route('catalogs.inspectors'))->assertOk()->assertSee('Não se aplica')->assertDontSee('SECRETARIA LEGADA INEXISTENTE');
    }

    public function test_non_admin_and_stale_actor_cannot_prepare_or_generate(): void
    {
        $input = $this->input(app(InspectorDecree::class));
        $user = User::create(['name' => 'User', 'username' => 'USER', 'password' => 'Password123']);
        $this->actingAs($user)->get(route('inspectors.decree.create'))->assertForbidden();
        $this->postJson(route('inspectors.decree.store'), $input)->assertForbidden();
        $this->actingAs($this->admin);
        DB::table('users')->where('id', $this->admin->id)->update(['session_version' => 4]);
        $this->postJson(route('inspectors.decree.store'), $input)->assertForbidden();
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_missing_or_ambiguous_mayor_blocks_decree_and_delegation_is_not_fallback(): void
    {
        DB::table('tb_Gestor')->where('name', 'PREFEITO EXEMPLO')->update(['deleted_at' => now()]);
        $this->get(route('inspectors.decree.create'))->assertUnprocessable();
        DB::table('tb_Gestor')->where('name', 'PREFEITO EXEMPLO')->update(['deleted_at' => null]);
        DB::table('tb_Gestor')->insert(['name' => 'OUTRO PREFEITO', 'name_key' => 'OUTRO PREFEITO', 'fund_name_key' => 'MUNICIPIO DE NIQUELANDIA', 'raw_payload' => '{"Cargo":"Prefeito"}']);
        $this->get(route('inspectors.decree.create'))->assertUnprocessable();
    }

    public function test_invalid_selection_and_missing_or_invalid_saved_decree_are_rejected(): void
    {
        $service = app(InspectorDecree::class);
        $input = $this->input($service);
        $this->postJson(route('inspectors.decree.store'), array_replace($input, ['inspector_id' => 9999]))->assertUnprocessable();
        foreach ([['Decreto' => '', 'Data_Decreto' => '2026-10-18'], ['Decreto' => '10/2026', 'Data_Decreto' => 'inválida']] as $raw) {
            DB::table('contract_inspectors')->where('id', $this->inspector)->update(['raw_payload' => json_encode($raw)]);
            $this->postJson(route('inspectors.decree.store'), $this->input($service))->assertUnprocessable();
        }
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_changed_deleted_and_expired_sources_are_rejected(): void
    {
        $input = $this->input(app(InspectorDecree::class));
        DB::table('contract_inspectors')->where('id', $this->inspector)->update(['deleted_at' => now()]);
        $this->postJson(route('inspectors.decree.store'), $input)->assertConflict();
        DB::table('contract_inspectors')->where('id', $this->inspector)->update(['deleted_at' => null]);
        $input = $this->input(app(InspectorDecree::class));
        $this->travel(31)->minutes();
        $this->postJson(route('inspectors.decree.store'), $input)->assertConflict();
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_change_during_render_prevents_delivery_and_audit(): void
    {
        $service = $this->capture(fn () => DB::table('tb_Gestor')->where('name', 'PREFEITO EXEMPLO')->update(['name' => 'ALTERADO']));
        try {
            $service->generate($this->admin, $this->input($service));
            $this->fail('Expected conflict');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_audit_failure_prevents_delivery(): void
    {
        $service = $this->capture();
        $input = $this->input($service);
        ActivityEvent::creating(static fn () => throw new RuntimeException('Audit unavailable'));
        try {
            $service->generate($this->admin, $input);
            $this->fail('Expected audit failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('activity_events', 0);
    }
}
