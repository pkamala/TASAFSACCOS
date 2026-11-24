<?php

namespace App;

use Illuminate\Support\Collection;

class PayrollPostingAccounts
{
    /**
     * @return array<string, int>
     */
    public function defaults(Collection $accounts): array
    {
        $active = $accounts->whereNull('retired_at');
        $configured = config('payroll.posting_accounts');
        $demoAccounts = config('app.demo_mode') ? [
            'cash' => ['1100', 'Demo bank'],
            'principal' => ['1200', 'Loan receivable'],
            'offset' => ['4200', 'Interest income'],
            'savings_deposit' => ['2200', 'Member savings'],
            'share_purchase' => ['2210', 'Member shares'],
        ] : [];

        $defaults = [];
        foreach ($configured as $field => $id) {
            if ($id !== null && $id !== '') {
                $account = $active->get($id);
            } elseif (isset($demoAccounts[$field])) {
                [$code, $name] = $demoAccounts[$field];
                $matches = $active->whereStrict('code', $code)->whereStrict('name', $name);
                $account = $matches->count() === 1 ? $matches->first() : null;
            } else {
                continue;
            }

            if ($account !== null) {
                $defaults[$field] = $account->id;
            }
        }

        return array_filter($defaults, fn (int $id): bool => count(array_keys($defaults, $id, true)) === 1);
    }
}
