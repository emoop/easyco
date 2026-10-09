<?php

namespace App\Mail;

/**
 * The merchant's own text for a template, if there is one (mail-design.md §5.3). In M1 there is no
 * `mail_templates` table and no editor, so this returns nothing and every mail uses the shipped default.
 * Stage M4 replaces the body of find() with a read of that table; the renderer already treats whatever
 * comes back as untrusted: it validates it, and falls back to the shipped default if it cannot be rendered.
 */
class TemplateOverrides
{
    /** @return array{subject: string, body: string}|null */
    public function find(string $key, string $locale): ?array
    {
        return null;
    }
}
