<?php

namespace App\Mail;

/** One registry entry: the key, its category and sender identity, its queue, and the CLOSED lists of variables it may use. */
final class MailTemplateDefinition
{
    /**
     * @param  list<string>  $scalars
     * @param  list<string>  $blocks
     */
    public function __construct(
        public readonly string $key,
        public readonly string $category,
        public readonly string $sender,
        public readonly string $queue,
        public readonly array $scalars,
        public readonly array $blocks,
    ) {
    }
}
