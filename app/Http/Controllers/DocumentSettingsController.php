<?php

namespace App\Http\Controllers;

use App\Infrastructure\Documents\DocumentSettings;
use Illuminate\Http\Request;

class DocumentSettingsController extends Controller
{
    public function update(Request $request, DocumentSettings $settings)
    {
        $settings->update($request->user(), $request->all());

        return redirect()->route('catalogs.index')->with('status', 'Administração dos documentos atualizada.');
    }
}
