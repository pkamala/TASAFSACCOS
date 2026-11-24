<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ChartAccountManagementController extends Controller
{
    public function create(): View
    {
        return view('staff.chart-account-form', ['original' => null, 'types' => $this->types()]);
    }

    public function show(int $account): View
    {
        $record = DB::table('chart_accounts as account')
            ->join('account_types as type', 'type.id', '=', 'account.account_type_id')
            ->join('account_categories as category', 'category.id', '=', 'type.account_category_id')
            ->leftJoin('cash_book_accounts as cash', 'cash.chart_account_id', '=', 'account.id')
            ->leftJoin('users as creator', 'creator.id', '=', 'account.created_by')
            ->leftJoin('users as retiree', 'retiree.id', '=', 'account.retired_by')
            ->where('account.id', $account)
            ->first(['account.*', 'type.name as type', 'category.name as category', 'cash.chart_account_id as cash_book_account_id', 'cash.is_bank_account', 'creator.name as creator', 'retiree.name as retiree']);
        abort_if($record === null, 404);
        $replacement = DB::table('chart_accounts')->where('replaces_account_id', $account)->first(['id', 'code', 'name', 'retired_at', 'is_ledger_account']);

        return view('staff.chart-account', compact('record', 'replacement'));
    }

    public function updateCashBook(Request $request, int $account): RedirectResponse
    {
        $data = $request->validate([
            'is_cash_book_account' => ['required', 'boolean'],
            'is_bank_account' => ['sometimes', 'required', 'boolean'],
        ]);
        DB::transaction(function () use ($account, $data): void {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['chart-accounts']);
            abort_unless(DB::table('chart_accounts')->where('id', $account)->where('is_ledger_account', true)->exists(), 404);

            if ($data['is_cash_book_account']) {
                DB::table('cash_book_accounts')->insertOrIgnore(['chart_account_id' => $account]);
                if (array_key_exists('is_bank_account', $data)) {
                    DB::table('cash_book_accounts')->where('chart_account_id', $account)->update(['is_bank_account' => (bool) $data['is_bank_account']]);
                }
            } else {
                DB::table('cash_book_accounts')->where('chart_account_id', $account)->delete();
            }
        }, 3);

        return redirect()->route('staff.chart-accounts.show', $account)->with('status', 'Cash book classification updated.');
    }

    public function replacement(int $account): View
    {
        $original = DB::table('chart_accounts')->find($account);
        abort_if($original === null, 404);
        abort_if($original->retired_at !== null, 409);

        return view('staff.chart-account-form', ['types' => $this->types(), 'original' => $original]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function replace(Request $request, int $account): RedirectResponse
    {
        return $this->save($request, $account);
    }

    public function retire(Request $request, int $account): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        DB::transaction(function () use ($request, $account, $reason): void {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['chart-accounts']);
            $original = DB::table('chart_accounts')->lockForUpdate()->find($account);
            abort_if($original === null, 404);
            if ($original->retired_at !== null) {
                $this->invalid('This account is already retired.');
            }
            $this->retireAccount($account, $request->user()->id, $reason);
        }, 3);

        return redirect()->route('staff.chart-accounts.show', $account)->with('status', 'Account retired.');
    }

    private function save(Request $request, ?int $replaces): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', 'regex:/\A[\x21-\x7e]+\z/'],
            'name' => ['required', 'string', 'max:255'],
            'name_sw' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'account_type_id' => ['required', 'integer', 'min:1', 'exists:account_types,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $actor = $request->user()->id;
        $code = Str::upper($data['code']);
        $id = DB::transaction(function () use ($data, $actor, $code, $replaces): int {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['chart-accounts']);
            $old = $replaces === null ? null : DB::table('chart_accounts')->lockForUpdate()->find($replaces);
            abort_if($replaces !== null && $old === null, 404);
            if ($old?->retired_at !== null) {
                $this->invalid('This account is already retired.');
            }
            if (($old === null || Str::upper($old->code) !== $code)
                && DB::table('chart_accounts')->whereNull('retired_at')->whereRaw('lower(code) = ?', [Str::lower($code)])->exists()) {
                throw ValidationException::withMessages(['code' => 'An active account already uses this code.']);
            }

            $id = DB::table('chart_accounts')->insertGetId([
                'code' => $code, 'name' => $data['name'], 'name_sw' => $data['name_sw'] ?? null, 'name_en' => $data['name_en'] ?? null,
                'account_type_id' => $data['account_type_id'], 'is_ledger_account' => $old->is_ledger_account ?? true,
                'replaces_account_id' => $replaces, 'created_by' => $actor, 'created_at' => now(), 'creation_reason' => $data['reason'],
            ]);
            if ($old) {
                $cashAccount = DB::table('cash_book_accounts')->where('chart_account_id', $old->id)->first();
                if ($cashAccount !== null) {
                    DB::table('cash_book_accounts')->insert(['chart_account_id' => $id, 'is_bank_account' => $cashAccount->is_bank_account]);
                }
                $this->retireAccount($old->id, $actor, $data['reason']);
            }

            return $id;
        }, 3);

        return redirect()->route('staff.chart-accounts.show', $id)
            ->with('status', $replaces === null ? 'Account created.' : 'Replacement created and original account retired.');
    }

    private function types(): Collection
    {
        return DB::table('account_types as type')
            ->join('account_categories as category', 'category.id', '=', 'type.account_category_id')
            ->orderBy('category.name')->orderBy('type.name')->get(['type.id', 'type.name', 'category.name as category']);
    }

    private function retireAccount(int $id, int $actor, string $reason): void
    {
        DB::table('chart_accounts')->where('id', $id)
            ->update(['retired_at' => now(), 'retired_by' => $actor, 'retirement_reason' => $reason]);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['account' => $message]);
    }
}
