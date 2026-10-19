<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\User;
use App\Support\SupplierFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actingAs(User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']));
    }

    private function input(): array
    {
        $data = ['kind' => 'supplier', 'return_to' => 'suppliers.index', 'name' => 'Fornecedor completo', 'document' => '11.222.333/0001-81'];
        foreach (SupplierFields::fields() as $field => [$label, $type]) {
            $data[$field] = match ($type) {
                'email' => 'cadastro@example.com', 'date' => '1980-05-20', 'select' => 'Feminino', 'tel' => '62999998888', default => 'Informação '.$field,
            };
        }

        return array_replace($data, ['CPF_Socio' => '529.982.247-25', 'PIS_PASEP' => '12345678901']);
    }

    public function test_all_supplier_fields_are_rendered_and_persisted_without_accepting_internal_fields(): void
    {
        $html = $this->get('/suppliers')->assertOk();
        foreach (array_keys(SupplierFields::fields()) as $field) {
            $html->assertSee('name="'.$field.'"', false);
        }
        $input = $this->input();
        $this->post('/catalogs', $input + ['ID_Fornecedor' => '999', 'PF_PJ' => 'PF', 'Data_Atualizacao' => '1900-01-01', 'raw_payload' => 'forged'])
            ->assertRedirect('/suppliers')->assertSessionHasNoErrors();
        $supplier = DB::table('suppliers')->sole();
        $raw = json_decode($supplier->raw_payload, true, 512, JSON_THROW_ON_ERROR);
        foreach (array_keys(SupplierFields::fields()) as $field) {
            $expected = match ($field) {
                'CPF_Socio' => '52998224725',
                'Nome_Fantasia', 'Nome_Socio', 'Nome_Contato' => mb_strtoupper($input[$field], 'UTF-8'),
                default => $input[$field],
            };
            $this->assertSame($expected, $raw[$field], $field);
        }
        $this->assertSame('PJ', $raw['PF_PJ']);
        $this->assertSame(now()->format('Y-m-d'), $raw['Data_Atualizacao']);
        $this->assertSame('11222333000181', $raw['CPF_CNPJ']);
        $this->assertSame(mb_strtoupper($input['name'], 'UTF-8'), $raw['Nome_Fornecedor']);
        $this->assertSame(mb_strtoupper($input['name'], 'UTF-8'), $supplier->name);
        $this->assertArrayNotHasKey('ID_Fornecedor', $raw);
        $this->assertArrayNotHasKey('raw_payload', $raw);
        $this->assertDatabaseHas('activity_events', ['action' => 'catalog_created']);
        $this->postJson('/suppliers/lookup', ['document' => $supplier->document_key])->assertOk()
            ->assertJsonPath('values.end_fornec', $input['Endereco'])
            ->assertJsonPath('values.cpf_socio', '52998224725')
            ->assertJsonMissingPath('values.Email_Fornecedor');
    }

    public function test_supplier_actions_are_in_the_header_and_target_the_correct_form(): void
    {
        $response = $this->get('/suppliers')->assertOk();
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        $xpath = new \DOMXPath($html);
        $header = '//dialog[@id="catalog-supplier"]/div[contains(@class,"modal-header")]';
        $this->assertSame(1, $xpath->query($header.'//button[@type="submit" and @form="catalog-supplier-form" and normalize-space(.)="Salvar"]/svg')->length);
        $this->assertSame(1, $xpath->query($header.'//button[@type="reset" and @form="catalog-supplier-form" and normalize-space(.)="Novo"]/svg')->length);
        $this->assertSame(1, $xpath->query($header.'//button[@disabled and normalize-space(.)="Excluir"]/svg')->length);
        $this->assertSame(0, $xpath->query('//form[@id="catalog-supplier-form"]//button')->length);
        $this->assertSame('POST', $html->getElementById('catalog-supplier-form')->getAttribute('method'));
    }

    public function test_invalid_details_restore_the_form_without_persisting_supplier_or_audit(): void
    {
        $this->from('/suppliers')->post('/catalogs', array_replace($this->input(), [
            'CPF_Socio' => '123', 'Email_Socio' => 'invalid', 'Data_Nasc' => '2026-02-30',
        ]))->assertRedirect('/suppliers')->assertSessionHasErrors(['Email_Socio', 'Data_Nasc'], null, 'catalog-supplier');
        $this->get('/suppliers')->assertOk()->assertSee('data-modal-auto-open', false)->assertSee('Informação Endereco');
        foreach (['CPF_Socio' => '123', 'Endereco' => str_repeat('x', 2001), 'Sexo' => 'Inválido'] as $field => $value) {
            $this->from('/suppliers')->post('/catalogs', array_replace($this->input(), [$field => $value]))
                ->assertSessionHasErrors([$field], null, 'catalog-supplier');
        }
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_person_type_is_derived_and_extra_fields_remain_optional(): void
    {
        $this->post('/catalogs', ['kind' => 'supplier', 'name' => 'Pessoa fictícia', 'document' => '52998224725', 'Endereco' => 'Rua de teste'])
            ->assertSessionHasNoErrors();
        $raw = json_decode(DB::table('suppliers')->sole()->raw_payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('PF', $raw['PF_PJ']);
        $this->assertSame('Rua de teste', $raw['Endereco']);
        $this->assertSame('', $raw['Email_Fornecedor']);
    }

    public function test_hidden_fields_can_be_omitted_for_both_document_types(): void
    {
        foreach (['52998224725' => ['Nome_Fantasia', ...array_keys(SupplierFields::GROUPS['Sócio'])],
            '11222333000181' => ['Data_Nasc', 'Sexo', 'PIS_PASEP', 'Doc_Prof']] as $document => $hidden) {
            $input = array_replace($this->input(), ['document' => (string) $document]);
            foreach ($hidden as $field) {
                unset($input[$field]);
            }
            $this->post('/catalogs', $input)->assertSessionHasNoErrors();
            $raw = json_decode(DB::table('suppliers')->where('document_key', (string) $document)->value('raw_payload'), true);
            foreach ($hidden as $field) {
                $this->assertSame('', $raw[$field], $field);
            }
        }
        $this->assertDatabaseCount('suppliers', 2);
        $this->assertDatabaseCount('activity_events', 2);
    }

    public function test_address_is_required_for_cpf_and_cnpj_and_has_its_own_warning(): void
    {
        foreach (['52998224725', '11222333000181'] as $document) {
            foreach ([null, '', '   '] as $address) {
                $this->from('/suppliers')->post('/catalogs', array_replace($this->input(), ['document' => $document, 'Endereco' => $address]))
                    ->assertRedirect('/suppliers')->assertSessionHasErrors(['Endereco'], null, 'catalog-supplier');
                $this->get('/suppliers')->assertOk()
                    ->assertSee('id="supplier-registration-required"', false)
                    ->assertSee('Confira os campos indicados para concluir o cadastro.')
                    ->assertDontSee('id="supplier-registration-errors"', false);
            }
        }
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('activity_events', 0);
        $html = new \DOMDocument;
        @$html->loadHTML($this->get('/suppliers')->getContent());
        $this->assertTrue($html->getElementById('catalog-supplier-Endereco')->hasAttribute('required'));
    }

    public function test_masked_phone_whatsapp_and_pis_are_validated_and_stored_as_digits(): void
    {
        $input = array_replace($this->input(), ['Fone_Fixo' => '(62) 3333-4444',
            'WhatsApp_Fornecedor' => '(62) 99999-8888', 'PIS_PASEP' => '123.45678.90-1']);
        $this->post('/catalogs', $input)->assertSessionHasNoErrors();
        $raw = json_decode(DB::table('suppliers')->sole()->raw_payload, true);
        $this->assertSame('6233334444', $raw['Fone_Fixo']);
        $this->assertSame('62999998888', $raw['WhatsApp_Fornecedor']);
        $this->assertSame('12345678901', $raw['PIS_PASEP']);
        foreach (['Fone_Celular' => 'texto', 'WhatsApp_Contato' => '123', 'PIS_PASEP' => '123',
            'Data_Nasc' => '31/02/2026'] as $field => $value) {
            $this->post('/catalogs', array_replace($this->input(), ['document' => '52998224725', $field => $value]))
                ->assertSessionHasErrors([$field], null, 'catalog-supplier');
        }
        $this->assertDatabaseCount('suppliers', 1);
    }

    public function test_retired_fields_are_absent_and_cannot_be_saved_even_if_sent_directly(): void
    {
        $retired = array_fill_keys(['VL_Pensao', 'ISSQN', 'Dependentes', 'INSS', 'Situacao', 'Tributario',
            'Honorario', 'Empregados', 'Origem', 'Porte', 'NaturezaJuridica', 'End_Socio'], 'Não persistir');
        $legacy = DB::table('suppliers')->insertGetId(['name' => 'Legado', 'name_key' => 'LEGADO',
            'document' => '52998224725', 'document_key' => '52998224725', 'raw_payload' => json_encode($retired)]);
        $html = $this->get('/suppliers')->assertOk()->assertDontSee('Informações tributárias e complementares');
        foreach (array_keys($retired) as $field) {
            $html->assertDontSee('name="'.$field.'"', false);
        }
        $this->post('/catalogs', $this->input() + $retired)->assertSessionHasNoErrors();
        $raw = json_decode(DB::table('suppliers')->where('name', 'FORNECEDOR COMPLETO')->value('raw_payload'), true);
        foreach (array_keys($retired) as $field) {
            $this->assertArrayNotHasKey($field, $raw);
        }
        $this->assertSame($retired, json_decode(DB::table('suppliers')->where('id', $legacy)->value('raw_payload'), true));
    }

    public function test_audit_failure_rolls_back_full_supplier_registration(): void
    {
        ActivityEvent::creating(fn () => throw new \RuntimeException('Synthetic audit failure'));
        try {
            $this->post('/catalogs', $this->input())->assertStatus(500);
            $this->assertDatabaseCount('suppliers', 0);
        } finally {
            ActivityEvent::flushEventListeners();
        }
    }

    public function test_registration_lookup_projects_all_active_fields_and_blocks_duplicate_save(): void
    {
        $input = $this->input();
        $raw = array_intersect_key($input, SupplierFields::fields()) + ['VL_Pensao' => 'PRIVATE RETIRED', 'End_Socio' => 'PRIVATE RETIRED', 'unexpected' => 'PRIVATE RAW'];
        DB::table('suppliers')->insert(['name' => $input['name'], 'name_key' => 'FORNECEDOR COMPLETO',
            'document' => $input['document'], 'document_key' => '11222333000181', 'raw_payload' => json_encode($raw)]);
        $response = $this->postJson('/suppliers/registration-lookup', ['document' => $input['document']])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('found', true)
            ->assertJsonPath('values.name', $input['name'])->assertJsonPath('values.document', '11222333000181')
            ->assertDontSee('PRIVATE')->assertJsonMissingPath('values.raw_payload');
        foreach (array_keys(SupplierFields::fields()) as $field) {
            $response->assertJsonPath('values.'.$field, $input[$field]);
        }
        $this->from('/suppliers')->post('/catalogs', $input)->assertRedirect('/suppliers')
            ->assertSessionHasErrors(['document'], null, 'catalog-supplier');
        $this->get('/suppliers')->assertOk()
            ->assertSee('id="supplier-registration-errors"', false)
            ->assertSee('aria-describedby="supplier-registration-errors-message" data-modal-auto-open', false)
            ->assertDontSee('Confira os campos indicados para concluir o cadastro.')
            ->assertSee('Fornecedor já cadastrado para este CPF/CNPJ. Consulte o catálogo.')
            ->assertSee('data-modal-close autofocus>OK', false);
        $this->assertDatabaseCount('suppliers', 1);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_registration_lookup_handles_missing_invalid_and_ambiguous_documents(): void
    {
        $this->postJson('/suppliers/registration-lookup', ['document' => '52998224725'])->assertOk()
            ->assertExactJson(['found' => false, 'values' => null]);
        $this->postJson('/suppliers/registration-lookup', ['document' => '123'])->assertUnprocessable();
        foreach (['Primeiro', 'Segundo'] as $name) {
            DB::table('suppliers')->insert(['name' => $name, 'name_key' => strtoupper($name),
                'document' => '52998224725', 'document_key' => '52998224725', 'raw_payload' => '{}']);
        }
        $this->postJson('/suppliers/registration-lookup', ['document' => '529.982.247-25'])->assertConflict()
            ->assertJsonMissingPath('values')->assertDontSee('Primeiro')->assertDontSee('Segundo');
    }

    public function test_full_registration_lookup_requires_current_admin_role(): void
    {
        $user = auth()->user();
        DB::table('users')->where('id', $user->id)->update(['user_type' => 'Usuario']);
        $this->postJson('/suppliers/registration-lookup', ['document' => '11222333000181'])->assertForbidden();
    }
}
