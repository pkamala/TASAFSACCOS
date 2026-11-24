<?php

namespace App;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BudgetLines
{
    public const Headers = ['account_code', 'amount_tzs'];

    public function accounts(): Builder
    {
        return DB::table('chart_accounts as account')
            ->join('account_types as type', 'type.id', '=', 'account.account_type_id')
            ->join('account_categories as category', 'category.id', '=', 'type.account_category_id')
            ->whereIn(DB::raw('lower(trim(category.name))'), ['income', 'revenue', 'expense', 'expenses']);
    }

    public function read(UploadedFile $upload): array
    {
        $file = ImportTable::open($upload, 'budget_file');
        $file->setCsvControl(',', '"', '');
        $header = $file->fgetcsv() ?: [];
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
        if (array_map('trim', $header) !== self::Headers) {
            $this->invalid('Use the account_code and amount_tzs columns from the template.');
        }

        $rows = [];
        $seen = [];
        while (! $file->eof()) {
            $line = $file->key() + 1;
            $fields = $file->fgetcsv();
            if ($fields === false || $fields === [null]) {
                continue;
            }
            if (count($fields) !== 2 || count($rows) >= 1000) {
                $this->invalid("Use two columns and at most 1,000 budget lines. Check row $line.");
            }

            [$code, $amount] = array_map('trim', $fields);
            if ($code === '' || strlen($code) > 100 || preg_match('//u', $code) !== 1 || str_contains($code, "\0")
                || ! preg_match('/\A\d{1,12}(?:\.\d{1,2})?\z/', $amount)) {
                $this->invalid("Enter an account code and a nonnegative TZS amount with up to two decimal places in row $line.");
            }
            if (isset($seen[$code])) {
                $this->invalid("Account code $code is repeated in the import.");
            }

            $seen[$code] = true;
            $rows[] = ['code' => $code, 'amount_minor' => (int) round($amount * 100)];
        }

        if ($rows === []) {
            $this->invalid('Add at least one budget line to the file.');
        }

        return $rows;
    }

    public function plan(int $year, array $rows, bool $lock = false): array
    {
        $accounts = $this->accounts()->whereIn('account.code', array_column($rows, 'code'))
            ->when($lock, fn (Builder $query) => $query->sharedLock())
            ->get(['account.id', 'account.code', 'account.name'])->groupBy('code');
        $current = DB::table('annual_budget_lines')->where('year', $year)
            ->whereIn('chart_account_id', $accounts->flatten(1)->pluck('id'))
            ->pluck('amount_minor', 'chart_account_id');

        return array_map(function (array $row) use ($accounts, $current): array {
            $matches = $accounts->get($row['code'], collect());
            if ($matches->count() !== 1) {
                $this->invalid("Account code {$row['code']} must match exactly one income or expense account.");
            }
            $account = $matches->first();

            return [
                ...$row,
                'account_id' => $account->id,
                'name' => $account->name,
                'previous_minor' => $current->get($account->id),
            ];
        }, $rows);
    }

    public function lockYear(int $year): void
    {
        DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ["annual-budget:$year"]);
    }

    public function save(int $year, array $lines, int $actor): void
    {
        if ($lines === []) {
            return;
        }

        $now = now();
        DB::table('annual_budget_lines')->upsert(array_map(fn (array $line): array => [
            'year' => $year,
            'chart_account_id' => $line['account_id'],
            'amount_minor' => $line['amount_minor'],
            'updated_by' => $actor,
            'created_at' => $now,
            'updated_at' => $now,
        ], $lines), ['year', 'chart_account_id'], ['amount_minor', 'updated_by', 'updated_at']);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['budget_file' => $message]);
    }
}
