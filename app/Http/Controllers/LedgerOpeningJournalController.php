<?php

namespace App\Http\Controllers;

use App\LedgerOpeningJournal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LedgerOpeningJournalController extends Controller
{
    public function index(Request $request): View
    {
        $preview = $this->previewFor($request);
        $posted = DB::table('ledger_opening_journals as journal')
            ->join('users as actor', 'actor.id', '=', 'journal.posted_by')->first(['journal.*', 'actor.name as actor']);
        $lines = $posted ? DB::table('ledger_opening_lines as line')
            ->join('chart_accounts as account', 'account.id', '=', 'line.chart_account_id')
            ->where('line.ledger_opening_journal_id', $posted->id)->orderBy('account.code')->orderBy('account.legacy_id')
            ->get(['account.code', 'account.name', 'account.legacy_id', 'line.signed_minor'])->map(fn ($row): array => (array) $row)
            : collect($preview['plan'] ?? []);
        $exceptions = $preview && ! $posted ? $lines->where('status', '!=', 'Ready')->count() : 0;

        return view('staff.ledger-opening-journal', compact('preview', 'posted', 'lines', 'exceptions'));
    }

    public function preview(Request $request, LedgerOpeningJournal $ledger): RedirectResponse
    {
        $request->session()->forget('ledger_opening_preview');
        $data = $request->validate([
            'effective_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'review_reference' => ['required', 'string', 'max:500'],
            'opening_journal' => ['required', 'file', 'max:1024'],
        ]);

        $rows = $ledger->read($data['opening_journal']);
        $request->session()->put('ledger_opening_preview', [
            'effective_on' => $data['effective_on'], 'review_reference' => $data['review_reference'],
            'actor' => $request->user()->id, 'token' => Str::random(40), 'rows' => $rows,
            'plan' => $ledger->plan($rows, $data['effective_on']),
            'sha256' => hash_file('sha256', $data['opening_journal']->getPathname()),
        ]);

        return redirect()->route('staff.ledger-opening.index')->withFragment('opening-lines');
    }

    public function post(Request $request, LedgerOpeningJournal $ledger): RedirectResponse
    {
        $token = $request->validate(['token' => ['required', 'string', 'size:40']])['token'];
        $preview = $this->previewFor($request);
        if (! $preview || ! hash_equals($preview['token'], $token)) {
            throw ValidationException::withMessages(['opening_journal' => 'Preview the opening journal before posting.']);
        }

        $ledger->post($preview, $request->user()->id);
        $request->session()->forget('ledger_opening_preview');

        return redirect()->route('staff.ledger-opening.index')->with('status', 'Opening journal posted.');
    }

    public function template(): BinaryFileResponse
    {
        return response()->download(base_path('templates/ledger_opening_journal.csv'), 'ledger_opening_journal.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function previewFor(Request $request): ?array
    {
        $preview = $request->session()->get('ledger_opening_preview');

        return ($preview['actor'] ?? null) === $request->user()->id ? $preview : null;
    }
}
