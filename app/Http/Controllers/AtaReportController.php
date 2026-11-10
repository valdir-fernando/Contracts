<?php

namespace App\Http\Controllers;

use App\Infrastructure\Atas\AtaReportPdf;
use App\Infrastructure\Atas\AtaReportQuery;
use App\Infrastructure\Atas\AtaSummaryQuery;
use Illuminate\Http\Request;

class AtaReportController extends Controller
{
    public function index(Request $request, AtaReportQuery $query)
    {
        $data = $query->for($request->user(), $request->query());
        if ($data['rows']->currentPage() > $data['rows']->lastPage()) {
            return redirect()->to($data['rows']->url($data['rows']->lastPage()));
        }

        return response()->view('reports.atas', $data)->header('Cache-Control', 'private, no-store');
    }

    public function pdf(Request $request, AtaReportPdf $pdf)
    {
        return response($pdf->generate($request->user(), $request->query()), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="relatorio-atas-'.today()->format('Y-m-d').'.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request, int $ata, AtaSummaryQuery $query)
    {
        return response()->view('reports.ata-summary', $query->for($request->user(), $ata))
            ->header('Cache-Control', 'private, no-store');
    }
}
