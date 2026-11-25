<?php

namespace App\Http\Controllers;

use App\MemberFunds;
use App\RecordLoanRepayment;
use App\RecordMemberFundTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SavingsLoanPaymentController extends Controller
{
    public function __invoke(Request $request, int $member, int $application, MemberFunds $funds, RecordMemberFundTransaction $transactions, RecordLoanRepayment $repayments): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:70'],
            'effective_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'between:0.01,5000000000000'],
        ]);
        $actor = $request->user()->id;
        $amount = (int) round((float) $data['amount'] * 100);
        $reference = Str::upper($data['reference']);

        $batch = DB::transaction(function () use ($data, $actor, $member, $application, $funds, $transactions, $repayments, $amount, $reference): int {
            $profile = DB::table('members')->lockForUpdate()->find($member);
            $loan = DB::table('loan_applications')->where('member_id', $member)->lockForUpdate()->find($application);
            abort_if($profile === null || $loan === null, 404);

            $existing = DB::table('loan_repayment_receipts as receipt')->leftJoin('loan_batches as batch', 'batch.id', '=', 'receipt.loan_batch_id')
                ->where('receipt.loan_application_id', $application)->where('receipt.receipt_reference', $reference)
                ->first(['receipt.*', 'batch.kind']);
            if ($existing !== null) {
                if ($existing->kind === 'savings_payment' && $existing->received_on === $data['effective_on'] && $existing->amount_minor === $amount) {
                    return $existing->loan_batch_id;
                }
                $this->invalid('This loan already has a payment with that reference.');
            }

            $balance = $funds->balances($member);
            if ($profile->status !== 'active' || $loan->status !== 'disbursed' || $balance['available'] === null || $balance['available'] < $amount) {
                $this->invalid('Payment requires an active member, an active loan, and sufficient available savings.');
            }
            if ($data['effective_on'] < max($balance['opening']->as_of, $balance['latest'] ?? '')) {
                $this->invalid('The payment date must be on or after the verified opening balance and latest savings or share transaction.');
            }

            $allocations = $this->allocate($application, $amount);
            $batch = DB::table('loan_batches')->insertGetId([
                'kind' => 'savings_payment', 'status' => 'confirmed', 'prepared_by' => $actor, 'prepared_at' => now(),
                'confirmed_by' => $actor, 'confirmed_at' => now(), 'effective_date' => $data['effective_on'],
                'items' => json_encode([['loan_id' => $application, 'member_id' => $member, 'amount' => $amount, 'allocations' => $allocations]], JSON_THROW_ON_ERROR),
            ]);
            $money = static fn (int $minor): string => number_format($minor / 100, 2, '.', '');
            $repayments->record([
                'receipt_reference' => $reference, 'received_on' => $data['effective_on'], 'amount' => $data['amount'],
                'allocations' => array_map(fn (array $row): array => array_map($money, $row), $allocations),
            ], $actor, $member, $application, $batch);
            $transactions->record($actor, $member, $balance['opening']->id, [
                'reference' => "SAVINGS-PAYMENT-$batch", 'effective_on' => $data['effective_on'], 'note' => "Loan $application · $reference",
            ], 'savings_loan_payment', -$amount, batch: $batch);

            return $batch;
        }, 3);

        return redirect()->route('staff.loan-journal.show', $batch)->with('status', 'Savings payment recorded. Prepare the loan journal to post it.');
    }

    private function allocate(int $application, int $amount): array
    {
        $paid = DB::table('loan_repayment_allocations')->where('loan_application_id', $application)
            ->selectRaw('installment_number, sum(principal_minor) AS principal, sum(interest_minor) AS interest')
            ->groupBy('installment_number')->get()->keyBy('installment_number');
        $installments = DB::table('loan_application_installments')->where('loan_application_id', $application)->orderBy('number')->get();
        $allocations = [];
        $remaining = $amount;
        foreach ($installments as $installment) {
            $prior = $paid->get($installment->number);
            $interest = min($remaining, $installment->interest_minor - (int) ($prior->interest ?? 0));
            $principal = min($remaining - $interest, $installment->principal_minor - (int) ($prior->principal ?? 0));
            if ($principal + $interest > 0) {
                $allocations[$installment->number] = compact('principal', 'interest');
                $remaining -= $principal + $interest;
            }
            if ($remaining === 0) {
                return $allocations;
            }
        }

        $this->invalid('Payment exceeds the remaining scheduled principal and interest.');
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['amount' => $message]);
    }
}
