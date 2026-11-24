<?php

namespace App;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonthlyPayroll
{
    public function prepare(string $month, int $actor, bool $includeOverdue = false): int
    {
        $start = $month.'-01';
        $end = CarbonImmutable::parse($start, 'Africa/Dar_es_Salaam')->endOfMonth()->toDateString();

        return DB::transaction(function () use ($start, $end, $actor, $includeOverdue): int {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['payroll-schedule']);
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['payroll:'.$start]);
            $lists = DB::table('loan_batches')->where('kind', 'repayment');
            if ((clone $lists)->where('period', $start)->whereIn('status', ['prepared', 'approved', 'confirmed'])->exists()) {
                $this->invalid('This month already has a prepared, approved, or confirmed payroll list.');
            }

            $loans = $this->repayments($start, $end, $includeOverdue);
            $reserved = $lists->whereIn('status', ['prepared', 'approved'])->pluck('items')
                ->flatMap(fn (string $items): array => json_decode($items, true, flags: JSON_THROW_ON_ERROR));
            foreach ($reserved as $entry) {
                $loanId = $entry['loan_id'] ?? null;
                if (isset($loans[$loanId]) && array_intersect_key($loans[$loanId]['allocations'], $entry['allocations']) !== []) {
                    $this->invalid("Loan $loanId has a scheduled installment on another open payroll list.");
                }
            }

            $items = [...array_values($loans), ...$this->contributions($end)];
            if ($items === []) {
                $this->invalid('No outstanding installments or configured contributions were found for this payroll month.');
            }

            return DB::table('loan_batches')->insertGetId([
                'kind' => 'repayment', 'period' => $start, 'items' => json_encode($items, JSON_THROW_ON_ERROR),
                'status' => 'prepared', 'prepared_by' => $actor, 'prepared_at' => now(),
            ]);
        }, 3);
    }

    private function repayments(string $start, string $end, bool $includeOverdue): array
    {
        $paid = DB::table('loan_repayment_allocations')->groupBy('loan_application_id', 'installment_number')
            ->selectRaw('loan_application_id, installment_number, sum(principal_minor) AS principal, sum(interest_minor) AS interest');
        $rows = DB::table('loan_application_installments as installment')
            ->join('loan_applications as loan', 'loan.id', '=', 'installment.loan_application_id')
            ->leftJoin('loan_disbursements as transfer', 'transfer.loan_application_id', '=', 'loan.id')
            ->leftJoin('active_loan_migrations as migration', 'migration.loan_application_id', '=', 'loan.id')
            ->leftJoinSub($paid, 'paid', fn ($join) => $join->on('paid.loan_application_id', '=', 'loan.id')
                ->on('paid.installment_number', '=', 'installment.number'))
            ->where('loan.status', 'disbursed')->whereRaw('coalesce(transfer.transferred_on, migration.effective_on) <= ?', [$end])
            ->where('installment.due_date', '<=', $end)
            ->when(! $includeOverdue, fn ($query) => $query->where('installment.due_date', '>=', $start))
            ->orderBy('loan.id')->orderBy('installment.number')
            ->selectRaw('loan.id, loan.member_id, installment.number, installment.due_date,
                greatest(installment.principal_minor - coalesce(paid.principal, 0), 0) AS principal,
                greatest(installment.interest_minor - coalesce(paid.interest, 0), 0) AS interest')
            ->get();

        $loans = [];
        foreach ($rows as $row) {
            $amount = (int) $row->principal + (int) $row->interest;
            if ($amount === 0) {
                continue;
            }
            $loans[$row->id] ??= ['loan_id' => $row->id, 'member_id' => $row->member_id, 'allocations' => [], 'amount' => 0];
            $loans[$row->id]['allocations'][$row->number] = ['principal' => (int) $row->principal, 'interest' => (int) $row->interest];
            $loans[$row->id]['amount'] += $amount;
            if ($row->due_date < $start) {
                $loans[$row->id]['overdue_installments'][$row->number] = $row->due_date;
            }
        }

        return $loans;
    }

    private function contributions(string $end): array
    {
        $members = DB::table('members')->where('status', 'active')
            ->whereRaw('(payroll_savings_minor > 0 OR payroll_shares_minor > 0)')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('member_exits')
                ->whereColumn('member_exits.member_id', 'members.id')->whereIn('member_exits.status', ['requested', 'approved']))
            ->select(['id', 'registration_no', 'payroll_savings_minor', 'payroll_shares_minor'])
            ->selectRaw('EXISTS (SELECT 1 FROM member_verified_balances AS balance
                WHERE balance.member_id = members.id AND balance.as_of <= ?) AS has_opening', [$end])
            ->orderBy('id')->get();
        $missing = $members->where('has_opening', false);
        if ($missing->isNotEmpty()) {
            $examples = $missing->take(10)->pluck('registration_no')->implode(', ');
            $count = $missing->count();
            $this->invalid("Verified opening balances are required for configured contributors. Missing balances: $count ($examples).");
        }

        return $members->map(fn ($member): array => [
            'member_id' => $member->id,
            'funds' => ['savings_deposit' => $member->payroll_savings_minor, 'share_purchase' => $member->payroll_shares_minor],
            'amount' => $member->payroll_savings_minor + $member->payroll_shares_minor,
        ])->all();
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['batch' => $message]);
    }
}
