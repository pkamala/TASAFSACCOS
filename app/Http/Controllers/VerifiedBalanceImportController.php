<?php

namespace App\Http\Controllers;

use App\VerifiedBalanceImports;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VerifiedBalanceImportController extends Controller
{
    public function index(Request $request): View
    {
        $preview = $this->current($request);
        $counts = collect($preview['plan'] ?? [])->countBy('status');
        $ready = $counts->get('Ready', 0);
        $recorded = $counts->get('Already recorded', 0);
        $exceptions = $counts->sum() - $ready - $recorded;

        return view('staff.verified-balance-import', compact('preview', 'ready', 'recorded', 'exceptions'));
    }

    public function preview(Request $request, VerifiedBalanceImports $imports): RedirectResponse
    {
        $request->session()->forget('verified_balances_preview');
        $upload = $request->validate(['opening_balances' => ['required', 'file', 'max:2048']])['opening_balances'];
        $rows = $imports->read($upload);

        $request->session()->put('verified_balances_preview', [
            'actor' => $request->user()->id, 'token' => Str::random(40),
            'rows' => $rows, 'plan' => $imports->plan($rows), 'sha256' => hash_file('sha256', $upload->getPathname()),
        ]);

        return redirect()->route('staff.verified-balance-import.index')->withFragment('preview');
    }

    public function apply(Request $request, VerifiedBalanceImports $imports): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:40']]);
        $preview = $this->current($request);
        if (! $preview || ! hash_equals($preview['token'], $data['token'])) {
            throw ValidationException::withMessages(['opening_balances' => 'Run a fresh dry run first.']);
        }

        $count = $imports->apply($preview['rows'], $preview['plan'], $preview['sha256'], $request->user()->id);
        $request->session()->forget('verified_balances_preview');

        return redirect()->route('staff.verified-balance-import.index')->with('status', "$count verified opening balances recorded.");
    }

    public function template(): BinaryFileResponse
    {
        return response()->download(base_path('templates/verified_opening_balances.csv'), 'verified_opening_balances.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function current(Request $request): ?array
    {
        $preview = $request->session()->get('verified_balances_preview');

        return ($preview['actor'] ?? null) === $request->user()->id ? $preview : null;
    }
}
