<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\UpdateAtaAdmission;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UpdateAtaAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $fund;

    private int $ata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actor = User::create(['name' => 'Admin', 'username' => 'ATA-ADMISSION', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $this->ata = DB::table('tb_ATA')->insertGetId(['ATA' => '107/2025', 'PROCESSO' => '2025007440', 'LICITACAO' => 'Pregão',
            'N_LICIT' => '12', 'fund_id' => $this->fund, 'CNPJ_FUNDO' => '04252011000110', 'source_files' => '[]',
            'quality_issues' => '[]', 'import_hash' => str_repeat('a', 64), 'version' => 1], 'ID');
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund]);
    }

    private function change(array $extra = [])
    {
        return $this->patchJson(route('atas.admission.update', ['ata' => $this->ata]),
            array_replace(['version' => 1, 'admitir_adesao' => 'SIM'], $extra));
    }

    public function test_existing_ata_admission_is_editable_and_only_that_value_is_updated_with_audit(): void
    {
        $this->get(route('atas.create'))->assertOk()->assertSee('name="admitir_adesao"', false)
            ->assertSee('data-update-url', false)->assertDontSee('data-ata-admission-url', false);
        $before = (array) DB::table('tb_ATA')->sole();
        foreach (['SIM', 'NAO', null] as $index => $value) {
            $this->change(['version' => $index + 1, 'admitir_adesao' => $value, 'PROCESSO' => 'FORJADO', 'actor_id' => 999])->assertOk()
                ->assertExactJson(['id' => $this->ata, 'number' => '107/2025', 'version' => $index + 2, 'admitir_adesao' => $value])
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'ADMITIR_ADESAO' => $value, 'version' => $index + 2]);
            $event = ActivityEvent::where('action', 'ata_admission_updated')->orderByDesc('id')->firstOrFail();
            $this->assertSame($this->actor->id, $event->actor_id);
            $this->assertSame($value, $event->metadata['after']['ADMITIR_ADESAO']);
        }
        $after = (array) DB::table('tb_ATA')->sole();
        foreach (['version', 'updated_at', 'ADMITIR_ADESAO'] as $field) {
            unset($before[$field], $after[$field]);
        }
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('activity_events', 3);
    }

    public function test_invalid_missing_stale_and_unchanged_choices_do_not_create_updates(): void
    {
        $this->patchJson(route('atas.admission.update', ['ata' => $this->ata]), ['version' => 1])->assertUnprocessable();
        $this->change(['admitir_adesao' => 'TALVEZ'])->assertUnprocessable()->assertJsonValidationErrors('admitir_adesao');
        $this->change(['admitir_adesao' => null])->assertOk()->assertJsonPath('version', 1);
        $this->assertDatabaseCount('activity_events', 0);
        $this->change()->assertOk();
        $this->change(['admitir_adesao' => 'NAO'])->assertConflict()->assertJsonMissingPath('admitir_adesao');
        $this->change(['version' => 2])->assertOk()->assertJsonPath('version', 2);
        $this->assertDatabaseCount('activity_events', 1);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'ADMITIR_ADESAO' => 'SIM', 'version' => 2]);
    }

    public function test_unauthorized_fund_and_guests_cannot_change_admission(): void
    {
        $other = DB::table('contract_funds')->insertGetId(['name' => 'Outro', 'name_key' => 'OUTRO', 'raw_payload' => '{}']);
        DB::table('users')->where('id', $this->actor->id)->update(['user_type' => 'Usuario']);
        $scope = AccessScope::create(['scope_key' => 'ADMISSION-FUND', 'fund_id' => $this->fund, 'fund' => 'Fundo']);
        $this->actor->scopes()->attach($scope);
        $this->actingAs($this->actor->fresh());
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['fund_id' => $other]);
        $this->change(['fund_id' => $this->fund, 'user_type' => 'Administrador'])->assertForbidden();
        auth()->logout();
        $this->change()->assertUnauthorized();
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'ADMITIR_ADESAO' => null, 'version' => 1]);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_deleted_ata_and_stale_actor_session_are_rejected(): void
    {
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['deleted_at' => now()]);
        $this->change()->assertNotFound();
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['deleted_at' => null]);
        DB::table('users')->where('id', $this->actor->id)->update(['session_version' => 1]);
        try {
            app(UpdateAtaAdmission::class)->update($this->actor, $this->ata, ['version' => 1, 'admitir_adesao' => 'SIM'], $this->fund);
            $this->fail('Stale actor must be rejected.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_audit_failure_rolls_back_choice_and_version(): void
    {
        Event::listen('eloquent.creating: '.ActivityEvent::class, function () {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            app(UpdateAtaAdmission::class)->update($this->actor, $this->ata, ['version' => 1, 'admitir_adesao' => 'SIM'], $this->fund);
            $this->fail('Audit failure must abort update.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ActivityEvent::class);
        }
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'ADMITIR_ADESAO' => null, 'version' => 1]);
        $this->assertDatabaseCount('activity_events', 0);
    }
}
