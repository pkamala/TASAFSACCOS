<?php

namespace App\Http\Controllers;

use App\TablePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PayrollCorrectionController extends Controller
{
    public function index(Request $request): View
    {
        $input = $request->validate(['status' => ['nullable', 'in:open,resolved']]);
        $status = $input['status'] ?? 'open';
        $entries = DB::table('payroll_corrections as correction')
            ->leftJoin('users as actor', 'actor.id', '=', 'correction.resolved_by')
            ->whereNull('correction.resolved_at', not: $status === 'resolved')
            ->orderBy('correction.payroll_month')->orderBy('correction.id')
            ->tap(TablePage::search(['correction.payroll_month', 'correction.entry', 'correction.source_file', 'correction.source_row', 'correction.issue', 'correction.resolution', 'actor.name']))
            ->paginate(TablePage::size(), ['correction.*', 'actor.name as resolver']);

        return view('staff.payroll-corrections', compact('entries', 'status'));
    }

    public function resolve(Request $request, int $correction): RedirectResponse
    {
        $input = $request->validate(['resolution' => ['required', 'string', 'max:500']]);
        $entry = DB::table('payroll_corrections')->where('id', $correction);
        abort_unless($entry->exists(), 404);

        $changed = $entry->whereNull('resolved_at')->update([
            'resolution' => $input['resolution'],
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return back()->with('status', $changed ? 'Correction marked resolved.' : 'This correction was already resolved.');
    }
}
