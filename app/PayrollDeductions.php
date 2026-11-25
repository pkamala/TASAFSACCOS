<?php

namespace App;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class PayrollDeductions
{
    public const Headers = ['deduction_key', 'registration_no', 'planned_tzs', 'actual_tzs'];

    public function components(array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            if (isset($item['funds'])) {
                foreach ($item['funds'] as $kind => $amount) {
                    if ($amount > 0) {
                        $rows["fund:{$item['member_id']}:$kind"] = [
                            'member_id' => $item['member_id'], 'label' => $kind === 'savings_deposit' ? 'Savings' : 'Shares', 'planned' => $amount,
                        ];
                    }
                }

                continue;
            }

            foreach ($item['allocations'] as $number => $allocation) {
                foreach ($allocation as $kind => $amount) {
                    if ($amount > 0) {
                        $rows["loan:{$item['loan_id']}:$number:$kind"] = [
                            'member_id' => $item['member_id'], 'loan_id' => $item['loan_id'],
                            'label' => "Loan {$item['loan_id']} · installment $number · $kind", 'planned' => $amount,
                        ];
                    }
                }
            }
        }

        return $rows;
    }

    public function registrationNumbers(array $components): array
    {
        return DB::table('members')->whereIntegerInRaw('id', array_unique(array_column($components, 'member_id')))
            ->pluck('registration_no', 'id')->all();
    }

    public function read(UploadedFile $upload, array $components, array $registrationNumbers): array
    {
        $file = ImportTable::open($upload, 'deductions');
        $file->setCsvControl(',', '"', '');
        $header = $file->fgetcsv() ?: [];
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
        if ($header !== self::Headers) {
            $this->invalid('Use the deduction template from this approved payroll list.');
        }

        $seen = [];
        $overrides = [];
        $line = 1;
        while (! $file->eof()) {
            $values = $file->fgetcsv();
            $line++;
            if ($values === false || $values === [null]) {
                continue;
            }
            if (count($values) !== count(self::Headers)) {
                $this->invalid("Use the template columns at row $line.");
            }

            [$key, $number, $planned, $actual] = array_map('trim', $values);
            $component = $components[$key] ?? null;
            $registration = $registrationNumbers[$component['member_id'] ?? null] ?? null;
            if ($component === null || isset($seen[$key])
                || $registration === null || ! in_array($number, [$registration, $this->csvRegistration($registration)], true)
                || $this->minor($planned, $line) !== $component['planned']) {
                $this->invalid("Row $line is duplicated or does not match this approved payroll list. Resolve any unplanned deductions with TASAF Accounts.");
            }

            $seen[$key] = true;
            $actual = $this->minor($actual, $line);
            if ($actual !== $component['planned']) {
                $overrides[$key] = $actual;
            }
        }
        if (count($seen) !== count($components) || $seen === []) {
            $this->invalid('Include every deduction from the template and enter 0 for each missed deduction.');
        }

        return $overrides;
    }

    public function differences(array $components, array $overrides): array
    {
        $changes = [];
        foreach ($overrides as $key => $actual) {
            $component = $components[$key];
            $changes[] = [
                ...$component, 'actual' => $actual, 'difference' => $actual - $component['planned'],
                'status' => match (true) {
                    $actual === 0 => 'Missed',
                    $actual < $component['planned'] => 'Partial',
                    default => 'Excess',
                },
            ];
        }

        return $changes;
    }

    public static function confirmedItems(stdClass $batch): array
    {
        $items = json_decode($batch->items, true, flags: JSON_THROW_ON_ERROR);
        $review = json_decode($batch->deduction_review ?? 'null', true, flags: JSON_THROW_ON_ERROR);

        return $review === null ? $items : self::apply($items, $review['overrides']);
    }

    public static function apply(array $items, array $overrides): array
    {
        $confirmed = [];
        foreach ($items as $item) {
            if (isset($item['funds'])) {
                foreach ($item['funds'] as $kind => $amount) {
                    $item['funds'][$kind] = $overrides["fund:{$item['member_id']}:$kind"] ?? $amount;
                }
                $item['amount'] = array_sum($item['funds']);
            } else {
                $total = 0;
                foreach ($item['allocations'] as $number => $allocation) {
                    foreach ($allocation as $kind => $amount) {
                        $item['allocations'][$number][$kind] = $overrides["loan:{$item['loan_id']}:$number:$kind"] ?? $amount;
                    }
                    $total += array_sum($item['allocations'][$number]);
                }
                $item['amount'] = $total;
            }
            if ($item['amount'] > 0) {
                $confirmed[] = $item;
            }
        }

        return $confirmed;
    }

    public function csvRegistration(string $number): string
    {
        return preg_match('/^[\x00-\x20]*[=+@-]|^[\t\r\n]/', $number) ? "'".$number : $number;
    }

    public function decimal(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }

    private function minor(string $amount, int $line): int
    {
        if (! preg_match('/\A\d{1,13}(?:\.\d{1,2})?\z/', $amount)) {
            $this->invalid("Enter a non-negative TZS amount with up to two decimals at row $line.");
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['deductions' => $message]);
    }
}
