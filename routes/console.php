<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment('Vamos construir algo útil.');
})->purpose('Exibir uma mensagem de inspiração');
