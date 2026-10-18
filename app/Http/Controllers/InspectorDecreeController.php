<?php

namespace App\Http\Controllers;

use App\Infrastructure\Documents\InspectorDecree;
use Illuminate\Http\Request;

class InspectorDecreeController extends Controller
{
    public function create(Request $request, InspectorDecree $decree)
    {
        $input = $request->validate(['inspector' => 'nullable|integer|min:1']);
        $prepared = $decree->prepare($request->user());
        $preset = isset($input['inspector']) ? collect($prepared['inspectors'])->firstWhere('id', (int) $input['inspector']) : null;
        abort_if(isset($input['inspector']) && ! $preset, 422, 'O fiscal selecionado não está disponível para emissão.');

        return response()->view('administration.inspector-decree', $prepared + ['preset' => $preset])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, InspectorDecree $decree)
    {
        if ($request->has('inspector')) {
            $data = $request->validate(['inspector' => 'required|integer|min:1', 'inspector_revision' => 'required|string|size:64']);
            $bytes = $decree->generateForInspector($request->user(), (int) $data['inspector'], $data['inspector_revision']);
        } else {
            $bytes = $decree->generate($request->user(), $request->all());
        }

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="decreto-fiscais.pdf"',
            'Cache-Control' => 'private, no-store']);
    }
}
