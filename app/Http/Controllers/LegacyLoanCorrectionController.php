<?php

namespace App\Http\Controllers;

use App\LegacyLoanCorrections;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LegacyLoanCorrectionController extends Controller
{
    private const Templates = [
        'loans' => ['loans.csv', 'Loans', 'One row per loan.'],
        'installments' => ['installments.csv', 'Installments', 'One row per scheduled installment, linked by loan reference.'],
        'repayment-allocations' => ['repayment_allocations.csv', 'Repayment allocations', 'One row per allocation. Multiple allocations can share a receipt reference.'],
        'legacy-link-corrections' => ['legacy_link_corrections.csv', 'Legacy link corrections', 'One row per reviewed legacy exception.'],
    ];

    private const Checks = [
        'unresolved_loan_id' => ['loanschedules', 'Installment has no matching loan', 'Find the loan on the signed schedule or disbursement record. Record the confirmed legacy loan ID.'],
        'conflict_loan_link' => ['repayments', 'Repayment points to two different loans', 'Compare the receipt, member account, and installment schedule. Confirm which loan and installment received the payment.'],
        'unresolved_loan_link' => ['repayments', 'Repayment has no matching loan', 'Trace the receipt or payroll deduction back to a loan. Leave the row open if the evidence is incomplete.'],
        'unresolved_loanschedule_id' => ['repayments', 'Repayment has no matching installment', 'Check the original schedule and confirm the installment ID. Check with finance if the payment was applied outside the schedule.'],
        'unresolved_member_id' => ['loans', 'Loan has no matching member', 'Verify the legacy member ID against the original member register.'],
        'unresolved_loancategory_id' => ['loans', 'Loan has no matching product', 'Verify the loan product against the approval or disbursement record.'],
    ];

    public function index(Request $request): View
    {
        $input = $request->validate([
            'snapshot' => ['nullable', 'regex:/\A[a-f0-9]{64}\z/'],
            'issue' => ['nullable', Rule::in(array_keys(self::Checks))],
        ]);
        $snapshots = DB::table('legacy_onboarding_rows')->where('source_table', 'loans')
            ->distinct()->orderBy('source_sha256')->pluck('source_sha256');
        $snapshot = $input['snapshot'] ?? $snapshots->first();
        $issue = $input['issue'] ?? array_key_first(self::Checks);
        abort_if($snapshot !== null && ! $snapshots->containsStrict($snapshot), 404);

        $counts = [];
        $exceptions = null;
        $scheduleLoans = collect();
        $applied = null;
        $preview = $request->session()->get('legacy_corrections_preview');
        if (($preview['snapshot'] ?? null) !== $snapshot || ($preview['actor'] ?? null) !== $request->user()->id) {
            $preview = null;
        }

        if ($snapshot !== null) {
            $rows = DB::table('legacy_onboarding_rows')->where('source_sha256', $snapshot);
            $pending = $this->pendingRows($snapshot);
            $summary = (clone $pending)->whereIn('source_table', ['loans', 'loanschedules', 'repayments']);
            foreach (self::Checks as $flag => [$table]) {
                $summary->selectRaw("count(*) FILTER (WHERE source_table = ? AND jsonb_exists(issues, ?)) AS $flag", [$table, $flag]);
            }
            $counts = (array) $summary->first();

            $exceptions = (clone $pending)->where('source_table', self::Checks[$issue][0])
                ->whereRaw('jsonb_exists(issues, ?)', [$issue])
                ->orderBy('source_id')->simplePaginate(25)
                ->appends(compact('snapshot', 'issue'))
                ->through(fn ($row): array => [
                    'id' => $row->source_id,
                    'data' => json_decode($row->data, true, flags: JSON_THROW_ON_ERROR),
                ]);

            $applied = DB::table('legacy_loan_corrections')->where('source_sha256', $snapshot)
                ->join('users', 'users.id', '=', 'legacy_loan_corrections.applied_by')
                ->select('legacy_loan_corrections.*', 'users.name as actor')
                ->orderByDesc('applied_at')->orderBy('source_table')->orderBy('source_id')
                ->simplePaginate(25, ['*'], 'applied_page')->appends(compact('snapshot', 'issue'));

            $scheduleIds = $exceptions->getCollection()->pluck('data.loanschedule_id')
                ->filter(fn ($id): bool => is_string($id) && ctype_digit($id))->unique();

            if ($scheduleIds->isNotEmpty()) {
                $scheduleLoans = (clone $rows)->where('source_table', 'loanschedules')->whereIn('source_id', $scheduleIds)
                    ->selectRaw("source_id, data->>'loan_id' AS loan_id")->pluck('loan_id', 'source_id');
            }
        }

        return view('staff.legacy-loan-corrections', [
            'templates' => self::Templates,
            'checks' => self::Checks,
            ...compact('snapshots', 'snapshot', 'issue', 'counts', 'exceptions', 'scheduleLoans', 'applied', 'preview'),
        ]);
    }

    public function worklist(Request $request): StreamedResponse
    {
        $snapshot = $request->validate(['snapshot' => ['required', 'regex:/\A[a-f0-9]{64}\z/']])['snapshot'];
        abort_unless(DB::table('legacy_onboarding_rows')->where('source_sha256', $snapshot)->where('source_table', 'loans')->exists(), 404);

        $rows = $this->pendingRows($snapshot)
            ->leftJoin('legacy_onboarding_rows as schedule', function (JoinClause $join): void {
                $join->on('schedule.source_sha256', '=', 'legacy_onboarding_rows.source_sha256')
                    ->where('schedule.source_table', 'loanschedules')
                    ->whereRaw("schedule.source_id = CASE WHEN legacy_onboarding_rows.data->>'loanschedule_id' ~ '^[0-9]{1,18}$'
                        THEN (legacy_onboarding_rows.data->>'loanschedule_id')::bigint END");
            })
            ->whereIn('legacy_onboarding_rows.source_table', ['loans', 'loanschedules', 'repayments'])
            ->whereRaw("legacy_onboarding_rows.issues <> '[]'::jsonb")
            ->orderBy('legacy_onboarding_rows.source_table')->orderBy('legacy_onboarding_rows.source_id')
            ->select('legacy_onboarding_rows.source_table', 'legacy_onboarding_rows.source_id', 'legacy_onboarding_rows.issues', 'legacy_onboarding_rows.data')
            ->selectRaw("schedule.data->>'loan_id' AS original_installment_loan_id");

        return response()->streamDownload(function () use ($rows, $snapshot): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'snapshot_sha256', 'source_table', 'source_id', 'unresolved_flags', 'receipt_id', 'payroll_record_id',
                'direct_loan_id', 'installment_id', 'original_installment_loan_id', 'legacy_member_id', 'legacy_loan_category_id',
            ], escape: '');
            foreach ($rows->cursor() as $entry) {
                $data = json_decode($entry->data, true, flags: JSON_THROW_ON_ERROR);
                $flags = json_decode($entry->issues, true, flags: JSON_THROW_ON_ERROR);
                $values = [
                    $snapshot, $entry->source_table, $entry->source_id, implode(' | ', $flags),
                    $data['payment_id'] ?? null, $data['monthly_id'] ?? null, $data['rploan_id'] ?? $data['loan_id'] ?? null,
                    $data['loanschedule_id'] ?? null, $entry->original_installment_loan_id,
                    $data['member_id'] ?? null, $data['loancategory_id'] ?? null,
                ];
                fputcsv($stream, array_map($this->spreadsheetCell(...), $values), escape: '');
            }
            fclose($stream);
        }, 'legacy-correction-worklist-'.substr($snapshot, 0, 12).'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function preview(Request $request, LegacyLoanCorrections $corrections): RedirectResponse
    {
        $request->session()->forget('legacy_corrections_preview');
        $input = $request->validate([
            'snapshot' => ['required', 'regex:/\\A[a-f0-9]{64}\\z/'],
            'corrections' => ['required', 'file', 'max:2048'],
        ]);
        $snapshot = $input['snapshot'];
        $upload = $input['corrections'];
        $rows = $corrections->read($upload, $snapshot);
        $corrections->validateLinks($snapshot, $rows);

        $request->session()->put('legacy_corrections_preview', [
            'token' => Str::random(40),
            'actor' => $request->user()->id,
            'snapshot' => $snapshot,
            'rows' => $rows,
            'checksum' => hash_file('sha256', $upload->getPathname()),
        ]);

        return redirect()->route('staff.loan-corrections.index', compact('snapshot'))->withFragment('correction-upload');
    }

    public function apply(Request $request, LegacyLoanCorrections $corrections): RedirectResponse
    {
        $input = $request->validate(['token' => ['required', 'string', 'size:40']]);
        $preview = $request->session()->get('legacy_corrections_preview');
        if (! $preview || $preview['actor'] !== $request->user()->id || ! hash_equals($preview['token'], $input['token'])) {
            throw ValidationException::withMessages(['corrections' => 'Run a fresh dry run before applying corrections.']);
        }

        $snapshot = $preview['snapshot'];
        $corrections->apply($snapshot, $preview['rows'], $preview['checksum'], $request->user()->id);
        $request->session()->forget('legacy_corrections_preview');

        return redirect()->route('staff.loan-corrections.index', compact('snapshot'))
            ->with('status', count($preview['rows']).' corrections applied.');
    }

    public function download(string $template): BinaryFileResponse
    {
        abort_unless(isset(self::Templates[$template]), 404);
        [$filename] = self::Templates[$template];

        return response()->download(base_path('templates/loan-import/'.$filename), $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function pendingRows(string $snapshot): Builder
    {
        return DB::table('legacy_onboarding_rows')->where('legacy_onboarding_rows.source_sha256', $snapshot)
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('legacy_loan_corrections as correction')
                    ->whereColumn('correction.source_sha256', 'legacy_onboarding_rows.source_sha256')
                    ->whereColumn('correction.source_table', 'legacy_onboarding_rows.source_table')
                    ->whereColumn('correction.source_id', 'legacy_onboarding_rows.source_id');
            });
    }

    private function spreadsheetCell(string|int|null $value): string
    {
        $cell = (string) $value;

        return preg_match('/\A(?:[\x00-\x20]|\x{FEFF})*[=+@-]|\A[\t\r\n]/u', $cell) ? "'".$cell : $cell;
    }
}
