<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonthlyStatementDraftController extends Controller
{
    public function index(Request $request): View
    {
        [$period, $start, $end] = $this->period($request);
        $drafts = $this->drafts($start, $end);
        $ready = $drafts->whereNull('hold')->count();

        return view('staff.monthly-statement-drafts', compact('period', 'start', 'end', 'drafts', 'ready'));
    }

    public function download(Request $request): StreamedResponse
    {
        [$period, $start, $end] = $this->period($request);
        $drafts = $this->drafts($start, $end)->whereNull('hold');
        if ($drafts->isEmpty()) {
            throw ValidationException::withMessages(['period' => 'No statements are ready to export for this month.']);
        }

        return response()->streamDownload(function () use ($drafts): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['registration_no', 'email', 'loan_balance', 'monthly_contribution', 'savings_balance'], escape: '');
            foreach ($drafts as $row) {
                fputcsv($stream, [
                    $row->registration_no, $row->email, $this->decimal($row->loan_minor),
                    $this->decimal($row->contribution_minor), $this->decimal($row->savings_minor),
                ], escape: '');
            }
            fclose($stream);
        }, "monthly-statement-draft-$period.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function period(Request $request): array
    {
        $today = CarbonImmutable::now('Africa/Dar_es_Salaam');
        $data = $request->validate(['period' => ['nullable', 'regex:/\A[2-9]\d{3}-(?:0[1-9]|1[0-2])\z/']]);
        $period = $data['period'] ?? $today->subMonthNoOverflow()->format('Y-m');
        if ($period >= $today->format('Y-m')) {
            throw ValidationException::withMessages(['period' => 'Choose a closed month.']);
        }
        $month = CarbonImmutable::parse($period.'-01', 'Africa/Dar_es_Salaam');

        return [$period, $month->toDateString(), $month->endOfMonth()->toDateString()];
    }

    private function drafts(string $start, string $end): Collection
    {
        $opening = DB::table('member_verified_balances')
            ->selectRaw('distinct on (member_id) member_id, as_of, savings_minor')
            ->orderBy('member_id')->orderByDesc('as_of')->orderByDesc('id');
        $funds = DB::table('member_fund_transactions')->where('effective_on', '<=', $end)->groupBy('member_id')
            ->select('member_id')
            ->selectRaw("sum(signed_minor) FILTER (WHERE kind <> 'share_purchase') AS savings_movement")
            ->selectRaw("sum(signed_minor) FILTER (WHERE effective_on >= ? AND kind IN ('savings_deposit', 'share_purchase')) AS contributions", [$start]);
        $principalPaid = DB::table('loan_repayment_receipts as receipt')
            ->join('loan_repayment_allocations as allocation', 'allocation.loan_repayment_receipt_id', '=', 'receipt.id')
            ->where('receipt.received_on', '<=', $end)->groupBy('receipt.loan_application_id')
            ->selectRaw('receipt.loan_application_id, sum(allocation.principal_minor) AS paid_minor');
        $loans = DB::table('loan_disbursements as transfer')
            ->join('loan_applications as loan', 'loan.id', '=', 'transfer.loan_application_id')
            ->leftJoinSub($principalPaid, 'paid', 'paid.loan_application_id', '=', 'loan.id')
            ->where('transfer.transferred_on', '<=', $end)->groupBy('loan.member_id')->select('loan.member_id')
            ->selectRaw('sum(loan.principal_minor + loan.insurance_minor + loan.processing_fee_minor + loan.other_charges_minor - coalesce(paid.paid_minor, 0)) AS loan_minor');

        $legacy = DB::table('legacy_onboarding_rows as staged')->where('staged.source_table', 'loans')
            ->join('members as owner', function ($join): void {
                $join->on('owner.legacy_source_sha256', '=', 'staged.source_sha256')
                    ->whereRaw("owner.legacy_member_id::text = staged.data->>'member_id'");
            })
            ->leftJoin('verified_legacy_loan_balances as verified', function ($join) use ($end): void {
                $join->on('verified.source_sha256', '=', 'staged.source_sha256')
                    ->on('verified.source_id', '=', 'staged.source_id')->on('verified.member_id', '=', 'owner.id')
                    ->where('verified.as_of', '=', $end);
            })
            ->whereRaw(<<<'SQL'
NOT coalesce(staged.data->>'disbursement_date' ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
    AND pg_input_is_valid(staged.data->>'disbursement_date', 'date')
    AND staged.data->>'disbursement_date' > ?, false)
SQL, [$end])
            ->groupBy('owner.id')->selectRaw('owner.id AS member_id, count(*) AS expected, count(verified.id) AS verified, sum(verified.principal_minor) AS principal_minor');

        return DB::table('members as member')->where('member.status', 'active')
            ->leftJoinSub($opening, 'opening', 'opening.member_id', '=', 'member.id')
            ->leftJoinSub($funds, 'funds', 'funds.member_id', '=', 'member.id')
            ->leftJoinSub($loans, 'loans', 'loans.member_id', '=', 'member.id')
            ->leftJoinSub($legacy, 'legacy', 'legacy.member_id', '=', 'member.id')
            ->select(['member.registration_no', 'member.first_name', 'member.last_name', 'member.email', 'opening.as_of'])
            ->selectRaw('opening.savings_minor + coalesce(funds.savings_movement, 0) AS savings_minor')
            ->selectRaw('coalesce(funds.contributions, 0) AS contribution_minor, coalesce(loans.loan_minor, 0) + coalesce(legacy.principal_minor, 0) AS loan_minor')
            ->selectRaw(<<<'SQL'
member.legacy_member_id IS NOT NULL AND (
    NOT EXISTS (
        SELECT 1 FROM legacy_onboarding_rows AS staged
        WHERE staged.source_sha256 = member.legacy_source_sha256 AND staged.source_table = 'loans'
    ) OR coalesce(legacy.expected, 0) > coalesce(legacy.verified, 0)
) AS legacy_loan_review
SQL)
            ->orderBy('member.registration_no')->orderBy('member.id')->get()
            ->each(function ($row) use ($start): void {
                $row->hold = match (true) {
                    $row->legacy_loan_review => 'Legacy loans need reconciliation',
                    $row->as_of === null || $row->as_of >= $start => 'Verified opening balances must predate the month',
                    ! $this->validContact($row->registration_no, $row->email) => 'Check the membership number or email',
                    min($row->loan_minor, $row->contribution_minor, $row->savings_minor) < 0
                        || max($row->loan_minor, $row->contribution_minor, $row->savings_minor) > 99999999999999 => 'Review the calculated amounts',
                    default => null,
                };
            });
    }

    private function validContact(string $registrationNo, string $email): bool
    {
        return preg_match('/\A[A-Z0-9][A-Z0-9-]{0,29}\z/', $registrationNo) === 1
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && ! str_contains('=+-@', $email[0]);
    }

    private function decimal(int|string $minor): string
    {
        $minor = (int) $minor;

        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
