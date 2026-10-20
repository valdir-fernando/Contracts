<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CnpjLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaSegura123', 'user_type' => 'Administrador']));
    }

    private function company(array $extra = []): array
    {
        return array_replace(['cnpj' => '11222333000181', 'razao_social' => 'EMPRESA FICTÍCIA',
            'nome_fantasia' => 'FANTASIA', 'logradouro' => 'Rua Exemplo', 'numero' => '10',
            'bairro' => 'Centro', 'municipio' => 'Cidade', 'uf' => 'GO', 'cep' => '12345000',
            'ddd_telefone_1' => '6233334444', 'ddd_telefone_2' => '62999998888',
            'email' => 'empresa@example.com', 'descricao_situacao_cadastral' => 'ATIVA',
            'qsa' => [['nome_socio' => ' SÓCIO EXEMPLO ', 'qualificacao_socio' => 'Sócio-Administrador',
                'cnpj_cpf_do_socio' => '***123456**', 'cpf_representante_legal' => '123'],
                ['nome_socio' => 'OUTRO SÓCIO', 'qualificacao_socio' => 'Sócio'],
                null, ['nome_socio' => ' ']]], $extra);
    }

    public function test_private_query_projects_only_supported_fields_without_writing_records(): void
    {
        Http::fake(['brasilapi.com.br/api/cnpj/v1/*' => Http::response($this->company())]);
        $this->postJson('/cnpj/lookup', ['cnpj' => '11.222.333/0001-81'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('status', 'found')
            ->assertJsonPath('supplier.name', 'EMPRESA FICTÍCIA')
            ->assertJsonPath('supplier.Fone_Fixo', '6233334444')
            ->assertJsonPath('supplier.Fone_Celular', '62999998888')
            ->assertJsonPath('fund.End_Fundo', 'Rua Exemplo, 10, Centro, Cidade, GO, 12345000')
            ->assertJsonMissingPath('qsa')
            ->assertJsonPath('partners', [['name' => 'SÓCIO EXEMPLO', 'qualification' => 'Sócio-Administrador'],
                ['name' => 'OUTRO SÓCIO', 'qualification' => 'Sócio']])
            ->assertDontSee('***123456**')->assertJsonMissingPath('partners.0.cpf_representante_legal');
        Http::assertSent(fn ($request) => $request->url() === 'https://brasilapi.com.br/api/cnpj/v1/11222333000181');
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('contract_funds', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_invalid_documents_and_non_administrators_never_call_provider(): void
    {
        Http::fake();
        foreach (['11111111111111', '52998224725', ['11222333000181'], '11222333000181?url=other'] as $cnpj) {
            $this->postJson('/cnpj/lookup', ['cnpj' => $cnpj])->assertUnprocessable();
        }
        $user = User::create(['name' => 'Usuário', 'username' => 'USER', 'password' => 'SenhaSegura123', 'user_type' => 'Usuario']);
        $this->actingAs($user)->postJson('/cnpj/lookup', ['cnpj' => '11222333000181'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_unavailable_not_found_and_mismatched_responses_are_safe(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 404)->push('invalid json', 200)
            ->push($this->company(['cnpj' => '00000000000191']))->push([], 429)->pushFailedConnection()]);
        $this->postJson('/cnpj/lookup', ['cnpj' => '11222333000181'])->assertOk()->assertExactJson(['status' => 'not_found']);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/cnpj/lookup', ['cnpj' => '11222333000181'])->assertStatus(503)
                ->assertExactJson(['status' => 'unavailable']);
        }
    }

    public function test_invalid_optional_contacts_are_omitted_and_inactive_status_is_preserved(): void
    {
        Http::fake(['*' => Http::response($this->company(['email' => 'inválido', 'ddd_telefone_1' => '123',
            'ddd_telefone_2' => '', 'descricao_situacao_cadastral' => 'BAIXADA']))]);
        $this->postJson('/cnpj/lookup', ['cnpj' => '11222333000181'])->assertOk()
            ->assertJsonPath('registration_status', 'BAIXADA')->assertJsonPath('supplier.Email_Fornecedor', '')
            ->assertJsonMissingPath('supplier.Fone_Fixo')->assertJsonMissingPath('supplier.Fone_Celular');
    }
}
