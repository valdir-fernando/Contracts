<?php

use App\Infrastructure\Documents\SupplyContractPdf;
use App\Infrastructure\Documents\SupplyContractTemplate;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Synthetic preview only: no database reads, persistence or audit.
$fields = [
    '#EMPREITADA' => 'EMPREITADA EXEMPLIFICATIVA', '#PRAZO_EXECUÇÃO' => '180 dias', '#EXTENSO_TOTAL' => 'doze mil reais', '#CONTRATO' => '001/26', '#PROCESSO' => '202600001', '#MODALIDADE' => 'Pregão', '#N_LICIT' => '001/26',
    '#FUNDO' => 'FUNDO MUNICIPAL DE EXEMPLO', '#CNPJ_FUNDO' => '00.000.000/0000-00', '#END_FUNDO' => 'Praça de Exemplo, 100',
    '#CARGO_GESTOR' => 'Gestor do Fundo', '#NOME_GESTOR' => 'GESTOR EXEMPLIFICATIVO', '#CPF_GESTOR' => '•••0000', '#RG_GESTOR' => 'Não informado',
    '#FORNECEDOR' => 'PROFISSIONAL EXEMPLIFICATIVO', '#CNPJ_FORNECEDOR' => '00.000.000/0000-00', '#END_FORNECEDOR' => 'Rua de Exemplo, 200',
    '#REPRESENTANTE_FORNECEDOR' => 'REPRESENTANTE EXEMPLIFICATIVO', '#CPF_REPRESENTANTE' => '•••0000', '#RG_REPRESENTANTE' => 'Não informado',
    '#DATA_LICIT' => '01/10/2026', '#DATA_CONTRATO' => '21/10/2026', '#OBJETO' => 'Atendimento médico clínico na unidade de saúde.',
    '#VIGENCIA' => '12 meses', '#INICIO' => '21/10/2026', '#FINAL' => '20/10/2027',
    '#VL_CONTRATO' => '12.000,00', '#EXTENSO' => 'doze mil reais', '#DOTAÇÃO' => '', '#VL_FICHA' => '', '#ADM' => '2025/2028',
];
$items = [['item' => '1', 'description' => 'Atendimento médico clínico na unidade de saúde.',
    'unit' => 'MÊS', 'quantity' => '12.0000', 'unit_price' => '1000.0000', 'total' => '12000.0000']];
$budgets = [['ficha' => '20260001', 'allocation' => '01.02.04.122.0001.2001.339039(100)20260001', 'amount' => '12000.0000']];
$fields += [
    '#CONT' => $fields['#CONTRATO'], '#CPF' => '•••0000', '#RG' => 'Não informado',
    '#DOC_PROF' => 'CRM EXEMPLO 123', '#ENDEREÇO' => $fields['#END_FORNECEDOR'],
    '#DECRETO' => '001/2026', '#CARGOCREDENCIADO' => 'Médico clínico', '#LOCAL_SERVICO' => 'Unidade de Saúde de Exemplo',
    '#VL_MENSAL' => '1.000,00', '#EXTENSO_MENSAL' => 'mil reais',
];
$html = (new SupplyContractTemplate)->render($fields, $items, $budgets, '12000.0000', 'health-accreditation');
$renderer = new class extends SupplyContractPdf
{
    public function __construct() {}

    public function preview(string $html): string
    {
        return $this->render($html);
    }
};
$directory = dirname(__DIR__).'/build/health-accreditation-contract-preview';
if (! is_dir($directory)) {
    mkdir($directory, 0750, true);
}
file_put_contents($directory.'/contract.html', $html);
file_put_contents($directory.'/contract.pdf', $renderer->preview($html));
echo $directory.'/contract.pdf'.PHP_EOL;
