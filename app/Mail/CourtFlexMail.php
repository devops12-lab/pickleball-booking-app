<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;

class CourtFlexMail extends Mailable
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly array $lines,
        public readonly ?string $actionUrl = null,
        public readonly ?string $actionLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.transactional');
    }
}
