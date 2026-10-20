<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings;

class TrimInputStrings extends TrimStrings
{
    protected $except = [];
}
