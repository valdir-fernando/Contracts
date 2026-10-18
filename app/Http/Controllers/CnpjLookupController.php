<?php

namespace App\Http\Controllers;

use App\Infrastructure\Companies\CnpjLookup;
use App\Infrastructure\Security\LockActor;
use App\Services\Legacy\Normalizer;
use Illuminate\Http\Request;

final class CnpjLookupController extends Controller
{
    public function __invoke(Request $request, LockActor $actors, CnpjLookup $lookup)
    {
        abort_unless($actors->current($request->user())->isAdmin(), 403);
        $data = $request->validate(['cnpj' => ['bail', 'required', 'string', 'max:32', function ($attribute, $value, $fail) {
            if (! preg_match('/^[\d\s.\/\-]+$/D', $value) || strlen(Normalizer::document($value)) !== 14
                || ! Normalizer::validDocument(Normalizer::document($value))) {
                $fail('Informe um CNPJ válido.');
            }
        }]]);
        $result = $lookup->find(Normalizer::document($data['cnpj']));
        abort_unless($actors->current($request->user())->isAdmin(), 403);

        return response()->json($result, $result['status'] === 'unavailable' ? 503 : 200,
            ['Cache-Control' => 'private, no-store']);
    }
}
