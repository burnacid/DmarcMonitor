<?php

namespace App\Mail;

use App\Models\AlertEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlertTriggered extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly AlertEvent $event) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(':type alert on :domain', [
                'type' => str_replace('_', ' ', $this->event->alertRule->type),
                'domain' => $this->event->domain->fqdn,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.alert-triggered',
        );
    }
}
