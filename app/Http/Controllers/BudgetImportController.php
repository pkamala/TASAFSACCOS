<?php

namespace App\Http\Controllers;

use App\BudgetLines;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BudgetImportController extends Controller
{
    public const SessionKey = 'annual_budget_import';

    public function preview(Request $request, BudgetLines $budgets): RedirectResponse
    {
        $request->session()->forget(self::SessionKey);
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:1900,9998'],
            'budget_file' => ['required', 'file', 'max:2048'],
        ]);

        $year = (int) $data['year'];
        try {
            $rows = $budgets->read($data['budget_file']);
            $plan = $budgets->plan($year, $rows);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('staff.budgets.index', ['year' => $year, 'import' => 1]));
        }
        $request->session()->put(self::SessionKey, [
            'actor' => $request->user()->id,
            'token' => Str::random(40),
            'year' => $year,
            'rows' => $rows,
            'plan' => $plan,
        ]);

        return redirect()->route('staff.budgets.index', ['year' => $year, 'import' => 1]);
    }

    public function apply(Request $request, BudgetLines $budgets): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:40']]);
        $preview = $request->session()->get(self::SessionKey);
        $actor = $request->user()->id;
        if (($preview['actor'] ?? null) !== $actor || ! hash_equals($preview['token'], $data['token'])) {
            throw ValidationException::withMessages(['budget_file' => 'Preview the import before saving budget lines.']);
        }

        $year = $preview['year'];
        $count = DB::transaction(function () use ($budgets, $preview, $year, $actor): int {
            $budgets->lockYear($year);
            $plan = $budgets->plan($year, $preview['rows'], lock: true);
            if ($plan !== $preview['plan']) {
                throw ValidationException::withMessages(['budget_file' => 'The budget or chart of accounts changed. Upload the file again to review the latest values.']);
            }

            $changes = array_values(array_filter($plan, fn (array $line): bool => $line['amount_minor'] !== $line['previous_minor']));
            $budgets->save($year, $changes, $actor);

            return count($changes);
        }, 3);

        $request->session()->forget(self::SessionKey);

        return redirect()->route('staff.budgets.index', ['year' => $year])->with('status', "$count budget lines saved for $year.");
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            echo implode(',', BudgetLines::Headers)."\r\n";
        }, 'annual_budget_template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
