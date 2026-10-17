<?php

namespace Tests\Feature;

use App\Infrastructure\Documents\DocumentSettings;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DocumentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']);
        $this->actingAs($this->admin);
    }

    private function input(array $extra = []): array
    {
        return $extra + ['administration_start_year' => '2029', 'administration_end_year' => '2032',
            'settings_version' => 1, 'settings_reason' => 'Início da nova administração municipal.'];
    }

    public function test_initial_administration_is_persistent_and_exposed_to_document_headers(): void
    {
        $this->assertSame(['administration' => '2025/2028'], app(DocumentSettings::class)->headerData());
        $this->get('/catalogs')->assertOk()->assertSee('Administração nos documentos')->assertSee('2025/2028')
            ->assertSee('name="administration_start_year" value="2025"', false)
            ->assertSee('name="administration_end_year" value="2028"', false);
        $this->assertDatabaseCount('document_settings', 1);
    }

    public function test_administrator_updates_shared_header_with_transactional_audit(): void
    {
        $previousHeader = app(DocumentSettings::class)->headerData();
        $this->put('/document-settings', $this->input(['updated_by' => 999, 'version' => 999]))
            ->assertRedirect('/catalogs')->assertSessionHasNoErrors();
        $this->assertSame(['administration' => '2029/2032'], app(DocumentSettings::class)->headerData());
        $this->assertSame(['administration' => '2025/2028'], $previousHeader);
        $this->assertDatabaseHas('document_settings', ['id' => 1, 'version' => 2, 'updated_by' => $this->admin->id]);
        $audit = ActivityEvent::sole();
        $this->assertSame('document_settings_updated', $audit->action);
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame(['before' => '2025/2028', 'after' => '2029/2032', 'reason' => $this->input()['settings_reason']], $audit->metadata);
    }

    public function test_invalid_years_and_missing_justification_do_not_change_settings(): void
    {
        foreach ([['administration_start_year' => 1899], ['administration_end_year' => 10000],
            ['administration_end_year' => 2028], ['administration_start_year' => 'invalid'],
            ['settings_reason' => ''], ['settings_version' => null]] as $invalid) {
            $this->from('/catalogs')->put('/document-settings', $this->input($invalid))->assertRedirect('/catalogs')
                ->assertSessionHasErrors(array_keys($invalid), null, 'document-settings');
        }
        $this->assertSame(['administration' => '2025/2028'], app(DocumentSettings::class)->headerData());
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_stale_form_cannot_overwrite_a_more_recent_administration(): void
    {
        $this->put('/document-settings', $this->input())->assertSessionHasNoErrors();
        $this->from('/catalogs')->put('/document-settings', $this->input(['administration_start_year' => 2033, 'administration_end_year' => 2036]))
            ->assertSessionHasErrors(['settings_version'], null, 'document-settings');
        $this->get('/catalogs')->assertOk()->assertSee('A configuração foi alterada por outra pessoa.')
            ->assertSee('name="settings_version" value="1"', false);
        $this->assertSame(['administration' => '2029/2032'], app(DocumentSettings::class)->headerData());
        $this->assertDatabaseCount('activity_events', 1);
    }

    public function test_non_admin_and_stale_identity_cannot_change_document_settings(): void
    {
        $user = User::create(['name' => 'Usuário', 'username' => 'REGULAR', 'password' => 'SyntheticPassword123']);
        $this->actingAs($user)->put('/document-settings', $this->input())->assertForbidden();
        $expected = clone $this->admin;
        DB::table('users')->where('id', $this->admin->id)->increment('session_version');
        try {
            app(DocumentSettings::class)->update($expected, $this->input());
            $this->fail('Expected stale identity rejection');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(['administration' => '2025/2028'], app(DocumentSettings::class)->headerData());
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_audit_failure_rolls_back_administration_and_version(): void
    {
        ActivityEvent::creating(fn () => throw new \RuntimeException('Synthetic audit failure'));
        try {
            $this->put('/document-settings', $this->input())->assertStatus(500);
            $this->assertDatabaseHas('document_settings', ['id' => 1, 'version' => 1, 'updated_by' => null,
                'administration_start_year' => 2025, 'administration_end_year' => 2028]);
            $this->assertDatabaseCount('activity_events', 0);
        } finally {
            ActivityEvent::flushEventListeners();
        }
    }
}
