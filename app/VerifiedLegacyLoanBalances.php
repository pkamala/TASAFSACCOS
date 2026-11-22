<?php

namespace App;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VerifiedLegacyLoanBalances
{
    public function plan(string $snapshot, string $asOf, array $balances): array
    {
        $loans = DB::table('legacy_onboarding_rows as loan')->where('loan.source_sha256', $snapshot)
            ->where('loan.source_table', 'loans')->whereIntegerInRaw('loan.source_id', array_keys($balances))
            ->leftJoin('members as member', function ($join): void {
                $join->on('member.legacy_source_sha256', '=', 'loan.source_sha256')
                    ->whereRaw("member.legacy_member_id::text = loan.data->>'member_id'");
            })
            ->select(['loan.source_id', 'loan.issues', 'member.id as member_id', 'member.registration_no'])
            ->selectRaw("loan.data->>'loan_code' AS reference")
            ->selectRaw(<<<'SQL'
CASE WHEN loan.data->>'disbursement_date' ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
AND pg_input_is_valid(loan.data->>'disbursement_date', 'date') THEN loan.data->>'disbursement_date' END AS disbursed_on
SQL)
            ->get()->keyBy('source_id');
        $verified = DB::table('verified_legacy_loan_balances')->where('source_sha256', $snapshot)->where('as_of', $asOf)
            ->whereIntegerInRaw('source_id', array_keys($balances))->get(['id', 'source_id', 'principal_minor'])->keyBy('source_id');

        $rows = [];
        foreach ($balances as $id => $amount) {
            $loan = $loans->get($id);
            $existing = $verified->get($id);
            $status = match (true) {
                $loan === null => 'Loan not found in the selected export',
                $loan->member_id === null || $loan->issues !== '[]' => 'Review the staged loan and member mapping',
                $amount < 0 => 'Review the negative principal balance',
                $loan->disbursed_on !== null && $loan->disbursed_on > $asOf && $amount !== 0 => 'Disbursement is after the report date',
                $existing !== null && $existing->principal_minor !== $amount => 'A different balance is already verified for this month',
                $existing !== null => 'Already recorded',
                default => 'Ready',
            };
            $rows[] = [
                'source_id' => $id, 'member_id' => $loan?->member_id, 'registration_no' => $loan?->registration_no,
                'reference' => $loan?->reference, 'principal_minor' => $amount, 'existing_id' => $existing?->id, 'status' => $status,
            ];
        }

        return $rows;
    }

    public function apply(array $preview, int $actor): int
    {
        return DB::transaction(function () use ($preview, $actor): int {
            $snapshot = $preview['snapshot'];
            $asOf = $preview['asOf'];
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ["verified-legacy-loans:$snapshot:$asOf"]);
            $plan = $this->plan($snapshot, $asOf, $preview['balances']);
            if ($plan !== $preview['plan'] || collect($plan)->contains(fn (array $row): bool => ! in_array($row['status'], ['Ready', 'Already recorded'], true))) {
                throw ValidationException::withMessages(['report' => 'Resolve the exceptions or run a fresh dry run before applying.']);
            }

            $now = now();
            $rows = collect($plan)->where('status', 'Ready')->map(fn (array $row): array => [
                'source_sha256' => $snapshot, 'as_of' => $asOf,
                'source_id' => $row['source_id'], 'member_id' => $row['member_id'], 'principal_minor' => $row['principal_minor'],
                'evidence_reference' => $preview['evidence'], 'report_sha256' => $preview['sha256'],
                'verified_by' => $actor, 'verified_at' => $now,
            ]);
            foreach ($rows->chunk(250) as $batch) {
                DB::table('verified_legacy_loan_balances')->insert($batch->values()->all());
            }

            return $rows->count();
        }, 3);
    }
}
