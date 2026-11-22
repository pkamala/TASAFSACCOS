<?php

namespace App\Http\Controllers;

use App\LegacyLoanBalanceReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LegacyLoanReconciliationController extends Controller
{
    public function index(Request $request, LegacyLoanBalanceReport $report): View
    {
        $snapshots = $report->snapshots();
        $filters = $request->validate([
            'snapshot' => ['nullable', 'string', Rule::in($snapshots)],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return view('staff.legacy-loan-reconciliation', [
            'snapshots' => $snapshots,
            'snapshot' => $filters['snapshot'] ?? null,
            'asOf' => $filters['as_of'] ?? null,
            'comparison' => null,
        ]);
    }

    public function compare(Request $request, LegacyLoanBalanceReport $report): View
    {
        $snapshots = $report->snapshots();
        $input = $request->validate([
            'snapshot' => ['required', 'string', Rule::in($snapshots)],
            'as_of' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'report' => ['required', 'file', 'max:1024'],
        ]);

        $reference = $report->read($input['report']);
        $snapshot = $input['snapshot'];
        $asOf = $input['as_of'];
        $reconstructed = $this->reconstruct($snapshot, $asOf, array_keys($reference));
        $unlinked = DB::table('legacy_onboarding_rows as source')
            ->where('source.source_sha256', $snapshot)->where('source.source_table', 'repayments')
            ->whereRaw("source.data->>'resolved_loan_id' IS NULL")
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('legacy_loan_corrections as correction')
                ->whereColumn('correction.source_sha256', 'source.source_sha256')
                ->whereColumn('correction.source_table', 'source.source_table')
                ->whereColumn('correction.source_id', 'source.source_id'))
            ->count();

        $comparison = [];
        foreach ($reference as $id => $reported) {
            $loan = $reconstructed[$id] ?? null;
            $balance = $loan?->principal_minor === null || $loan->applied_minor === null
                ? null : (int) $loan->principal_minor - (int) $loan->applied_minor;
            $difference = $balance === null ? null : $balance - $reported;
            $review = match (true) {
                $loan === null => 'Loan missing from snapshot',
                $loan->disbursed_on !== null && $loan->disbursed_on > $asOf => 'Disbursed after report date',
                $balance === null => 'Principal or repayment unavailable',
                $loan->disbursed_on === null => 'Disbursement date missing',
                $loan->excluded > 0 => 'Repayments need review',
                $difference !== 0 => 'Variance',
                default => 'Amounts match',
            };
            if ($loan?->disbursed_on !== null && $loan->disbursed_on > $asOf) {
                $balance = $difference = null;
            }

            $comparison[] = [
                'id' => $id,
                'code' => $loan?->code,
                'reported' => $reported,
                'reconstructed' => $balance,
                'difference' => $difference,
                'review' => $review,
                'excluded' => $loan?->excluded ?? 0,
            ];
        }

        return view('staff.legacy-loan-reconciliation', compact('snapshots', 'comparison', 'snapshot', 'asOf', 'unlinked'));
    }

    public function download(): BinaryFileResponse
    {
        return response()->download(
            base_path('templates/loan-import/legacy_balance_report.csv'),
            'legacy_balance_report.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    private function reconstruct(string $snapshot, string $asOf, array $loanIds): array
    {
        $ids = implode(',', $loanIds);
        $sql = <<<'SQL'
WITH inputs AS (
    SELECT CAST(? AS text) AS snapshot, CAST(? AS date) AS cutoff, string_to_array(?, ',')::bigint[] AS loan_ids
), repayments AS (
    SELECT coalesce(correction.loan_id::text, source.data->>'resolved_loan_id') AS loan_id,
           source.data->>'paymentdate' AS paid_on,
           source.data->>'principlepayed' AS principal,
           source.issues,
           correction.source_id IS NOT NULL AS corrected
    FROM inputs
    JOIN legacy_onboarding_rows source ON source.source_sha256 = inputs.snapshot AND source.source_table = 'repayments'
    LEFT JOIN legacy_loan_corrections correction USING (source_sha256, source_table, source_id)
), typed AS (
    SELECT loan_id,
           CASE WHEN paid_on ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' AND pg_input_is_valid(paid_on, 'date') THEN paid_on::date END AS paid_on,
           CASE WHEN principal ~ '^-?[0-9]{1,12}(\.[0-9]+)?$' THEN round(principal::numeric * 100) END AS principal_minor,
           corrected OR issues = '[]'::jsonb AS link_ok
    FROM repayments
    WHERE loan_id IS NOT NULL
), allocations AS (
    SELECT loan_id, coalesce(sum(principal_minor) FILTER (WHERE link_ok AND paid_on <= inputs.cutoff), 0) AS applied_minor,
           count(*) FILTER (WHERE paid_on IS NULL OR (paid_on <= inputs.cutoff AND (principal_minor IS NULL OR NOT link_ok))) AS excluded
    FROM typed CROSS JOIN inputs GROUP BY loan_id
)
SELECT source.source_id AS id, source.data->>'loan_code' AS code,
       CASE WHEN source.data->>'disbursement_date' ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
            AND pg_input_is_valid(source.data->>'disbursement_date', 'date') THEN source.data->>'disbursement_date' END AS disbursed_on,
       CASE WHEN source.data->>'principle' ~ '^[0-9]{1,12}(\.[0-9]+)?$' THEN round((source.data->>'principle')::numeric * 100) END AS principal_minor,
       coalesce(allocations.applied_minor, 0) AS applied_minor, coalesce(allocations.excluded, 0) AS excluded
FROM inputs
JOIN legacy_onboarding_rows source ON source.source_sha256 = inputs.snapshot AND source.source_table = 'loans'
LEFT JOIN allocations ON allocations.loan_id = source.source_id::text
WHERE source.source_id = ANY(inputs.loan_ids)
SQL;

        return array_column(DB::select($sql, [$snapshot, $asOf, $ids]), null, 'id');
    }
}
