<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractAttachment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SignedDocumentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_document_columns_precede_adjustment_index_and_reference_existing_private_attachments(): void
    {
        // The additive adjustment-index migration follows the six document references.
        $this->assertSame([...Contract::SIGNED_DOCUMENT_COLUMNS, 'indice_reajuste'], array_slice(Schema::getColumnListing('contracts'), -7));
        $contract = Contract::create(['quality_issues' => [], 'objeto' => 'Preservar objeto']);
        $this->assertNull($contract->fresh()->indice_reajuste);
        foreach (Contract::SIGNED_DOCUMENT_COLUMNS as $column) {
            $this->assertNull($contract->fresh()->getAttribute($column));
        }
        $user = User::create(['name' => 'Usuário fictício', 'username' => 'DOCUMENTOS_TESTE', 'password' => 'SenhaFicticia123', 'user_type' => 'Administrador']);
        $attachment = ContractAttachment::create(['contract_id' => $contract->id, 'uploaded_by' => $user->id, 'name' => 'Contrato assinado',
            'path' => $contract->id.'/documento-assinado.pdf', 'mime_type' => 'application/pdf',
            'size' => 100, 'sha256' => str_repeat('a', 64)]);
        DB::table('contracts')->where('id', $contract->id)->update(['Contrato_PDF' => $attachment->id]);
        $contract->refresh();
        $this->assertSame($attachment->id, $contract->Contrato_PDF);
        $this->assertSame('Preservar objeto', $contract->objeto);
        $this->assertArrayNotHasKey('Contrato_PDF', $contract->toArray());
        $contract->fill(['Contrato_PDF' => null]);
        $this->assertSame($attachment->id, $contract->Contrato_PDF);
    }

    public function test_signed_document_reference_cannot_point_to_missing_attachment(): void
    {
        $contract = Contract::create(['quality_issues' => []]);
        $this->expectException(QueryException::class);
        DB::table('contracts')->where('id', $contract->id)->update(['OS_PDF' => 999999]);
    }
}
