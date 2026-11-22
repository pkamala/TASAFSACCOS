<?php

namespace App;

use Carbon\CarbonImmutable;

class LoanSchedule
{
    public function calculate(int $principal, int $annualBasisPoints, int $months, string $method, ?string $firstDueDate): array
    {
        $monthlyRate = $annualBasisPoints / 120000;
        $interestForTerm = (int) round($principal * $annualBasisPoints * $months / 120000);
        $installment = $annualBasisPoints === 0
            ? (int) round($principal / $months)
            : (int) round($principal * $monthlyRate / (1 - (1 + $monthlyRate) ** -$months));
        $firstDue = $firstDueDate ? CarbonImmutable::parse($firstDueDate) : null;
        $balance = $principal;
        $totalInterest = 0;
        $rows = [];

        for ($month = 1; $month <= $months; $month++) {
            $interest = $method === 'flat'
                ? (int) round($interestForTerm * $month / $months) - $totalInterest
                : (int) round($balance * $monthlyRate);
            $principalPaid = match (true) {
                $month === $months => $balance,
                $method === 'flat', $annualBasisPoints === 0 => (int) round($principal * $month / $months) - ($principal - $balance),
                default => min($balance, max(0, $installment - $interest)),
            };

            $rows[] = [
                'month' => $month,
                'date' => $firstDue?->addMonthsNoOverflow($month - 1)->toDateString(),
                'opening' => $balance,
                'principal' => $principalPaid,
                'interest' => $interest,
                'payment' => $principalPaid + $interest,
                'closing' => $balance - $principalPaid,
            ];
            $balance -= $principalPaid;
            $totalInterest += $interest;
        }

        return [
            'rows' => $rows,
            'interest' => $totalInterest,
            'total' => $principal + $totalInterest,
        ];
    }
}
