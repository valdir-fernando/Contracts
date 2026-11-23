<?php

namespace App\Http\Controllers;

use App\Infrastructure\Atas\AtaDeliveryOrders;
use Illuminate\Http\Request;

final class AtaDeliveryOrderController
{
    private const NUMBER_RULES = ['required', 'string', 'max:24', 'regex:~^[0-9]{3,}/[0-9]{2}$~D'];

    public function store(Request $request, int $ata, AtaDeliveryOrders $orders)
    {
        $input = $request->validate(['version' => 'required|integer|min:1', 'request_id' => 'required|uuid']);

        return response()->json($orders->store($request->user(), $ata, $this->fund($request), (int) $input['version'], $input['request_id']),
            201, ['Cache-Control' => 'private, no-store']);
    }

    public function lookup(Request $request, int $ata, AtaDeliveryOrders $orders)
    {
        $input = $request->validate(['number' => self::NUMBER_RULES]);

        return response()->json($orders->lookup($request->user(), $ata, $this->fund($request), $input['number']),
            200, ['Cache-Control' => 'private, no-store']);
    }

    public function destroy(Request $request, int $ata, AtaDeliveryOrders $orders)
    {
        $input = $request->validate(['number' => self::NUMBER_RULES, 'version' => 'required|integer|min:1']);
        $orders->remove($request->user(), $ata, $this->fund($request), (int) $input['version'], $input['number']);

        return response()->noContent(204, ['Cache-Control' => 'private, no-store']);
    }

    private function fund(Request $request): ?int
    {
        return $request->session()->has('fund_id') ? (int) $request->session()->get('fund_id') : null;
    }
}
