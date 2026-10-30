<?php

namespace App\Http\Controllers;

use App\Infrastructure\Dfd\DfdAccess;
use App\Infrastructure\Dfd\DfdPdf;
use App\Infrastructure\Dfd\DfdRegistration;
use App\Infrastructure\Security\CatalogAccess;
use App\Services\Legacy\Normalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DfdController extends Controller
{
    public function pdf(Request $request, int $dfd, DfdPdf $pdf)
    {
        $bytes = $pdf->generate($request->user(), $request->session()->get('fund_id'), $dfd);

        return response($bytes)->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="dfd-'.$dfd.'.pdf"')
            ->header('Cache-Control', 'private, no-store');
    }

    public function classes(Request $request, DfdAccess $access)
    {
        $access->context($request->user(), $request->session()->get('fund_id'));

        return response()->json(['classes' => DB::table('tb_Produtos_Prodata')->where('Inativo', 0)->whereNotNull('Classe')->whereRaw("TRIM(Classe) != ''")->distinct()->orderBy('Classe')->pluck('Classe')])
            ->header('Cache-Control', 'private, no-store');
    }

    public function search(Request $request, DfdAccess $access)
    {
        [$actor, $fund] = $access->context($request->user(), $request->session()->get('fund_id'));
        $data = $request->validate(['q' => 'nullable|string|max:100']);
        $records = $access->visible($actor, $fund->id)->when(filled($data['q'] ?? null), fn ($query) => $query->where('N_DFD', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['q']).'%'))
            ->orderByDesc('ID')->limit(50)->get(['ID', 'N_DFD', 'Data_DFD', 'Categoria', 'Processo_DFD']);

        return response()->json(['records' => $records->map(fn ($record) => ['number' => $record->N_DFD, 'date' => $record->Data_DFD,
            'category' => $record->Categoria, 'process' => $record->Processo_DFD, 'url' => route('dfd-compra.edit', $record->ID)])])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, DfdAccess $access, ?int $dfd = null)
    {
        [$actor, $fund] = $access->context($request->user(), $request->session()->get('fund_id'));
        $data = $request->validate(['classe' => 'nullable|string|max:1000', 'tab' => 'nullable|in:general,products',
            'q' => 'nullable|string|max:100', 'catalog_product' => 'nullable|integer|min:1', 'item' => 'nullable|integer|min:1']);
        $record = $dfd ? $access->record($actor, $fund->id, $dfd) : null;
        $oldCategory = old('_dfd_id') === null || old('_dfd_id') === '' ? old('Categoria') : null;
        $category = $record?->Categoria ?? ($data['classe'] ?? (is_string($oldCategory) && mb_strlen($oldCategory) <= 1000 ? $oldCategory : ''));
        if (! $record && $category !== '') {
            abort_unless(DB::table('tb_Produtos_Prodata')->where('Classe', $category)->where('Inativo', 0)->exists(), 422, 'Classe indisponível.');
        }
        abort_if(! $record && ($data['tab'] ?? '') === 'products', 403, 'Salve a DFD antes de acessar Produtos.');
        $departments = Normalizer::name($fund->name) === 'MUNICIPIO DE NIQUELANDIA' ? CatalogAccess::departments($actor)->orderBy('name')->get(['id', 'name']) : collect();
        $budgets = CatalogAccess::byOrganization($actor, DB::table('tb_Dotacao')->whereNull('deleted_at'), 'Fundo', 'Secretaria', false)
            ->whereIn('Fundo', [$fund->name, Normalizer::name($fund->name)])->orderBy('Ficha')->get(['ID_Dotacao', 'Ficha', 'Descricao', 'Secretaria', 'Orgao', 'Gestao', 'Unidade', 'Funcao', 'SubFunc', 'Programa', 'Projeto', 'Fonte', 'Codigo', 'Dotacao']);
        $items = $record ? DB::table('tb_Produtos_DFD')->where('ID_DFD', $record->ID)->whereNull('deleted_at')->orderBy('ITEM')
            ->get(['ID', 'ID_PROD', 'ITEM', 'COD_PROD', 'PROD_SIMPLES', 'CARACT', 'UND', 'QTD']) : collect();
        $item = isset($data['item']) ? $items->firstWhere('ID', (int) $data['item']) : null;
        abort_if(isset($data['item']) && ! $item, 404);
        $catalog = $record ? DB::table('tb_Produtos_Prodata')->where('Classe', $category)->where('Inativo', 0)
            ->orderBy('Descricao')->get(['ID', 'ID_Prodata', 'Descricao', 'UND']) : collect();
        $selectedId = $item?->ID_PROD ?? ($data['catalog_product'] ?? null);
        $selected = $record && $selectedId ? DB::table('tb_Produtos_Prodata')->where('ID', $selectedId)->where('Classe', $category)->where('Inativo', 0)->first(['ID', 'ID_Prodata', 'Descricao', 'UND']) : null;
        abort_if(isset($data['catalog_product']) && ! $selected, 404);
        $values = $record ? array_intersect_key((array) $record, array_flip([...DfdRegistration::TEXT_FIELDS, 'N_DFD', 'Data_DFD', 'Data_Conclusao', 'Ficha', 'department_id', 'version'])) : ['Data_DFD' => now()->format('Y-m-d')];
        $canRestore = (string) old('_dfd_id', '') === (string) ($record?->ID ?? '') && old('Categoria') === $category;

        return response()->view('dfd-compra.index', compact('record', 'category', 'fund', 'departments', 'budgets', 'items', 'item', 'catalog', 'selected', 'values', 'canRestore'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, DfdRegistration $registration)
    {
        $id = $registration->save($request->user(), $request->session()->get('fund_id'), null, $request->all());

        return redirect()->route('dfd-compra.edit', $id)->with('status', 'DFD salva. A aba Produtos está disponível.');
    }

    public function update(Request $request, int $dfd, DfdRegistration $registration)
    {
        $registration->save($request->user(), $request->session()->get('fund_id'), $dfd, $request->all());

        return redirect()->route('dfd-compra.edit', $dfd)->with('status', 'DFD atualizada.');
    }

    public function destroy(Request $request, int $dfd, DfdRegistration $registration)
    {
        $registration->delete($request->user(), $request->session()->get('fund_id'), $dfd, $request->all());

        return redirect()->route('dfd-compra.index')->with('status', 'DFD excluída. O histórico foi preservado.');
    }

    public function saveProduct(Request $request, int $dfd, DfdRegistration $registration, ?int $item = null)
    {
        $registration->product($request->user(), $request->session()->get('fund_id'), $dfd, $item, $request->all());

        return redirect()->route('dfd-compra.edit', [$dfd, 'tab' => 'products'])->with('status', 'Produto salvo na DFD.');
    }

    public function deleteProduct(Request $request, int $dfd, int $item, DfdRegistration $registration)
    {
        $registration->product($request->user(), $request->session()->get('fund_id'), $dfd, $item, $request->all(), true);

        return redirect()->route('dfd-compra.edit', [$dfd, 'tab' => 'products'])->with('status', 'Produto excluído. O histórico foi preservado.');
    }
}
