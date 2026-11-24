<?php

namespace App\Http\Controllers;

use App\TablePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AssetRegisterController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['edit' => ['nullable', 'integer', 'min:1']]);
        $item = isset($data['edit']) ? DB::table('asset_register')->find($data['edit']) : null;
        abort_if(isset($data['edit']) && $item === null, 404);
        $assets = DB::table('asset_register')->orderBy('id')->simplePaginate(TablePage::size());

        return view('staff.asset-register', compact('assets', 'item'));
    }

    public function store(Request $request): RedirectResponse
    {
        DB::table('asset_register')->insert([...$this->values($request), 'created_at' => now()]);

        return redirect()->route('staff.assets.index')->with('status', 'Asset registered.');
    }

    public function update(Request $request, int $asset): RedirectResponse
    {
        $updated = DB::table('asset_register')->where('id', $asset)->update($this->values($request));
        abort_if($updated === 0, 404);

        return redirect()->route('staff.assets.index')->with('status', 'Asset updated.');
    }

    private function values(Request $request): array
    {
        $data = $request->validate([
            'item' => ['required', 'string', 'max:160'],
            'value' => ['required', 'numeric', 'decimal:0,2', 'between:0.01,999999999999.99'],
            'depreciation' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'lte:value'],
        ]);

        return [
            'item' => $data['item'],
            'value_minor' => (int) round($data['value'] * 100),
            'depreciation_minor' => (int) round($data['depreciation'] * 100),
            'updated_by' => $request->user()->id,
            'updated_at' => now(),
        ];
    }
}
