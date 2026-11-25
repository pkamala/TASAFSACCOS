<?php

namespace App;

class SavingsInterest
{
    public static function month(int $principal, int $annualRate): int
    {
        $principal = max(0, $principal);

        return intdiv($principal, 120000) * $annualRate
            + intdiv(($principal % 120000) * $annualRate + 60000, 120000);
    }

    public static function withholding(int $interest, int $rate): int
    {
        return intdiv($interest, 10000) * $rate + intdiv(($interest % 10000) * $rate + 5000, 10000);
    }
}
