<?php

namespace App;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordMemberFundTransaction
{
    public function __construct(private LedgerPeriods $periods) {}

    public function record(int $actor, int $member, int $opening, array $data, string $kind, int $amount, ?int $withdrawal = null, ?int $batch = null): void
    {
        $reference = Str::upper($data['reference']);
        $existing = DB::table('member_fund_transactions')->where('member_id', $member)
            ->where('kind', $kind)->where('reference', $reference)->first();
        if ($existing !== null) {
            if ($batch === null && $existing->loan_batch_id === null && $existing->signed_minor === $amount && $existing->effective_on === $data['effective_on']
                && $existing->note === ($data['note'] ?? null) && $existing->member_savings_withdrawal_id === $withdrawal) {
                return;
            }

            throw ValidationException::withMessages([
                'funds' => 'This reference is already recorded for the member and transaction type.',
            ]);
        }

        $this->periods->requireOpenDates([$data['effective_on']], 'effective_on');

        DB::table('member_fund_transactions')->insert([
            'member_id' => $member, 'member_verified_balance_id' => $opening, 'member_savings_withdrawal_id' => $withdrawal,
            'kind' => $kind, 'signed_minor' => $amount, 'effective_on' => $data['effective_on'], 'reference' => $reference,
            'note' => $data['note'] ?? null, 'loan_batch_id' => $batch, 'recorded_by' => $actor, 'recorded_at' => now(),
        ]);
    }
}
