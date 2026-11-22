<?php

namespace App\Http\Controllers;

use App\StatementMailer;
use App\TablePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MailingAccountController extends Controller
{
    public function index(StatementMailer $mailer): View
    {
        $active = $mailer->selectedAccountId();
        $accounts = DB::table('mailing_accounts')->orderByRaw('lower(label)')->orderBy('id')
            ->simplePaginate(TablePage::size(), ['id', 'label', 'from_address', 'host', 'port', 'security']);
        $history = DB::table('mailing_account_selections as change')
            ->leftJoin('mailing_accounts as account', 'account.id', '=', 'change.mailing_account_id')
            ->join('users as actor', 'actor.id', '=', 'change.changed_by')
            ->orderByDesc('change.id')->simplePaginate(TablePage::size('history_page'), ['account.label', 'actor.name as actor', 'change.changed_at'], 'history_page');

        $incoming = DB::table('incoming_mail_settings')->find(1, ['protocol', 'host', 'port', 'security', 'username', 'changed_at']);
        $automatic = $mailer->automaticEnabled();
        $deliveryConfigured = $mailer->configured($active);
        $lastAutomaticChange = DB::table('statement_auto_send_changes as change')
            ->join('users as actor', 'actor.id', '=', 'change.changed_by')
            ->orderByDesc('change.id')->first(['actor.name as actor', 'change.changed_at']);

        return view('staff.mailing-accounts', compact('active', 'accounts', 'history', 'incoming', 'automatic', 'deliveryConfigured', 'lastAutomaticChange'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:100', 'regex:/\A[^\r\n]+\z/'],
            'host' => MailDeliverySettingsController::HostRules,
            'port' => ['required', 'integer', 'between:1,65535'],
            'security' => ['required', 'in:starttls,tls'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $password = $data['password'];
        unset($data['password']);
        DB::table('mailing_accounts')->insert([
            ...$data, 'encrypted_password' => Crypt::encryptString($password),
            'created_by' => $request->user()->id, 'created_at' => now(),
        ]);

        return redirect()->route('staff.mailing-accounts.index')->with('status', 'Mailing account saved. Select it when it is ready for outgoing mail.');
    }

    public function select(Request $request, StatementMailer $mailer): RedirectResponse
    {
        $data = $request->validate(['account' => ['present', 'nullable', 'integer', 'exists:mailing_accounts,id']]);
        $account = isset($data['account']) ? (int) $data['account'] : null;
        DB::transaction(function () use ($request, $mailer, $account): void {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['statement-mailing-account']);
            if ($mailer->selectedAccountId() !== $account) {
                DB::table('mailing_account_selections')->insert([
                    'mailing_account_id' => $account, 'changed_by' => $request->user()->id, 'changed_at' => now(),
                ]);
            }
        }, 3);

        return redirect()->route('staff.mailing-accounts.index')->with('status', 'Outgoing sender updated.');
    }
}
