<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractAttachment;
use App\Models\ContractAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContractAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $regular;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('contract_documents');
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $this->regular = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'TestPassword12345']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'attachment-scope'), 'fund' => 'Fundo', 'fund_id' => $fund]);
        $this->regular->scopes()->attach($scope);
        $this->contract = Contract::create(['numero' => 'ANEXO/26', 'fund_id' => $fund, 'ownership_resolved' => true, 'quality_issues' => []]);
    }

    private function upload(): ContractAttachment
    {
        $this->actingAs($this->regular)->post('/contracts/'.$this->contract->id.'/attachments', [
            'name' => 'Instrumento assinado', 'file' => UploadedFile::fake()->image('instrumento.png'),
        ])->assertRedirect('/contracts/'.$this->contract->id);

        return ContractAttachment::sole();
    }

    private function url(ContractAttachment $document): string
    {
        return '/contracts/'.$this->contract->id.'/attachments/'.$document->id;
    }

    public function test_private_upload_download_audit_and_current_scope(): void
    {
        $document = $this->upload();
        Storage::disk('contract_documents')->assertExists($document->path);
        $this->assertStringNotContainsString('instrumento', $document->path);
        $this->assertSame($this->regular->id, $document->uploaded_by);
        $this->get('/contracts/'.$this->contract->id)->assertOk()->assertSee('Instrumento assinado')->assertDontSee($document->path);
        $this->get($this->url($document))->assertOk()->assertDownload('documento-'.$document->id.'.png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseHas('contract_audits', ['action' => 'attachment_uploaded', 'actor_id' => $this->regular->id]);
        $this->assertDatabaseHas('contract_audits', ['action' => 'attachment_downloaded', 'actor_id' => $this->regular->id]);
        $this->regular->scopes()->detach();
        $this->get($this->url($document))->assertForbidden();
        $this->post('/contracts/'.$this->contract->id.'/attachments', ['name' => 'Outro', 'file' => UploadedFile::fake()->image('other.png')])->assertForbidden();
        $this->actingAs($this->admin)->get('/contracts/'.Contract::create(['quality_issues' => []])->id.'/attachments/'.$document->id)->assertNotFound();
    }

    public function test_rejects_oversize_files_wrong_content_and_disguised_extensions(): void
    {
        $this->actingAs($this->regular);
        $url = '/contracts/'.$this->contract->id.'/attachments';
        $original = UploadedFile::fake()->image('original.png');
        foreach ([UploadedFile::fake()->create('script.php', 1, 'text/plain'),
            UploadedFile::fake()->createWithContent('falso.pdf', '<?php echo 1;'),
            UploadedFile::fake()->createWithContent('disfarce.php', file_get_contents($original->getRealPath())),
            UploadedFile::fake()->image('grande.png')->size(10241)] as $file) {
            $this->post($url, ['name' => 'Teste', 'file' => $file])->assertSessionHasErrors('file');
        }
        $this->assertDatabaseCount('contract_attachments', 0);
        $this->assertSame([], Storage::disk('contract_documents')->allFiles());
    }

    public function test_only_admin_removes_and_restores_and_deleted_contract_hides_documents(): void
    {
        $document = $this->upload();
        $data = ['confirmation' => (string) $document->id, 'reason' => 'Documento anexado por engano'];
        $this->delete($this->url($document), $data)->assertForbidden();
        $this->actingAs($this->admin)->delete($this->url($document), ['confirmation' => 'wrong'])->assertSessionHasErrors(['confirmation', 'reason']);
        $this->delete($this->url($document), $data)->assertRedirect();
        $this->assertSoftDeleted($document);
        Storage::disk('contract_documents')->assertExists($document->path);
        $this->get($this->url($document))->assertNotFound();
        $this->post($this->url($document).'/restore', $data)->assertRedirect();
        $this->get($this->url($document))->assertOk();
        $this->delete('/contracts/'.$this->contract->id, ['version' => 1, 'confirmation' => 'ANEXO/26', 'reason' => 'Contrato excluído para revisão'])->assertRedirect();
        $this->get($this->url($document))->assertNotFound();
        Storage::disk('contract_documents')->assertExists($document->path);
        $this->post('/contracts/'.$this->contract->id.'/restore', ['version' => 2, 'confirmation' => 'ANEXO/26', 'reason' => 'Revisão concluída'])->assertRedirect();
        $this->get($this->url($document))->assertOk();
        $this->assertDatabaseHas('contract_audits', ['action' => 'attachment_deleted']);
        $this->assertDatabaseHas('contract_audits', ['action' => 'attachment_restored']);
    }

    public function test_integrity_failure_blocks_download_without_success_audit(): void
    {
        $document = $this->upload();
        Storage::disk('contract_documents')->put($document->path, 'arquivo alterado');
        $this->get($this->url($document))->assertStatus(409);
        $this->assertDatabaseMissing('contract_audits', ['action' => 'attachment_downloaded']);
        Storage::disk('contract_documents')->delete($document->path);
        $this->get($this->url($document))->assertNotFound();
    }

    public function test_audit_failure_rolls_back_metadata_and_removes_new_file(): void
    {
        $this->withoutExceptionHandling();
        ContractAudit::creating(fn () => throw new \RuntimeException('audit unavailable'));
        try {
            $this->actingAs($this->regular)->post('/contracts/'.$this->contract->id.'/attachments', [
                'name' => 'Teste', 'file' => UploadedFile::fake()->image('test.png'),
            ]);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('audit unavailable', $error->getMessage());
        } finally {
            ContractAudit::flushEventListeners();
        }
        $this->assertDatabaseCount('contract_attachments', 0);
        $this->assertSame([], Storage::disk('contract_documents')->allFiles());
    }

    public function test_readiness_detects_missing_queue_table(): void
    {
        $this->get('/ready')->assertOk()->assertJson(['status' => 'ready']);
        DB::statement('DROP TABLE jobs');
        $this->get('/ready')->assertStatus(503)->assertJson(['status' => 'unavailable']);
    }
}
