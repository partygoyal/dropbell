<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CustomerPasswordReset extends Mailable
{
    public function __construct(public string $resetUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your Dropbell password');
    }

    public function content(): Content
    {
        return new Content(view: 'customer.password-reset');
    }
}
