<?php

use App\Infrastructure\Documents\SupplyContractPdf;
use App\Infrastructure\Documents\SupplyContractTemplate;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Synthetic preview only: no database queries, persistence, authorization or audit.
$fields = [
    '#CONTRATO' => '001/26', '#Contrato' => '001/26', '#PROCESSO' => '202600001', '#Processo' => '202600001',
    '#FUNDO' => 'FUNDO MUNICIPAL DE EXEMPLO', '#ADM' => '2025/2028', '#Modalidade' => 'Inexigibilidade', '#N_Modalidade' => '001/26',
    '#CNPJ_FUNDO' => '00.000.000/0000-00', '#End_Fundo' => 'Praça de Exemplo, nº 100, Centro',
    '#Cargo_Gestor' => 'Gestor do Fundo', '#NomeGestor' => 'GESTOR EXEMPLIFICATIVO', '#Decreto' => '001/2026', '#Matricula' => '12345',
    '#PF_PJ' => 'a pessoa física', '#NomeLocador' => 'LOCADOR EXEMPLIFICATIVO',
    '#Dados_Locador' => 'CPF: •••0000, endereço: Rua de Exemplo, nº 200, Centro',
    '#CPF_GESTOR' => '•••0000', '#DOCUMENTO_LOCADOR' => 'CPF: •••0000',
    '#Destinação' => 'Unidade de Atendimento', '#Vigencia' => '12 (doze) meses',
    '#VLMensal' => '1.000,00', '#ExtMensal' => 'mil reais', '#VLTotal' => '12.000,00', '#ExtTotal' => 'doze mil reais', '#DataContrato' => '20/10/2026',
];
$items = [['item' => '1', 'code' => '001', 'description' => 'Locação de imóvel situado na Rua de Exemplo, nº 200, destinado à Unidade de Atendimento.',
    'unit' => 'MÊS', 'quantity' => '12.0000', 'unit_price' => '1000.0000', 'total' => '12000.0000']];
$budgets = [['ficha' => '001', 'allocation' => '01.02.03.04.122.0001.2001.339036', 'amount' => '12000.0000']];
$html = (new SupplyContractTemplate)->render($fields, $items, $budgets, '12000.0000', 'property-lease');
$renderer = new class extends SupplyContractPdf
{
    public function __construct() {}

    public function preview(string $html): string
    {
        return $this->render($html);
    }
};
$directory = dirname(__DIR__).'/build/property-lease-preview';
if (! is_dir($directory)) {
    mkdir($directory, 0750, true);
}
file_put_contents($directory.'/contract.html', $html);
file_put_contents($directory.'/contract.pdf', $renderer->preview($html));
echo $directory.'/contract.pdf'.PHP_EOL;
