<?php

namespace Tests\Feature;

use App\Infrastructure\Documents\ServiceOrderTemplate;
use Tests\TestCase;

class ServiceOrderTemplateTest extends TestCase
{
    public function test_models_use_fixed_keys_and_distinct_execution_text_while_escaping_supplier_data(): void
    {
        $template = new ServiceOrderTemplate;
        foreach (['Obras' => ['works', 'ORDEM DE SERVIÇO DE OBRA', 'projetos'],
            'Serviços Diversos' => ['services', 'ORDEM DE PRESTAÇÃO DE SERVIÇOS', 'entregas'],
            'Serviço' => ['services', 'ORDEM DE PRESTAÇÃO DE SERVIÇOS', 'entregas'],
            'Fornecimento' => ['generic', 'ORDEM DE SERVIÇO', 'especificações']] as $type => [$model, $title, $word]) {
            $this->assertSame($model, $template->model($type));
            $snapshot = $this->snapshot($model);
            $html = $template->render($snapshot);
            $this->assertStringContainsString($title, $html);
            $this->assertStringContainsString($title.' Nº 001/26', $html);
            $this->assertStringContainsString('data:image/png;base64,', $html);
            $this->assertStringContainsString('alt="Prefeitura de Niquelândia"', $html);
            $this->assertStringNotContainsString('number-box', $html);
            $this->assertSame(2, substr_count($html, '<div class="electronic-signature-space"></div>'));
            $this->assertStringContainsString($word, $html);
            $this->assertStringContainsString('Administração 2025/2028', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html);
            $this->assertStringNotContainsString('<script>', $html);
            $this->assertStringContainsString('30 dias', $html);
            $this->assertSame(64, strlen($template->hash($model)));
        }
    }

    public function test_arbitrary_template_paths_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ServiceOrderTemplate)->hash('../../anything');
    }

    private function snapshot(string $model): array
    {
        return ['model' => $model, 'fields' => ['Fundo' => 'Fundo Exemplo', 'Fornecedor' => '<script>Fornecedor</script>',
            'Contrato' => '001/26', 'Nº OS' => '001/26', 'Data OS' => '20/10/2026', 'Objeto' => 'Objeto de teste',
            'Início' => '01/11/2026', 'Final' => '30/11/2026', 'Período' => '30 dias', 'Observações' => 'Observação'],
            'details' => ['fund_document' => '', 'fund_address' => '', 'administration' => '2025/2028',
                'process' => 'P-1', 'procurement' => 'Concorrência', 'bid' => '01/26', 'amendment' => null,
                'supplier_document' => '', 'location' => '', 'inspector' => '', 'manager' => '', 'manager_role' => '', 'supplier_signatory' => '']];
    }
}
