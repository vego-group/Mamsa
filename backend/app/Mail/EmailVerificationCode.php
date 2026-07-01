<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * FR-005 — partner email verification code. Sent synchronously (no ShouldQueue)
 * so it works on hosts without a queue worker.
 */
class EmailVerificationCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public int $expMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'رمز تأكيد البريد الإلكتروني - مَمسَى',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-code',
        );
    }
}
