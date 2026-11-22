<?php

namespace App\Http\Controllers;

use App\StatementMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MailDeliverySettingsController extends Controller
{
    public const HostRules = ['required', 'string', 'max:253', 'regex:/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/i'];

    public function incoming(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'protocol' => ['required', 'in:imap,pop3'],
            'host' => self::HostRules,
            'port' => ['required', 'integer', 'between:1,65535'],
            'security' => ['required', 'in:starttls,tls'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
        ]);
        $password = $data['password'] ?? null;
        unset($data['password']);
        $data['port'] = (int) $data['port'];

        DB::transaction(function () use ($request, $data, $password): void {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['incoming-mail-settings']);
            $current = DB::table('incoming_mail_settings')->find(1);
            if ($password === null && ($current === null || array_intersect_key((array) $current, $data) !== $data)) {
                throw ValidationException::withMessages(['password' => 'Enter the mailbox password when setting up or changing the connection.']);
            }
            if ($password === null) {
                return;
            }

            DB::table('incoming_mail_settings')->updateOrInsert(['id' => 1], [
                ...$data, 'encrypted_password' => Crypt::encryptString($password),
                'changed_by' => $request->user()->id, 'changed_at' => now(),
            ]);
        }, 3);

        return redirect()->route('staff.mailing-accounts.index')->withFragment('incoming-mail')
            ->with('status', 'Incoming mailbox settings saved.');
    }

    public function automatic(Request $request, StatementMailer $mailer): RedirectResponse
    {
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        DB::transaction(function () use ($request, $enabled, $mailer): void {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['statement-mailing-account']);
            if ($enabled && ! $mailer->configured($mailer->selectedAccountId())) {
                throw ValidationException::withMessages(['enabled' => 'Select an outgoing SMTP account before enabling automatic delivery.']);
            }

            $previous = DB::table('statement_auto_send_changes')->orderByDesc('id')->value('enabled');
            if ($previous !== $enabled) {
                DB::table('statement_auto_send_changes')->insert([
                    'enabled' => $enabled, 'changed_by' => $request->user()->id, 'changed_at' => now(),
                ]);
            }
        }, 3);

        return redirect()->route('staff.mailing-accounts.index')->withFragment('automatic-delivery')
            ->with('status', $enabled ? 'Automatic statement delivery enabled.' : 'Automatic statement delivery disabled.');
    }
}
