<?php

use App\Models\Contract;
use Illuminate\Contracts\Console\Kernel;

// Read-only rendering for browser-contract-products.cjs; no database queries or writes.
require dirname(__DIR__).'/vendor/autoload.php';
$application = require dirname(__DIR__).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
$contract = new Contract;
$contract->forceFill(['id' => 1, 'processo' => '123/2026']);
$contract->exists = true;
$html = view('components.contract-products', ['contract' => $contract])->render();
$html = preg_replace('~https?://[^/]+/~', 'http://contracts.test/', $html);
echo '<!doctype html><html><head><meta charset="utf-8"></head><body><form class="contract-section"><input type="hidden" name="version" value="1"><input type="hidden" name="_token" value="test"><p id="valor_total" hidden></p><button type="button" data-contract-products-open>6. Cad.Prod</button><dialog data-contract-products-dialog><button type="button" data-contract-products-close>Fechar</button>'.$html.'</dialog><label class="field">Valor do contrato<input id="contract-items-total" readonly></label></form></body></html>';
