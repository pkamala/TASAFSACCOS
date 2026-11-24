<?php

namespace App;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanEligibility
{
    public const MinimumShares = 50_000_000;

    public function __construct(private MemberFunds $funds) {}

    public function details(int $member, ?int $application = null): array
    {
        $minimums = DB::table('loan_eligibility_revisions')->orderByDesc('id')->first();
        $funds = $this->funds->balances($member);
        $balance = $funds['opening'];
        $exiting = DB::table('member_exits')->where('member_id', $member)
            ->whereIn('status', ['requested', 'approved'])->exists();
        $profile = DB::table('members')->find($member, ['status', 'joining_date']);
        $joiningDate = $profile?->joining_date;
        $today = CarbonImmutable::today('Africa/Dar_es_Salaam');
        $earliest = $joiningDate === null ? null : CarbonImmutable::parse($joiningDate, 'Africa/Dar_es_Salaam')->addMonthsNoOverflow(3);
        $limit = $balance === null ? null : 3 * max(0, $funds['available']);
        $committed = $limit === null ? 0 : (int) DB::table('loan_applications as loan')
            ->where('loan.member_id', $member)->whereIn('loan.status', ['approved', 'disbursed'])
            ->when($application !== null, fn ($loans) => $loans->where('loan.id', '<>', $application))
            ->selectRaw('COALESCE(SUM(GREATEST(0, loan.principal_minor + loan.insurance_minor + loan.processing_fee_minor + loan.other_charges_minor
                - COALESCE((SELECT SUM(allocation.principal_minor) FROM loan_repayment_allocations AS allocation
                    WHERE allocation.loan_application_id = loan.id), 0))), 0) AS committed')
            ->first()->committed;
        $legacy = DB::table('verified_legacy_loan_balances as balance')->where('balance.member_id', $member)
            ->where('balance.as_of', '<=', $today->toDateString())
            ->whereNotExists(fn ($loans) => $loans->selectRaw('1')->from('active_loan_migrations as migration')
                ->join('loan_applications as migrated', 'migrated.id', '=', 'migration.loan_application_id')
                ->whereColumn('migration.source_id', 'balance.source_id')->whereColumn('migrated.member_id', 'balance.member_id'))
            ->orderBy('balance.source_id')->orderByDesc('balance.as_of')->orderByDesc('balance.id')
            ->distinct('balance.source_id')->select('balance.source_id', 'balance.principal_minor');
        $legacyCommitted = $limit === null ? 0 : (int) DB::query()->fromSub($legacy, 'latest_balance')->sum('principal_minor');
        $capacity = $limit === null ? null : max(0, $limit - $committed - $legacyCommitted);

        $reason = match (true) {
            $exiting => 'The member has an open exit request.',
            $profile?->status !== 'active' => 'A loan requires an active member.',
            $earliest === null => 'Record the joining date before applying for a loan.',
            $today->lt($earliest) => 'The member completes three months of membership on '.$earliest->toDateString().'.',
            $minimums === null => 'Set the savings and share minimums before accepting loan applications.',
            $balance === null => 'No opening savings and shares have been imported for this member.',
            $funds['available'] < $minimums->min_savings_minor => 'Available savings are below the required minimum.',
            $funds['shares'] < max(self::MinimumShares, $minimums->min_shares_minor) => 'Share balance is below the required minimum.',
            default => null,
        };

        return compact('minimums', 'balance', 'reason', 'funds', 'capacity');
    }

    public function requireEligible(int $member, int $financed, ?int $application = null): array
    {
        DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['loan-eligibility']);
        $details = $this->details($member, $application);
        if ($details['reason'] !== null) {
            throw ValidationException::withMessages(['eligibility' => $details['reason']]);
        }

        if ($financed > $details['capacity']) {
            throw ValidationException::withMessages(['principal' => 'The financed loan amount plus existing loan commitments must be within three times available savings.']);
        }

        return $details;
    }
}
