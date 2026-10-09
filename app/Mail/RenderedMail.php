<?php

namespace App\Mail;

/** A finished message: a header-safe subject, the final HTML document, and the derived plain-text part. */
final class RenderedMail
{
    public function __construct(
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
    ) {
    }
}
