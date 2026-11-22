<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ChartOfAccountsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,retired,all'],
            'category' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
        ]);
        $search = $input['q'] ?? '';
        $status = $input['status'] ?? 'active';
        $category = $input['category'] ?? null;
        $categories = DB::table('account_categories')->orderBy('name')->get(['id', 'name']);
        $duplicateCodes = DB::table('chart_accounts')->whereNull('retired_at')->select('code')->groupBy('code')->havingRaw('count(*) > 1');

        $accounts = DB::table('chart_accounts as account')
            ->join('account_types as type', 'type.id', '=', 'account.account_type_id')
            ->join('account_categories as category', 'category.id', '=', 'type.account_category_id')
            ->leftJoinSub($duplicateCodes, 'duplicate', 'duplicate.code', '=', 'account.code')
            ->select([
                'account.id', 'account.legacy_id', 'account.retired_at', 'account.code', 'account.name', 'account.name_sw', 'account.name_en',
                'category.name as category', 'type.name as type', 'duplicate.code as duplicate_code',
            ])
            ->when($status === 'active', fn (Builder $query) => $query->whereNull('account.retired_at'))
            ->when($status === 'retired', fn (Builder $query) => $query->whereNotNull('account.retired_at'))
            ->when($category, fn (Builder $query) => $query->where('category.id', $category))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';

                $query->where(function (Builder $query) use ($term): void {
                    $query->whereLike('account.code', $term)
                        ->orWhereLike('account.name', $term)
                        ->orWhereLike('account.name_sw', $term)
                        ->orWhereLike('account.name_en', $term);
                });
            })
            ->orderBy('account.code')->orderBy('account.id')
            ->simplePaginate(25)->appends(['q' => $search, 'category' => $category, 'status' => $status]);

        return view('staff.accounts', compact('accounts', 'categories', 'category', 'search', 'status'));
    }
}
