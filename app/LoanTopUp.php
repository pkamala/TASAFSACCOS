<?php

namespace App;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class LoanTopUp
{
    public function __construct(private RecordLoanRepayment $repayments) {}

    public function source(int $loan, int $member, int $category): stdClass
    {
        $source = DB::table('loan_applications')->where('id', $loan)->where('member_id', $member)
            ->where('loan_category_id', $category)->where('status', 'disbursed')->lockForUpdate()->first();
        if ($source === null) {
            throw ValidationException::withMessages(['top_up_loan_id' => 'Choose an active loan in this category belonging to this member.']);
        }

        return $source;
    }

    /** @return array{principal: int, interest: int, total: int, allocations: array<int, array{principal: int, interest: int}>} */
    public function quote(int $loan, string $date): array
    {
        $paid = DB::table('loan_repayment_allocations')->where('loan_application_id', $loan)
            ->selectRaw('installment_number, sum(principal_minor) as principal, sum(interest_minor) as interest')
            ->groupBy('installment_number');
        $installments = DB::table('loan_application_installments as installment')->where('installment.loan_application_id', $loan)
            ->leftJoinSub($paid, 'paid', 'paid.installment_number', '=', 'installment.number')
            ->orderBy('installment.number')
            ->get(['installment.number', 'installment.due_date', 'installment.principal_minor', 'installment.interest_minor', 'paid.principal as paid_principal', 'paid.interest as paid_interest']);
        $allocations = [];
        $principal = $interest = 0;
        foreach ($installments as $installment) {
            $principalDue = $installment->principal_minor - (int) $installment->paid_principal;
            $interestDue = $installment->due_date <= $date ? $installment->interest_minor - (int) $installment->paid_interest : 0;
            if ($principalDue + $interestDue > 0) {
                $allocations[$installment->number] = ['principal' => $principalDue, 'interest' => $interestDue];
                $principal += $principalDue;
                $interest += $interestDue;
            }
        }

        return ['principal' => $principal, 'interest' => $interest, 'total' => $principal + $interest, 'allocations' => $allocations];
    }

    public function settle(stdClass $replacement, int $actor, int $batch, string $date, ?string $netAmount): int
    {
        $source = $this->source($replacement->top_up_loan_id, $replacement->member_id, $replacement->loan_category_id);
        if (DB::table('loan_batches')->where('kind', 'repayment')->whereIn('status', ['prepared', 'approved'])
            ->whereJsonContains('items', [['loan_id' => $source->id]])->exists()) {
            throw ValidationException::withMessages(['transfer_reference' => 'Resolve the original loan’s open payroll list before paying out the top-up.']);
        }
        if (DB::table('loan_repayment_receipts')->where('loan_application_id', $source->id)->where('received_on', '>', $date)->exists()) {
            throw ValidationException::withMessages(['transferred_on' => 'The top-up date cannot precede a recorded repayment on the original loan.']);
        }
        $quote = $this->quote($source->id, $date);
        $net = $replacement->principal_minor - $quote['total'];
        if ($quote['total'] <= 0 || $net <= 0 || $netAmount === null || (int) round((float) $netAmount * 100) !== $net) {
            throw ValidationException::withMessages([
                'net_transfer' => 'Confirm the net bank transfer for this top-up. The amount due after clearing the original loan on '.$date.' is TZS '.number_format(max(0, $net) / 100, 2).'.',
            ]);
        }

        $money = static fn (int $minor): string => number_format($minor / 100, 2, '.', '');
        $this->repayments->record([
            'receipt_reference' => 'TOP-UP-'.$replacement->id,
            'received_on' => $date,
            'amount' => $money($quote['total']),
            'allocations' => array_map(fn (array $entry): array => array_map($money, $entry), $quote['allocations']),
        ], $actor, $replacement->member_id, $source->id, $batch);

        DB::table('loan_applications')->where('id', $source->id)->update(['status' => 'repaid']);

        return $net;
    }
}
