<?php

use App\Infrastructure\Documents\ServiceOrderTemplate;
use Illuminate\Contracts\Console\Kernel;

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Execute com o usuário da aplicação: docker compose exec --user www-data -T app php scripts/preview-service-order-models.php\n");
    exit(1);
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$template = new ServiceOrderTemplate;
$directory = storage_path('app/private/previews/service-orders');
if (! is_dir($directory)) {
    mkdir($directory, 0750, true);
}
$base = ['preview' => true, 'fields' => ['Fundo' => 'MUNICÍPIO MODELO', 'Fornecedor' => 'EMPRESA EXEMPLIFICATIVA LTDA.',
    'Contrato' => '021/26', 'Nº OS' => '004/26', 'Data OS' => '20/10/2026',
    'Início' => '01/11/2026', 'Final' => '30/04/2027', 'Período' => '06 (seis) meses',
    'Observações' => 'Execução conforme as especificações e o cronograma previstos nos anexos do contrato.'],
    'details' => ['fund_document' => '00.000.000/0000-00', 'fund_address' => 'Praça Central, nº 100 · Centro · Município Modelo/GO',
        'administration' => '2025/2028', 'process' => '2026010552', 'procurement' => 'Concorrência Eletrônica', 'bid' => '003/26',
        'amendment' => null, 'supplier_document' => '00.000.000/0000-00', 'location' => 'Município Modelo/GO',
        'inspector' => 'Fiscal Exemplo', 'manager' => 'Gestor Exemplo', 'manager_role' => 'Gestor do Fundo', 'supplier_signatory' => 'Representante Exemplo']];
foreach (['works' => 'Execução de reforma e ampliação do prédio administrativo municipal, compreendendo adequação dos espaços de atendimento, instalações e acabamentos, conforme os projetos e as especificações integrantes do contrato.',
    'services' => 'Prestação de serviços de manutenção preventiva e corretiva das instalações dos prédios municipais, com atendimento às unidades indicadas, conforme as especificações e as condições do contrato.'] as $model => $object) {
    $document = $base;
    $document['model'] = $model;
    $document['fields']['Objeto'] = $object;
    $html = $template->render($document);
    $pdf = new Dompdf\Dompdf(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
    $pdf->setPaper('A4');
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->render();
    file_put_contents($directory.'/'.$model.'.pdf', $pdf->output());
    file_put_contents($directory.'/'.$model.'.html', $html);
    echo $model.': '.$pdf->getCanvas()->get_page_count().' página(s)'.PHP_EOL;
}
