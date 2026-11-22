<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MemberLoginLinkMail extends Mailable
{
    public function __construct(public string $url, public bool $setup, public int $expiresIn) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->setup ? 'Set up your TASAF SACCOS login' : 'Reset your TASAF SACCOS password');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.member-login-link');
    }
}
