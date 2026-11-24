<?php

namespace App\Http\Controllers;

use App\PayrollDeductions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollDeductionController extends Controller
{
    public function show(Request $request, int $batch, PayrollDeductions $deductions): View
    {
        $record = $this->approved($batch);
        $items = json_decode($record->items, true, flags: JSON_THROW_ON_ERROR);
        $components = $deductions->components($items);
        $registrationNumbers = $deductions->registrationNumbers($components);
        $preview = $request->session()->get('payroll_deduction_preview');
        if (($preview['batch'] ?? null) !== $batch || ($preview['actor'] ?? null) !== $request->user()->id) {
            $preview = null;
        }
        $differences = collect($deductions->differences($components, $preview['overrides'] ?? []));
        $planned = (int) array_sum(array_column($components, 'planned'));
        $actual = $planned + $differences->sum('difference');

        return view('staff.loan-batches.deductions', compact('record', 'preview', 'registrationNumbers', 'differences', 'planned', 'actual'));
    }

    public function template(int $batch, PayrollDeductions $deductions): StreamedResponse
    {
        $record = $this->approved($batch);
        $components = $deductions->components(json_decode($record->items, true, flags: JSON_THROW_ON_ERROR));
        $registrationNumbers = $deductions->registrationNumbers($components);

        return response()->streamDownload(function () use ($deductions, $components, $registrationNumbers): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, PayrollDeductions::Headers, escape: '');
            foreach ($components as $key => $row) {
                $number = $registrationNumbers[$row['member_id']] ?? '';
                fputcsv($stream, [$key, $deductions->csvRegistration($number), $deductions->decimal($row['planned']), ''], escape: '');
            }
            fclose($stream);
        }, "payroll-$batch-deductions.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request, int $batch, PayrollDeductions $deductions): RedirectResponse
    {
        $record = $this->approved($batch);
        $request->session()->forget('payroll_deduction_preview');
        $upload = $request->validate(['deductions' => ['required', 'file', 'max:4096']])['deductions'];
        $components = $deductions->components(json_decode($record->items, true, flags: JSON_THROW_ON_ERROR));
        $overrides = $deductions->read($upload, $components, $deductions->registrationNumbers($components));
        $request->session()->put('payroll_deduction_preview', [
            'batch' => $batch, 'actor' => $request->user()->id, 'token' => Str::random(40),
            'overrides' => $overrides, 'source_sha256' => hash_file('sha256', $upload->getPathname()),
            'approved_sha256' => hash('sha256', $record->items),
        ]);

        return redirect()->route('staff.payroll-deductions.show', $batch)->withFragment('deduction-preview');
    }

    private function approved(int $batch): stdClass
    {
        $record = DB::table('loan_batches')->where('kind', 'repayment')->where('status', 'approved')->find($batch);
        abort_if($record === null, 404);

        return $record;
    }
}
