<?php

namespace App\Http\Controllers;

use App\TablePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoanCategoryController extends Controller
{
    public function index(): View
    {
        $categories = DB::table('loan_categories as category')
            ->leftJoin('users as actor', 'actor.id', '=', 'category.created_by')
            ->orderByRaw('lower(category.name)')->orderBy('category.id')
            ->simplePaginate(TablePage::size(), ['category.*', 'actor.name AS actor']);

        return view('staff.loan-applications.categories', compact('categories'));
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
}
