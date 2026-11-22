<?php

namespace App;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanEligibility
{
    public function __construct(private MemberFunds $funds) {}

    public function details(int $member): array
    {
        $minimums = DB::table('loan_eligibility_revisions')->orderByDesc('id')->first();
        $funds = $this->funds->balances($member);
        $balance = $funds['opening'];
        $exiting = DB::table('member_exits')->where('member_id', $member)
            ->whereIn('status', ['requested', 'approved'])->exists();
        $reason = match (true) {
            $exiting => 'The member has an open exit request.',
            $minimums === null => 'Set the savings and share minimums before accepting loan applications.',
            $balance === null => 'Record verified savings and share balances for this member before applying.',
            $funds['available'] < $minimums->min_savings_minor => 'Available savings are below the required minimum.',
            $funds['shares'] < $minimums->min_shares_minor => 'Verified shares are below the required minimum.',
            default => null,
        };

        return compact('minimums', 'balance', 'reason', 'funds');
    }

    public function requireEligible(int $member): array
    {
        DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['loan-eligibility']);
        $details = $this->details($member);
        if ($details['reason'] !== null) {
            throw ValidationException::withMessages(['eligibility' => $details['reason']]);
        }

        return $details;
    }
}
