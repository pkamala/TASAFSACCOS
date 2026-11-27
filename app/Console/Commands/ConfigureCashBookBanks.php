<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConfigureCashBookBanks extends Command
{
    protected $signature = 'chart:configure-cash-book-banks {actor : Staff administrator ID for the account audit}';

    protected $description = 'Configure the six bank accounts for the 2026 cashbook';

    public function handle(): int
    {
        $actor = $this->argument('actor');
        if (! ctype_digit($actor) || ! DB::table('users')->where('id', $actor)->where('is_staff_admin', true)->exists()) {
            $this->error('Supply an existing staff administrator ID.');

            return self::FAILURE;
        }

        $banks = [
            '1020' => 'Balance with CRDB Bank',
            '1021' => 'Balance with Maendeleo Bank',
            '1022' => 'Balance with Mwanga Hakika Bank',
            '1023' => 'Balance with NMB Bank',
            '1024' => 'Balance with NBC Bank call acc',
            '1025' => 'Balance with NBC Bank Wezesha acc',
        ];

        try {
            DB::transaction(function () use ($actor, $banks): void {
                DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['chart-accounts']);
                $accounts = DB::table('chart_accounts')->whereIn('code', array_keys($banks))->lockForUpdate()->get();
                $original = $accounts->where('code', '1020')->where('name', 'Cash at Bank')->sole();
                $now = now();
                $reason = 'Bank chart configuration from bank acc.xlsx';

                foreach ($banks as $code => $name) {
                    $replaces = (string) $code === '1020' ? $original->id : null;
                    $active = $accounts->where('code', (string) $code)->whereNull('retired_at')
                        ->where('id', '!=', $original->id);
                    if ($active->isEmpty()) {
                        $id = DB::table('chart_accounts')->insertGetId([
                            'code' => (string) $code, 'name' => $name, 'name_en' => $name,
                            'account_type_id' => $original->account_type_id, 'is_ledger_account' => true,
                            'replaces_account_id' => $replaces, 'created_by' => $actor,
                            'created_at' => $now, 'creation_reason' => $reason,
                        ]);
                    } else {
                        $account = $active->sole();
                        if ($account->name !== $name || ! $account->is_ledger_account
                            || $account->account_type_id !== $original->account_type_id || $account->replaces_account_id !== $replaces) {
                            throw new RuntimeException("Account {$code} conflicts with the cashbook bank configuration.");
                        }
                        $id = $account->id;
                    }

                    DB::table('cash_book_accounts')->upsert([
                        ['chart_account_id' => $id, 'is_bank_account' => true],
                    ], ['chart_account_id'], ['is_bank_account']);
                }

                if ($original->retired_at === null) {
                    DB::table('chart_accounts')->where('id', $original->id)->update([
                        'retired_at' => $now, 'retired_by' => $actor, 'retirement_reason' => $reason,
                    ]);
                }
            }, 3);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Bank accounts 1020–1025 are configured.');

        return self::SUCCESS;
    }
}
