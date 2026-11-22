<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MonthlyMemberStatementMail extends Mailable
{
    public function __construct(
        public string $month,
        public string $asOfDate,
        public int $loanBalance,
        public int $contribution,
        public int $savingsBalance,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "TASAF SACCOS monthly statement · {$this->month}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.monthly-member-statement');
    }
}
