<?php

namespace App;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordLoanRepayment
{
    public function __construct(private LedgerPeriods $periods) {}

    public function record(array $input, int $actor, int $member, int $application, int $batch): void
    {
        $portion = ['nullable', 'numeric', 'decimal:0,2', 'between:0,5000000000000'];
        $data = Validator::make($input, [
            'receipt_reference' => ['required', 'string', 'max:100'],
            'received_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'between:0.01,5000000000000'],
            'allocations' => ['required', 'array', 'max:120'],
            'allocations.*' => ['array:principal,interest'],
            'allocations.*.principal' => $portion,
            'allocations.*.interest' => $portion,
        ])->validate();
        $minor = static fn ($amount): int => (int) round((float) $amount * 100);
        $receiptAmount = $minor($data['amount']);
        $allocations = [];
        $allocated = 0;
        foreach ($data['allocations'] as $number => $row) {
            if (! is_int($number) || $number < 1 || $number > 120) {
                $this->invalid('Supply valid installment numbers.');
            }
            $principal = $minor($row['principal'] ?? 0);
            $interest = $minor($row['interest'] ?? 0);
            if ($principal + $interest > 0) {
                $allocations[$number] = ['principal_minor' => $principal, 'interest_minor' => $interest];
                $allocated += $principal + $interest;
            }
        }
        if ($allocated !== $receiptAmount) {
            $this->invalid('The allocations must add up to the amount applied to this loan.');
        }

        $reference = Str::upper($data['receipt_reference']);

        DB::transaction(function () use ($data, $allocations, $receiptAmount, $reference, $actor, $member, $application, $batch): void {
            $record = DB::table('loan_applications')->where('member_id', $member)->lockForUpdate()->find($application);
            abort_if($record === null, 404);

            $existing = DB::table('loan_repayment_receipts')->where('loan_application_id', $application)
                ->where('receipt_reference', $reference)->first();
            if ($existing !== null) {
                $saved = DB::table('loan_repayment_allocations')->where('loan_repayment_receipt_id', $existing->id)
                    ->get()->mapWithKeys(fn ($row): array => [
                        $row->installment_number => ['principal_minor' => $row->principal_minor, 'interest_minor' => $row->interest_minor],
                    ])->all();
                if ($saved == $allocations && $existing->received_on === $data['received_on'] && $existing->amount_minor === $receiptAmount) {
                    return;
                }
                $this->invalid('This receipt reference already exists for the loan with different details.');
            }

            $repaymentStart = DB::table('loan_disbursements')->where('loan_application_id', $application)->value('transferred_on')
                ?? DB::table('active_loan_migrations')->where('loan_application_id', $application)->value('effective_on');
            if ($record->status !== 'disbursed' || $repaymentStart === null || $data['received_on'] < $repaymentStart) {
                $this->invalid('Record repayments on or after the transfer or migration date for an active loan.');
            }

            $installments = DB::table('loan_application_installments')->where('loan_application_id', $application)->get()->keyBy('number');
            $paid = DB::table('loan_repayment_allocations')->where('loan_application_id', $application)
                ->selectRaw('installment_number, sum(principal_minor) AS principal_minor, sum(interest_minor) AS interest_minor')
                ->groupBy('installment_number')->get()->keyBy('installment_number');

            foreach ($allocations as $number => $allocation) {
                $installment = $installments->get($number);
                $prior = $paid->get($number);
                if ($installment === null
                    || $allocation['principal_minor'] > $installment->principal_minor - (int) ($prior->principal_minor ?? 0)
                    || $allocation['interest_minor'] > $installment->interest_minor - (int) ($prior->interest_minor ?? 0)) {
                    $this->invalid("The allocation for installment $number exceeds its remaining principal or interest, or the installment does not exist.");
                }
            }

            $this->periods->requireOpenDates([$data['received_on']], 'received_on');

            $receipt = DB::table('loan_repayment_receipts')->insertGetId([
                'loan_application_id' => $application,
                'receipt_reference' => $reference,
                'received_on' => $data['received_on'],
                'amount_minor' => $receiptAmount,
                'loan_batch_id' => $batch,
                'recorded_by' => $actor,
                'recorded_at' => now(),
            ]);
            $rows = [];
            foreach ($allocations as $number => $allocation) {
                $rows[] = [
                    ...$allocation,
                    'loan_application_id' => $application,
                    'loan_repayment_receipt_id' => $receipt,
                    'installment_number' => $number,
                ];
            }
            DB::table('loan_repayment_allocations')->insert($rows);

            if ($installments->sum('payment_minor') === $paid->sum('principal_minor') + $paid->sum('interest_minor') + $receiptAmount) {
                DB::table('loan_applications')->where('id', $application)->update(['status' => 'repaid']);
            }
        }, 3);

    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['allocations' => $message]);
    }
}
