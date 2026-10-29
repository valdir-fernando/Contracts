<?php

namespace Tests\Feature;

use App\Application\Security\ActorIdentity;
use App\Infrastructure\Documents\SignedDocuments;
use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractAttachment;
use App\Models\ContractAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PublicationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Contract $contract;

    private int $fund;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('contract_documents');
        $this->actor = User::create(['name' => 'Usuário fictício', 'username' => 'PUBLICACAO', 'password' => 'SenhaFicticia123']);
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo teste', 'name_key' => 'FUNDO TESTE', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'publication'), 'fund' => 'Fundo teste', 'fund_id' => $this->fund]);
        $this->actor->scopes()->attach($scope);
        $this->contract = Contract::create(['numero' => '001/26', 'processo' => 'PROC-PUBLICACAO', 'fund_id' => $this->fund,
            'ownership_resolved' => true, 'quality_issues' => [], 'version' => 1]);
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund]);
    }

    private function pdf(string $name = 'assinado.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    }

    private function upload(array $extra = [])
    {
        return $this->from('/contracts/'.$this->contract->id)->post('/publication/contracts/'.$this->contract->id,
            array_replace(['category' => 'Contrato_PDF', 'version' => $this->contract->fresh()->version, 'file' => $this->pdf()], $extra));
    }

    public function test_retired_panel_redirects_without_data_and_navigation_has_no_links_to_it(): void
    {
        foreach (['/publication', '/publication?q=inexistente', '/publication?contract='.$this->contract->id] as $url) {
            $this->get($url)->assertRedirect(route('reports.index'))
                ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('PROC-PUBLICACAO');
        }
        foreach (['/dashboard', '/reports', '/contracts/'.$this->contract->id] as $url) {
            $page = $this->get($url)->assertOk()->assertDontSee('data-nav-label="Publicação"', false);
            $html = new \DOMDocument;
            @$html->loadHTML($page->getContent());
            $xpath = new \DOMXPath($html);
            $this->assertSame(0, $xpath->query('//a[@href="'.route('publication.index').'" or starts-with(@href, "'.route('publication.index').'?" )]')->length);
        }
    }

    public function test_all_six_uploads_link_private_unchanged_pdfs_and_audit_version_changes(): void
    {
        foreach (array_keys(SignedDocuments::CATEGORIES) as $category) {
            $file = $this->pdf();
            $hash = hash_file('sha256', $file->getRealPath());
            $version = $this->contract->fresh()->version;
            $this->upload(['category' => $category, 'file' => $file])->assertRedirect('/contracts/'.$this->contract->id)->assertSessionHasNoErrors();
            $contract = $this->contract->fresh();
            $document = ContractAttachment::findOrFail($contract->getAttribute($category));
            $this->assertSame($category, $document->signed_category);
            $this->assertSame($this->actor->id, $document->uploaded_by);
            $this->assertSame($hash, hash('sha256', Storage::disk('contract_documents')->get($document->path)));
            $this->assertSame($version + 1, $contract->version);
            $this->assertSame($this->actor->id, $contract->updated_by);
            $audit = ContractAudit::where('contract_id', $contract->id)->latest('id')->firstOrFail();
            $this->assertSame(['before' => null, 'after' => $document->id], $audit->changes[$category]);
        }
        $this->assertDatabaseCount('contract_attachments', 6);
        $this->assertDatabaseCount('contract_audits', 6);
    }

    public function test_replacement_requires_confirmation_and_keeps_previous_documents_downloadable(): void
    {
        $this->upload()->assertSessionHasNoErrors();
        $original = ContractAttachment::sole();
        $this->upload()->assertSessionHasErrors('replace_confirmation');
        $this->assertDatabaseCount('contract_attachments', 1);
        $this->assertCount(1, Storage::disk('contract_documents')->allFiles());
        $this->upload(['replace_confirmation' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('contract_attachments', 2);
        $this->assertCount(2, Storage::disk('contract_documents')->allFiles());
        $this->assertNotSame($original->id, $this->contract->fresh()->Contrato_PDF);
        $this->get('/publication/contracts/'.$this->contract->id.'/documents/'.$this->contract->fresh()->Contrato_PDF)->assertOk();
        $this->get('/publication/contracts/'.$this->contract->id.'/documents/'.$original->id)->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_invalid_file_category_and_stale_versions_leave_no_changes_or_orphan_files(): void
    {
        $this->upload(['file' => UploadedFile::fake()->image('imagem.png')])->assertSessionHasErrors('file');
        $this->upload(['file' => UploadedFile::fake()->createWithContent('fraude.pdf', '<html>fraude</html>')])->assertSessionHasErrors('file');
        $this->upload(['file' => UploadedFile::fake()->create('grande.pdf', 10241, 'application/pdf')])->assertSessionHasErrors('file');
        $this->upload(['file' => null])->assertSessionHasErrors('file');
        $this->upload(['file' => [$this->pdf()]])->assertSessionHasErrors('file');
        $this->upload(['category' => 'updated_by'])->assertSessionHasErrors('category');
        $this->contract->update(['version' => 2]);
        $this->upload(['version' => 1])->assertRedirect('/contracts/'.$this->contract->id)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('contract_attachments', 0);
        $this->assertDatabaseCount('contract_audits', 0);
        $this->assertSame([], Storage::disk('contract_documents')->allFiles());
        $this->assertNull($this->contract->fresh()->Contrato_PDF);
    }

    public function test_other_funds_and_departments_are_not_visible_or_mutable(): void
    {
        $otherFund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo restrito', 'name_key' => 'FUNDO RESTRITO', 'raw_payload' => '{}']);
        $other = Contract::create(['numero' => 'SIGILOSO', 'fund_id' => $otherFund, 'ownership_resolved' => true, 'quality_issues' => []]);
        $this->get('/publication')->assertRedirect(route('reports.index'))->assertDontSee('SIGILOSO');
        $this->get('/publication?contract='.$other->id)->assertRedirect(route('reports.index'))->assertDontSee('SIGILOSO');
        $this->post('/publication/contracts/'.$other->id, ['category' => 'Contrato_PDF', 'version' => 1, 'file' => $this->pdf()])->assertForbidden();
        $scope = $this->actor->scopes()->firstOrFail();
        $department = DB::table('contract_departments')->insertGetId(['name' => 'Secretaria autorizada', 'name_key' => 'SECRETARIA AUTORIZADA', 'raw_payload' => '{}']);
        $scope->update(['department' => 'Secretaria autorizada', 'department_id' => $department]);
        $this->get('/publication?contract='.$this->contract->id)->assertRedirect(route('reports.index'))->assertDontSee('PROC-PUBLICACAO');
        $this->upload()->assertForbidden();
        $this->assertSame([], Storage::disk('contract_documents')->allFiles());
    }

    public function test_even_administrator_is_limited_to_selected_fund_in_this_module(): void
    {
        $this->actor->update(['user_type' => 'Administrador']);
        $otherFund = DB::table('contract_funds')->insertGetId(['name' => 'Outro Fundo', 'name_key' => 'OUTRO FUNDO', 'raw_payload' => '{}']);
        $other = Contract::create(['numero' => 'OUTRO-FUNDO', 'fund_id' => $otherFund, 'quality_issues' => []]);
        $this->actingAs($this->actor->fresh());
        $this->get('/publication')->assertRedirect(route('reports.index'))->assertDontSee('OUTRO-FUNDO');
        $this->post('/publication/contracts/'.$other->id, ['category' => 'Contrato_PDF', 'version' => 1, 'file' => $this->pdf()])->assertForbidden();
    }

    public function test_download_rejects_tampering_removed_documents_and_wrong_contract(): void
    {
        $this->upload();
        $document = ContractAttachment::sole();
        $url = '/publication/contracts/'.$this->contract->id.'/documents/'.$document->id;
        Storage::disk('contract_documents')->put($document->path, 'alterado');
        $this->get($url)->assertStatus(409);
        $other = Contract::create(['fund_id' => $this->fund, 'ownership_resolved' => true, 'quality_issues' => []]);
        $this->get('/publication/contracts/'.$other->id.'/documents/'.$document->id)->assertNotFound();
        $document->delete();
        $this->get($url)->assertNotFound();
        $this->contract->delete();
        $this->upload()->assertNotFound();
    }

    public function test_audit_failure_rolls_back_reference_attachment_version_and_staged_file(): void
    {
        ContractAudit::creating(fn () => throw new RuntimeException('Falha de auditoria'));
        try {
            app(SignedDocuments::class)->upload(new ActorIdentity($this->actor->id, $this->actor->session_version),
                $this->fund, $this->contract->id, ['category' => 'Contrato_PDF', 'version' => 1], $this->pdf());
            $this->fail('Expected audit failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Falha de auditoria', $error->getMessage());
            $this->assertDatabaseCount('contract_attachments', 0);
            $this->assertNull($this->contract->fresh()->Contrato_PDF);
            $this->assertSame(1, $this->contract->fresh()->version);
            $this->assertSame([], Storage::disk('contract_documents')->allFiles());
        } finally {
            ContractAudit::flushEventListeners();
        }
    }

    public function test_revoked_identity_cannot_upload_or_download_existing_document(): void
    {
        $this->upload();
        $document = ContractAttachment::sole();
        $this->actor->increment('session_version');
        $this->post('/publication/contracts/'.$this->contract->id, ['category' => 'OS_PDF', 'version' => 2, 'file' => $this->pdf()])->assertRedirect('/login');
        $this->get('/publication/contracts/'.$this->contract->id.'/documents/'.$document->id)->assertRedirect('/login');
        $this->assertDatabaseCount('contract_attachments', 1);
    }
}
