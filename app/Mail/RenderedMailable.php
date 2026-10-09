<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

/**
 * The one Mailable of the shop: it carries an already rendered, already sanitised message
 * (subject, HTML, plain text) and the resolved sender. All user-influenced text reached it through
 * TemplateRenderer and MailHeader; nothing is added here.
 */
final class RenderedMailable extends Mailable
{
    public function __construct(
        private readonly RenderedMail $rendered,
        private readonly MailerSettings $sender,
    ) {
    }

    public function build(): static
    {
        $this->from($this->sender->fromAddress, $this->sender->fromName)
            ->subject($this->rendered->subject)
            ->html($this->rendered->html)
            ->withSymfonyMessage(function (Email $message): void {
                $message->text($this->rendered->text);
                $message->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
            });

        if ($this->sender->replyTo !== null) {
            $this->replyTo($this->sender->replyTo);
        }

        return $this;
    }
}
