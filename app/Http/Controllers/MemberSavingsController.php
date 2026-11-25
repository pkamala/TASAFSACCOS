<?php

namespace App\Http\Controllers;

use App\MemberFunds;
use App\TablePage;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MemberSavingsController extends Controller
{
    public function __invoke(Request $request, MemberFunds $funds): Response
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'product' => ['nullable', 'in:savings,deposits,shares'],
        ]);
        $product = $input['product'] ?? 'savings';
        $productLabel = ucfirst($product);
        $search = $input['q'] ?? '';
        $members = DB::table('members')
            ->select(['members.id', 'members.registration_no', 'members.first_name', 'members.last_name', 'members.status'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->whereLike('members.registration_no', $term)
                        ->orWhereRaw("concat_ws(' ', members.first_name, members.last_name) ILIKE ?", [$term]);
                });
            })
            ->orderByRaw('lower(members.first_name), lower(members.last_name)')->orderBy('members.id');

        $total = (clone $members)->count();
        $rows = $funds->withBalances($members)->paginate(TablePage::size(), total: $total);
        $view = view('staff.member-savings', compact('rows', 'search', 'product', 'productLabel'));

        return response($request->ajax() ? $view->fragment('table-results') : $view, 200, ['Cache-Control' => 'private, no-store']);
    }
}
