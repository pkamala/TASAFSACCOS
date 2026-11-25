<?php

namespace App\Http\Controllers;

use App\TablePage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoanCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $categories = DB::table('loan_categories as category')
            ->leftJoin('users as actor', 'actor.id', '=', 'category.created_by')
            ->orderByRaw('lower(category.name)')->orderBy('category.id')
            ->tap(TablePage::search(['category.name', 'category.created_at', 'actor.name']))
            ->paginate(TablePage::size(), ['category.*', 'actor.name AS actor']);

        $editing = null;
        if ($request->filled('edit')) {
            $selected = $request->validate(['edit' => ['required', 'integer', 'min:1']]);
            $editing = DB::table('loan_categories')->find($selected['edit']);
            abort_if($editing === null, 404);
        }

        return view('staff.loan-applications.categories', compact('categories', 'editing'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $created = DB::table('loan_categories')->insertOrIgnore([
            'name' => $data['name'], 'created_by' => $request->user()->id, 'created_at' => now(),
        ]);
        if ($created === 0) {
            throw ValidationException::withMessages(['name' => 'A loan category with this name already exists.']);
        }

        return redirect()->route('staff.loan-categories.index')->with('status', 'Loan category added.');
    }

    public function update(Request $request, int $category): RedirectResponse
    {
        $data = $request->validate(['category_name' => ['required', 'string', 'max:80']]);
        try {
            $updated = DB::transaction(fn (): int => DB::table('loan_categories')->where('id', $category)
                ->update(['name' => $data['category_name']]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['category_name' => 'A loan category with this name already exists.']);
        }
        abort_if($updated === 0, 404);

        return redirect()->route('staff.loan-categories.index')->with('status', 'Loan category renamed.');
    }
}
