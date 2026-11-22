<?php

namespace App;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class StatementMailer
{
    public function selectedAccountId(): ?int
    {
        return DB::table('mailing_account_selections')->orderByDesc('id')->value('mailing_account_id');
    }

    public function configured(?int $account): bool
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');

        return ! config('app.demo_mode') && ($account !== null || in_array($transport, config('statements.delivery_transports'), true));
    }

    public function automaticEnabled(): bool
    {
        return DB::table('statement_auto_send_changes')->orderByDesc('id')->value('enabled')
            ?? config('statements.monthly_email_enabled');
    }

    public function shouldSendAutomatically(): bool
    {
        return $this->automaticEnabled() && $this->configured($this->selectedAccountId());
    }

    public function forDelivery(?int $account): Mailer
    {
        if ($account === null) {
            return Mail::mailer();
        }

        $settings = DB::table('mailing_accounts')->find($account);
        $mailer = Mail::build([
            'transport' => 'smtp',
            'scheme' => $settings->security === 'tls' ? 'smtps' : 'smtp',
            'host' => $settings->host,
            'port' => $settings->port,
            'username' => $settings->username,
            'password' => Crypt::decryptString($settings->encrypted_password),
            'require_tls' => true,
            'timeout' => 15,
        ]);
        $mailer->alwaysFrom($settings->from_address, $settings->from_name);

        return $mailer;
    }
}
