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
            'as_of' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'q' => ['nullable', 'string', 'max:100'],
            'product' => ['nullable', 'in:savings,deposits,shares'],
        ]);
        $product = $input['product'] ?? 'savings';
        $productLabel = ucfirst($product);
        $search = $input['q'] ?? '';
        $asOf = $input['as_of'] ?? null;
        $members = DB::table('members')
            ->tap(TablePage::memberStatus('members.status'))
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
        $rows = $funds->withBalances($members, $asOf)->paginate(TablePage::size(), total: $total);
        $view = view('staff.member-savings', compact('rows', 'search', 'product', 'productLabel', 'asOf'));

        return response($request->ajax() ? $view->fragment('table-results') : $view, 200, ['Cache-Control' => 'private, no-store']);
    }
}
