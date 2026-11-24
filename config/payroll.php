<?php

return [
    'posting_accounts' => [
        'cash' => env('PAYROLL_CASH_ACCOUNT_ID'),
        'principal' => env('PAYROLL_PRINCIPAL_ACCOUNT_ID'),
        'offset' => env('PAYROLL_INTEREST_ACCOUNT_ID'),
        'savings_deposit' => env('PAYROLL_SAVINGS_ACCOUNT_ID'),
        'share_purchase' => env('PAYROLL_SHARES_ACCOUNT_ID'),
    ],
];
