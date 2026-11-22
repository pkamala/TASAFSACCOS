<?php

namespace App;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LegacyLoanBalanceReport
{
    public function snapshots(): array
    {
        return DB::table('legacy_onboarding_rows')->where('source_table', 'loans')
            ->distinct()->orderBy('source_sha256')->pluck('source_sha256')->all();
    }

    public function read(UploadedFile $report): array
    {
        $file = ImportTable::open($report, 'report');
        $file->setCsvControl(',', '"', '');
        $header = $file->fgetcsv() ?: [];
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
        if ($header !== ['legacy_loan_id', 'principal_balance_tzs']) {
            $this->invalid('Use the headers from the legacy balance report template.');
        }

        $rows = [];
        $line = 1;
        while (! $file->eof()) {
            $values = $file->fgetcsv();
            $line++;
            if ($values === false || $values === [null]) {
                continue;
            }
            if (count($values) !== 2 || count($rows) >= 3000) {
                $this->invalid("Invalid column count or 3,000-row limit exceeded at row $line.");
            }
            [$id, $amount] = array_map('trim', $values);
            if (! preg_match('/\A[1-9]\d{0,9}\z/', $id) || (int) $id > 2147483647 || isset($rows[(int) $id])
                || ! preg_match('/\A-?\d{1,12}(?:\.\d{1,2})?\z/', $amount)) {
                $this->invalid("Supply a unique legacy loan ID and a valid TZS principal balance in row $line.");
            }
            [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '');
            $minor = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
            $rows[(int) $id] = str_starts_with($amount, '-') ? -$minor : $minor;
        }

        if ($rows === []) {
            $this->invalid('The report has no loan records.');
        }

        return $rows;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['report' => $message]);
    }
}
